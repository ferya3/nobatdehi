#!/usr/bin/env bash
#
# نصب سامانه نوبت‌دهی بارگیری روی Ubuntu 24.04
#
#   curl -fsSL https://raw.githubusercontent.com/ferya3/nobatdehi/claude/system-architecture-b9hlgv/install.sh \
#     | sudo bash -s -- --domain factory.ir --email you@example.com
#
# اسکریپت idempotent است: اجرای دوباره، نصب را به‌روزرسانی می‌کند و
# رمزها و داده‌ها را دست نمی‌زند.

set -Eeuo pipefail

# ---------------------------------------------------------------- تنظیمات

REPO_URL="${REPO_URL:-https://github.com/ferya3/nobatdehi.git}"
REPO_BRANCH="${REPO_BRANCH:-claude/system-architecture-b9hlgv}"
APP_DIR="${APP_DIR:-/var/www/nobatdehi}"
APP_USER="${APP_USER:-nobatdehi}"
DB_NAME="${DB_NAME:-nobatdehi}"
DB_USER="${DB_USER:-nobatdehi}"
PHP_VERSION="${PHP_VERSION:-8.4}"
NODE_MAJOR="22"

DOMAIN=""
ENABLE_TLS="auto"
TLS_EMAIL=""
SEED_DEMO="no"
SKIP_PACKAGES="no"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)     DOMAIN="${2:-}"; shift 2 ;;
        --email)      TLS_EMAIL="${2:-}"; shift 2 ;;
        --branch)     REPO_BRANCH="${2:-}"; shift 2 ;;
        --no-tls)     ENABLE_TLS="no"; shift ;;
        --demo)       SEED_DEMO="yes"; shift ;;
        --skip-packages) SKIP_PACKAGES="yes"; shift ;;
        -h|--help)
            grep '^#' "$0" | head -8 | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) echo "گزینه‌ی ناشناخته: $1" >&2; exit 1 ;;
    esac
done

# ------------------------------------------------------------------ کمکی

BOLD=$'\e[1m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; RED=$'\e[31m'; RESET=$'\e[0m'

step()  { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
info()  { printf '    %s\n' "$1"; }
warn()  { printf '    %s%s%s\n' "$YELLOW" "$1" "$RESET"; }
die()   { printf '\n%sخطا: %s%s\n' "$RED" "$1" "$RESET" >&2; exit 1; }

# خطای هر خط را با شماره‌اش گزارش کن، نه یک «Aborted» خشک و خالی
trap 'die "اجرا در خط $LINENO متوقف شد (فرمان: $BASH_COMMAND)"' ERR

as_app() { sudo -u "$APP_USER" -H bash -lc "cd '$APP_DIR' && $1"; }

# HTTP/2 روی بلوکی که certbot ساخته.
#
# روی nginx ۱.۲۴ (همان چیزی که اوبونتو ۲۴.۰۴ دارد) دستور «http2 on;» اصلاً
# وجود ندارد و کل پیکربندی را می‌خواباند؛ تنها راه، افزودن به خط listen است.
# آن شکل روی نسخه‌های جدیدتر هم کار می‌کند، فقط هشدار deprecated می‌دهد.
enable_http2() {
    local site="/etc/nginx/sites-available/nobatdehi"
    local backup="${site}.pre-http2"

    [[ -f "$site" ]] || return 0

    cp "$site" "$backup"

    python3 - "$site" <<'HTTP2PY'
import re, sys, pathlib
p = pathlib.Path(sys.argv[1])
text = p.read_text()
p.write_text(re.sub(
    r'(?m)^(\s*listen\s+[^;\n]*\b443\s+ssl)(?![^;\n]*http2)([^;\n]*);',
    r'\1 http2\2;',
    text,
))
HTTP2PY

    if nginx -t >/dev/null 2>&1; then
        rm -f "$backup"
        svc reload nginx
        info "HTTP/2 فعال شد."
    else
        mv "$backup" "$site"
        warn "فعال‌کردن HTTP/2 پیکربندی را خراب کرد؛ به حالت قبل برگشت."
    fi
}

# روی سرور واقعی همیشه systemd است، ولی این اسکریپت ممکن است داخل کانتینر یا
# WSL هم اجرا شود. آنجا systemctl فقط «Failed to connect to bus» می‌دهد.
if [[ -d /run/systemd/system ]]; then
    SYSTEMD_OK="yes"
else
    SYSTEMD_OK="no"
fi

svc() {
    local action="$1"; shift

    if [[ "$SYSTEMD_OK" == "yes" ]]; then
        case "$action" in
            enable-now) systemctl enable --now "$@" >/dev/null ;;
            *)          systemctl "$action" "$@" ;;
        esac
        return
    fi

    local unit
    for unit in "$@"; do
        unit="${unit%.service}"
        case "$action" in
            enable-now|start) service "$unit" start >/dev/null 2>&1 || true ;;
            restart|reload)   service "$unit" restart >/dev/null 2>&1 || true ;;
        esac
    done
}

# مقدار یک کلید را در .env جایگزین یا اضافه می‌کند
set_env() {
    local key="$1" value="$2" file="$APP_DIR/.env"
    if grep -qE "^${key}=" "$file"; then
        # مقدار ممکن است / یا & داشته باشد، پس از python برای جایگزینی امن استفاده می‌کنیم
        python3 - "$file" "$key" "$value" <<'PY'
import sys, pathlib
path, key, value = sys.argv[1], sys.argv[2], sys.argv[3]
p = pathlib.Path(path)
out, written = [], False
for line in p.read_text().splitlines():
    if line.startswith(f'{key}='):
        # اولین تعریف را جایگزین می‌کنیم و بقیه‌ی تکراری‌ها را می‌اندازیم دور،
        # وگرنه .env با دو خط از یک کلید بیرون می‌آید
        if not written:
            out.append(f'{key}={value}')
            written = True
        continue
    out.append(line)
p.write_text('\n'.join(out) + '\n')
PY
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

random_secret() { head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32; }

# ------------------------------------------------------- بررسی پیش‌نیازها

[[ $EUID -eq 0 ]] || die "این اسکریپت باید با sudo اجرا شود."

if [[ -r /etc/os-release ]]; then
    . /etc/os-release
    [[ "${VERSION_ID:-}" == "24.04" ]] || warn "این اسکریپت برای Ubuntu 24.04 نوشته شده؛ نسخه‌ی شما ${VERSION_ID:-نامشخص} است."
fi

if [[ -z "$DOMAIN" ]]; then
    warn "دامنه داده نشد؛ سرویس روی IP سرور و بدون HTTPS بالا می‌آید."
    warn "برای HTTPS: sudo bash install.sh --domain factory.ir --email you@example.com"
    ENABLE_TLS="no"
fi

SERVER_NAME="${DOMAIN:-_}"

step "شروع نصب سامانه نوبت‌دهی بارگیری"
info "دامنه: ${DOMAIN:-«بدون دامنه»}"
info "مسیر نصب: $APP_DIR"
info "شاخه: $REPO_BRANCH"

# ------------------------------------------------------------- بسته‌ها

step "نصب بسته‌های سیستمی"

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

if [[ "$SKIP_PACKAGES" == "yes" ]]; then
    info "با --skip-packages نصب بسته‌ها رد شد؛ فرض بر این است که خودتان آن‌ها را مدیریت می‌کنید."
else
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl gnupg lsb-release software-properties-common unzip git acl >/dev/null

    step "افزودن مخزن PHP ${PHP_VERSION}"

    FPM_POLICY="$(apt-cache policy "php${PHP_VERSION}-fpm" 2>/dev/null || true)"

    if ! grep -qE 'Candidate: *[0-9]' <<<"$FPM_POLICY"; then
        add-apt-repository -y ppa:ondrej/php >/dev/null
        apt-get update -qq
    fi

    step "نصب PHP ${PHP_VERSION}، PostgreSQL، Redis و Nginx"

    # فهرست عمداً کوتاه است: فقط چیزی که وابستگی‌های پروژه واقعاً می‌خواهند.
    # pcntl و posix از php-cli می‌آیند و Horizon بدون آن‌ها کار نمی‌کند.
    apt-get install -y -qq \
        nginx \
        postgresql postgresql-contrib \
        redis-server \
        "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-redis" \
        "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
        "php${PHP_VERSION}-opcache" \
        >/dev/null

    step "نصب Node.js ${NODE_MAJOR}"

    if ! command -v node >/dev/null || [[ "$(node -v | cut -c2- | cut -d. -f1)" -lt "$NODE_MAJOR" ]]; then
        curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash - >/dev/null 2>&1
        apt-get install -y -qq nodejs >/dev/null
    fi

    step "نصب Composer"

    if ! command -v composer >/dev/null; then
        curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
        php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
        rm -f /tmp/composer-setup.php
    fi
fi

step "بررسی پیش‌نیازهای اجرا"

for binary in composer node npm nginx psql redis-cli; do
    command -v "$binary" >/dev/null || die "«${binary}» پیدا نشد. بدون --skip-packages اجرا کنید یا خودتان نصبش کنید."
done

# مسیر باینری را صریح می‌گیریم، نه از PATH.
#
# روی سروری که از قبل PHP دیگری دارد (پنل میزبانی، نصب دستی، نسخه‌ی قدیمی‌تر)،
# «php» ممکن است به همان اشاره کند و افزونه‌های php8.4-* اصلاً در آن نباشند —
# آن‌وقت بررسی زیر روی باینری اشتباه انجام می‌شود.
if [[ -x "/usr/bin/php${PHP_VERSION}" ]]; then
    PHP_BIN="/usr/bin/php${PHP_VERSION}"
elif command -v php >/dev/null; then
    PHP_BIN="$(command -v php)"
    warn "php${PHP_VERSION} در /usr/bin پیدا نشد؛ از ${PHP_BIN} استفاده می‌شود."
else
    die "PHP پیدا نشد. بدون --skip-packages اجرا کنید یا خودتان نصبش کنید."
fi

# nginx بدون FPM نمی‌تواند PHP را سرو کند؛ بهتر است همین‌جا بفهمیم تا وسط کار.
[[ -d "/etc/php/${PHP_VERSION}/fpm" ]] || die "php${PHP_VERSION}-fpm نصب نیست. بدون --skip-packages اجرا کنید، یا خودتان نصبش کنید."

INSTALLED_PHP="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
[[ "$INSTALLED_PHP" == "$PHP_VERSION" ]] || warn "PHP نصب‌شده ${INSTALLED_PHP} است، نه ${PHP_VERSION}."

# روی Debian/Ubuntu گاهی ماژول نصب می‌شود ولی برای یک SAPI فعال نمی‌شود.
# phpenmod این را idempotent درست می‌کند و اگر ماژول اصلاً نصب نباشد بی‌اثر است.
if command -v phpenmod >/dev/null; then
    for mod in pdo_pgsql pgsql redis mbstring dom simplexml curl zip opcache; do
        phpenmod -v "$PHP_VERSION" "$mod" 2>/dev/null || true
    done
fi

# این فهرست از ext-* های واقعی composer.lock درآمده، نه از عادت.
# نبودِ pcntl یا posix باعث می‌شود Horizon بی‌سروصدا کار نکند.
REQUIRED_EXTS="pdo_pgsql redis mbstring dom simplexml curl zip openssl tokenizer fileinfo pcntl posix"

# عمداً «php -m | grep» نیست.
#
# با set -o pipefail، grep -q به‌محض پیدا کردنِ تطابق خارج می‌شود، لوله بسته
# می‌شود، php سیگنال SIGPIPE می‌گیرد و با کد ناصفر تمام می‌کند — و pipefail کل
# لوله را ناموفق می‌شمارد. نتیجه: افزونه‌ی سالم «نصب‌نشده» گزارش می‌شود، آن هم
# به‌صورت اتفاقی و وابسته به سرعت ماشین. extension_loaded پاسخ قطعی می‌دهد و
# اصلاً لوله‌ای در کار نیست.
MISSING_EXTS=()

while IFS= read -r ext; do
    [[ -n "$ext" ]] && MISSING_EXTS+=("$ext")
done < <(REQUIRED_EXTS="$REQUIRED_EXTS" "$PHP_BIN" -r '
    foreach (preg_split("/\s+/", trim((string) getenv("REQUIRED_EXTS"))) as $ext) {
        if ($ext !== "" && ! extension_loaded($ext)) {
            echo $ext, PHP_EOL;
        }
    }
')

if [[ ${#MISSING_EXTS[@]} -gt 0 ]]; then
    # پیام خالی «افزونه نصب نیست» بن‌بست است؛ هرچه برای تشخیص لازم است چاپ می‌شود.
    printf '\n%sافزونه‌های PHP زیر بار نمی‌شوند: %s%s\n\n' "$RED" "${MISSING_EXTS[*]}" "$RESET" >&2
    printf '  باینری:      %s (%s)\n' "$PHP_BIN" "$("$PHP_BIN" -v | head -1)" >&2
    printf '  php روی PATH: %s\n' "$(command -v php || echo '—')" >&2
    printf '  مسیر ini:    %s\n' "$("$PHP_BIN" --ini | grep -i 'scan.*for additional' | cut -d: -f2- | xargs || echo '—')" >&2
    PHP_MODULES="$("$PHP_BIN" -m || true)"
    printf '\n  افزونه‌های بارشده:\n' >&2
    printf '%s\n' "$PHP_MODULES" | tr '\n' ' ' | fold -s -w 76 | sed 's/^/    /' >&2
    printf '\n  بسته‌های php%s نصب‌شده:\n' "$PHP_VERSION" >&2
    dpkg-query -W -f='    ${Package} ${Status}\n' "php${PHP_VERSION}-*" 2>/dev/null | grep 'install ok installed' | sed 's/ install ok installed//' >&2 || true
    FIX_PACKAGES=()
    for ext in "${MISSING_EXTS[@]}"; do
        case "$ext" in
            pdo_pgsql) FIX_PACKAGES+=("php${PHP_VERSION}-pgsql") ;;
            redis|mbstring|curl|zip) FIX_PACKAGES+=("php${PHP_VERSION}-${ext}") ;;
            dom|simplexml) FIX_PACKAGES+=("php${PHP_VERSION}-xml") ;;
            pcntl|posix|openssl|tokenizer|fileinfo) FIX_PACKAGES+=("php${PHP_VERSION}-cli") ;;
        esac
    done

    if [[ ${#FIX_PACKAGES[@]} -gt 0 ]]; then
        # تکراری‌ها را جمع می‌کنیم
        readarray -t FIX_PACKAGES < <(printf '%s\n' "${FIX_PACKAGES[@]}" | sort -u)
        printf '\n  رفع احتمالی:\n    sudo apt-get install --reinstall -y %s\n' "${FIX_PACKAGES[*]}" >&2
    fi
    printf '\n' >&2
    die "بدون این افزونه‌ها برنامه اجرا نمی‌شود."
fi

# ------------------------------------------------------------ کاربر و کد

step "آماده‌سازی کاربر و کد برنامه"

if ! id -u "$APP_USER" >/dev/null 2>&1; then
    adduser --system --group --home "$APP_DIR" --shell /bin/bash "$APP_USER" >/dev/null
    info "کاربر $APP_USER ساخته شد."
fi

mkdir -p "$APP_DIR"
chown -R "$APP_USER:$APP_USER" "$APP_DIR"

# گیت باید مخزن را با هر مالکی امن بداند، وگرنه دستورهای بعدی رد می‌شوند
git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true

if [[ -d "$APP_DIR/.git" ]]; then
    info "به‌روزرسانی کد موجود…"
    as_app "git fetch --depth 1 origin '$REPO_BRANCH' && git checkout -B '$REPO_BRANCH' 'origin/$REPO_BRANCH' && git reset --hard 'origin/$REPO_BRANCH'"
else
    info "دریافت کد…"
    sudo -u "$APP_USER" -H git clone --depth 1 --branch "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
fi

# ------------------------------------------------------------- دیتابیس

step "آماده‌سازی PostgreSQL"

svc enable-now postgresql

DB_PASSWORD_FILE="/etc/nobatdehi/db_password"
mkdir -p /etc/nobatdehi && chmod 700 /etc/nobatdehi

if [[ -f "$DB_PASSWORD_FILE" ]]; then
    DB_PASSWORD="$(cat "$DB_PASSWORD_FILE")"
    info "رمز دیتابیس موجود استفاده شد."
else
    DB_PASSWORD="$(random_secret)"
    printf '%s' "$DB_PASSWORD" > "$DB_PASSWORD_FILE"
    chmod 600 "$DB_PASSWORD_FILE"
fi

sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_USER}') THEN
        CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASSWORD}';
    ELSE
        ALTER ROLE ${DB_USER} WITH PASSWORD '${DB_PASSWORD}';
    END IF;
END
\$\$;
SQL

DB_EXISTS="$(sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" || true)"

if [[ "$(tr -d '[:space:]' <<<"$DB_EXISTS")" != "1" ]]; then
    sudo -u postgres createdb -O "$DB_USER" "$DB_NAME"
    info "دیتابیس ${DB_NAME} ساخته شد."
else
    # دیتابیسِ همنام از قبل هست. اگر مال کس دیگری باشد، مهاجرت‌ها بعداً با
    # «permission denied for table migrations» می‌شکنند — پیامی که هیچ نمی‌گوید
    # مشکل از مالکیت است. همین‌جا صریح شکست می‌خوریم.
    DB_OWNER="$(sudo -u postgres psql -tAc "SELECT pg_catalog.pg_get_userbyid(datdba) FROM pg_database WHERE datname='${DB_NAME}'")"

    if [[ "$DB_OWNER" != "$DB_USER" ]]; then
        die "دیتابیس «${DB_NAME}» از قبل وجود دارد و مالکش «${DB_OWNER}» است، نه «${DB_USER}».
    اگر این همان دیتابیس برنامه است:  sudo -u postgres psql -c 'ALTER DATABASE ${DB_NAME} OWNER TO ${DB_USER};'
    اگر دیتابیس دیگری است، با نام دیگری نصب کنید:  DB_NAME=nobatdehi2 sudo -E bash install.sh"
    fi

    info "دیتابیس ${DB_NAME} از قبل موجود بود."
fi

# در PostgreSQL 15 به بعد، PUBLIC دیگر روی schema public حق CREATE ندارد؛
# بدون این، مهاجرت روی دیتابیسی که با ابزار دیگری ساخته شده شکست می‌خورد.
sudo -u postgres psql -v ON_ERROR_STOP=1 -q -d "$DB_NAME" <<SQL
GRANT ALL ON SCHEMA public TO ${DB_USER};
ALTER SCHEMA public OWNER TO ${DB_USER};
SQL

step "آماده‌سازی Redis"

svc enable-now redis-server

# Redis بدون رمز روی لوپ‌بک هم یعنی هر فرایندی روی این ماشین می‌تواند
# session و کش را بخواند. رمز یک بار ساخته می‌شود و بعد دست نمی‌خورد.
REDIS_CONF="/etc/redis/redis.conf"
REDIS_PASS=""

if [[ -f "$REDIS_CONF" ]]; then
    REDIS_PASS="$(grep -oP '^\s*requirepass\s+\K\S+' "$REDIS_CONF" 2>/dev/null | tail -1 || true)"

    if [[ -z "$REDIS_PASS" ]]; then
        REDIS_PASS="$(openssl rand -hex 24)"

        # بایند هم به لوپ‌بک محدود می‌شود؛ نصب پیش‌فرض اوبونتو همین است
        # ولی روی سرورهایی که کسی دستکاری کرده باید مطمئن شویم.
        sed -i 's/^\s*#\?\s*requirepass .*/requirepass '"$REDIS_PASS"'/' "$REDIS_CONF"
        grep -q '^requirepass ' "$REDIS_CONF" || printf '\nrequirepass %s\n' "$REDIS_PASS" >> "$REDIS_CONF"

        sed -i 's/^\s*bind .*/bind 127.0.0.1 ::1/' "$REDIS_CONF"

        svc restart redis-server
        info "رمز Redis ساخته شد و فقط روی لوپ‌بک گوش می‌دهد."
    else
        info "Redis از قبل رمز داشت؛ دست نخورد."
    fi
fi

# ---------------------------------------------------------------- .env

step "تنظیم فایل .env"

if [[ ! -f "$APP_DIR/.env" ]]; then
    sudo -u "$APP_USER" cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    info ".env از روی .env.example ساخته شد."
fi

if [[ -n "$DOMAIN" ]]; then
    if [[ "$ENABLE_TLS" == "no" ]]; then
        APP_URL="http://${DOMAIN}"; REVERB_SCHEME="http"; REVERB_PUBLIC_PORT="80"
    else
        APP_URL="https://${DOMAIN}"; REVERB_SCHEME="https"; REVERB_PUBLIC_PORT="443"
    fi
    REVERB_PUBLIC_HOST="$DOMAIN"
else
    SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
    APP_URL="http://${SERVER_IP:-localhost}"
    REVERB_SCHEME="http"; REVERB_PUBLIC_PORT="80"; REVERB_PUBLIC_HOST="${SERVER_IP:-localhost}"
fi

set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "$APP_URL"
set_env APP_LOCALE fa
set_env APP_TIMEZONE Asia/Tehran

set_env DB_CONNECTION pgsql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 5432
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASSWORD"

set_env CACHE_STORE redis
set_env SESSION_DRIVER redis
set_env QUEUE_CONNECTION redis
set_env REDIS_CLIENT phpredis
if [[ -n "$REDIS_PASS" ]]; then
    set_env REDIS_PASSWORD "$REDIS_PASS"
fi

set_env BROADCAST_CONNECTION reverb

# نشست رمزنگاری‌شده و پروکسیِ محدود — پیش‌فرض‌های امنِ همین نصب
set_env SESSION_ENCRYPT true
set_env TRUSTED_PROXIES "127.0.0.1,::1"

# کلیدهای Reverb فقط یک‌بار ساخته می‌شوند؛ عوض‌شدنشان همه‌ی
# اتصال‌های باز را قطع می‌کند.
if ! grep -qE '^REVERB_APP_KEY=.+' "$APP_DIR/.env"; then
    set_env REVERB_APP_ID "$(shuf -i 100000-999999 -n 1)"
    set_env REVERB_APP_KEY "$(random_secret | tr 'A-Z' 'a-z')"
    set_env REVERB_APP_SECRET "$(random_secret | tr 'A-Z' 'a-z')"
fi

set_env REVERB_HOST 127.0.0.1
set_env REVERB_PORT 8080
set_env REVERB_SERVER_HOST 0.0.0.0
set_env REVERB_SERVER_PORT 8080
# Reverb از پشت Nginx سرو می‌شود؛ مستقیم روی شبکه گوش نمی‌دهد
set_env REVERB_SERVER_HOST 127.0.0.1

# مرورگر از پشت Nginx وصل می‌شود، پس میزبان و پورت عمومی فرق دارند
set_env VITE_REVERB_APP_KEY '${REVERB_APP_KEY}'
set_env VITE_REVERB_HOST "$REVERB_PUBLIC_HOST"
set_env VITE_REVERB_PORT "$REVERB_PUBLIC_PORT"
set_env VITE_REVERB_SCHEME "$REVERB_SCHEME"

set_env OTP_EXPOSE_IN_RESPONSE false

chown "$APP_USER:$APP_USER" "$APP_DIR/.env"
chmod 640 "$APP_DIR/.env"

# ------------------------------------------------------------- ساخت برنامه

step "نصب وابستگی‌های PHP"
as_app "COMPOSER_ALLOW_SUPERUSER=0 composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader --quiet"

if ! grep -qE '^APP_KEY=.+' "$APP_DIR/.env"; then
    as_app "$PHP_BIN artisan key:generate --force --quiet"
    info "APP_KEY ساخته شد."
fi

step "ساخت فایل‌های Frontend"
as_app "npm ci --no-fund --no-audit --silent"
as_app "npm run build --silent"

step "اجرای مهاجرت‌ها و داده‌های پایه"
as_app "$PHP_BIN artisan migrate --force --no-interaction"

# رمزهای اولیه تصادفی‌اند و seeder فقط یک بار چاپشان می‌کند. آن خروجی وسط
# صدها خط دیگر گم می‌شود و بعد راهی برای ورود به پنل نمی‌ماند — پس همان‌جا
# در فایلی که فقط root می‌خواندش نگه داشته می‌شود.
CREDENTIALS_FILE="/etc/nobatdehi/initial_passwords"

as_app "$PHP_BIN artisan db:seed --force --no-interaction" | tee /tmp/nobatdehi-seed.$$

if grep -qE '@example\.test[[:space:]]+\S' /tmp/nobatdehi-seed.$$; then
    {
        echo "# رمزهای اولیه‌ی پنل — ساخته‌شده در $(date -Is)"
        echo "# بعد از اولین ورود و تغییر رمز، این فایل را پاک کنید."
        grep -E '@example\.test[[:space:]]+\S' /tmp/nobatdehi-seed.$$ | sed 's/^[[:space:]]*//'
    } > "$CREDENTIALS_FILE"

    chmod 600 "$CREDENTIALS_FILE"
    ok "رمزهای اولیه در $CREDENTIALS_FILE ذخیره شد."
fi

rm -f /tmp/nobatdehi-seed.$$

as_app "$PHP_BIN artisan slots:generate"

if [[ "$SEED_DEMO" == "yes" ]]; then
    as_app "$PHP_BIN artisan db:seed --class=DemoQueueSeeder --force --no-interaction"
fi

step "بهینه‌سازی و دسترسی فایل‌ها"
[[ -e "$APP_DIR/public/storage" ]] || as_app "$PHP_BIN artisan storage:link"
as_app "$PHP_BIN artisan config:cache && $PHP_BIN artisan route:cache && $PHP_BIN artisan view:cache"

chown -R "$APP_USER:www-data" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
# فایل‌های جدیدی که php-fpm می‌سازد هم باید برای گروه نوشتنی بمانند
setfacl -R -d -m g:www-data:rwx "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true

# نگینکس باید بتواند public/ را بخواند
chmod o+x "$APP_DIR"

# ------------------------------------------------------------ سرویس‌ها

step "ساخت سرویس‌های systemd"

cat > /etc/systemd/system/nobatdehi-reverb.service <<UNIT
[Unit]
Description=Nobatdehi Reverb WebSocket server
After=network.target redis-server.service

[Service]
Type=simple
User=${APP_USER}
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} artisan reverb:start
Restart=always
RestartSec=3

NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full

[Install]
WantedBy=multi-user.target
UNIT

cat > /etc/systemd/system/nobatdehi-horizon.service <<UNIT
[Unit]
Description=Nobatdehi Horizon queue workers
After=network.target redis-server.service postgresql.service

[Service]
Type=simple
User=${APP_USER}
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} artisan horizon
# ترمینیت به Horizon می‌گوید کار جاری را تمام کند و بعد خارج شود
ExecStop=${PHP_BIN} artisan horizon:terminate
Restart=always
RestartSec=3

NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full

[Install]
WantedBy=multi-user.target
UNIT

cat > /etc/systemd/system/nobatdehi-scheduler.service <<UNIT
[Unit]
Description=Nobatdehi scheduler
After=network.target

[Service]
Type=oneshot
User=${APP_USER}
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} artisan schedule:run
UNIT

cat > /etc/systemd/system/nobatdehi-scheduler.timer <<UNIT
[Unit]
Description=Run the Nobatdehi scheduler every minute

[Timer]
OnCalendar=*:0/1
AccuracySec=10s
Persistent=true

[Install]
WantedBy=timers.target
UNIT

# در کانتینر یا WSL ممکن است systemd اصلاً در حال اجرا نباشد؛ فایل‌ها را
# می‌نویسیم ولی وانمود نمی‌کنیم که سرویس بالا آمده.
if [[ "$SYSTEMD_OK" == "yes" ]]; then
    systemctl daemon-reload
    systemctl enable --now nobatdehi-reverb.service nobatdehi-horizon.service nobatdehi-scheduler.timer >/dev/null
    systemctl restart nobatdehi-reverb.service nobatdehi-horizon.service
else
    warn "systemd در حال اجرا نیست؛ فایل‌های سرویس نوشته شدند ولی فعال نشدند."
    warn "روی یک سرور واقعی: systemctl enable --now nobatdehi-reverb nobatdehi-horizon nobatdehi-scheduler.timer"
fi

# --------------------------------------------------------------- Nginx

step "پیکربندی Nginx"

# روی میزبان‌های بدون IPv6 (بعضی VPSها و کانتینرها) دستور listen [::]
# کل nginx را از کار می‌اندازد، نه فقط این سایت را.
if [[ -f /proc/net/if_inet6 ]]; then
    LISTEN_V6="    listen [::]:80;"
else
    LISTEN_V6=""
    info "IPv6 روی این میزبان فعال نیست؛ فقط IPv4 پیکربندی شد."
fi

# فشرده‌سازی — در conf.d و نه داخل بلوک سایت، چون سطح http است و باید روی
# هر چیزی که Nginx سرو می‌کند اعمال شود.
#
# پیش‌فرض اوبونتو «gzip on» است ولی gzip_types کامنت شده، یعنی عملاً فقط
# text/html فشرده می‌شود و JS و CSS خام از سیم رد می‌شوند. روی همین پروژه
# اندازه‌گیری شد: app.js از ۲۰۱ به ۶۸ کیلوبایت و app.css از ۶۱ به ۱۱.
cat > /etc/nginx/conf.d/nobatdehi-performance.conf <<'NGINXPERF'
# «gzip on;» عمداً اینجا نیست.
#
# اوبونتو خودش در nginx.conf روشنش کرده و تکرارش خطای «duplicate directive»
# می‌دهد که کل Nginx را می‌خواباند — نه اینکه مقدار قبلی را عوض کند. آنچه
# کم بود gzip_types بود که آنجا کامنت مانده و بدون آن فقط text/html فشرده
# می‌شود.
gzip_vary on;
gzip_proxied any;
gzip_comp_level 5;
gzip_min_length 512;

# woff2 عمداً در فهرست نیست: خودش از قبل فشرده است و gzip فقط CPU می‌سوزاند
# بدون اینکه حتی یک بایت کم کند.
gzip_types
    text/plain
    text/css
    text/xml
    text/javascript
    application/javascript
    application/json
    application/manifest+json
    application/xml
    application/rss+xml
    image/svg+xml;
NGINXPERF

cat > /etc/nginx/sites-available/nobatdehi <<NGINX
server {
    listen 80;
${LISTEN_V6}
    server_name ${SERVER_NAME};
    root ${APP_DIR}/public;

    index index.php;
    charset utf-8;

    client_max_body_size 20m;

    # هدرهای امنیتی از PHP می‌آیند (App\Http\Middleware\SecurityHeaders).
    #
    # تکرارشان اینجا باعث می‌شد مرورگر دو X-Frame-Options متناقض بگیرد —
    # DENY از Laravel و SAMEORIGIN از اینجا — و آن‌که سست‌تر است برنده شود.
    # یک منبع، آن هم جایی که تست دارد.

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    # Service Worker باید از ریشه سرو شود و کش نشود
    location = /sw.js {
        add_header Cache-Control "no-cache, must-revalidate";
        try_files \$uri =404;
    }

    # دارایی‌های ساخت، نامشان hash دارد پس کش طولانی امن است
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }

    # WebSocket و API ناقل Reverb
    location ~ ^/(app|apps)(/|$) {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/nobatdehi /etc/nginx/sites-enabled/nobatdehi
rm -f /etc/nginx/sites-enabled/default

NGINX_TEST="$(nginx -t 2>&1 || true)"
grep -q "test is successful" <<<"$NGINX_TEST" || die "پیکربندی Nginx معتبر نیست:
${NGINX_TEST}"

svc enable-now nginx
svc reload nginx

step "پیکربندی PHP-FPM"

PHP_INI="/etc/php/${PHP_VERSION}/fpm/conf.d/99-nobatdehi.ini"
cat > "$PHP_INI" <<INI
; تنظیمات production
expose_php = Off
memory_limit = 512M
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 60
date.timezone = Asia/Tehran

opcache.enable = 1
opcache.memory_consumption = 192
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
INI

svc restart "php${PHP_VERSION}-fpm"

# ----------------------------------------------------------------- TLS

if [[ "$ENABLE_TLS" != "no" && -n "$DOMAIN" ]]; then
    step "دریافت گواهی HTTPS"

    apt-get install -y -qq certbot python3-certbot-nginx >/dev/null

    CERTBOT_ARGS=(--nginx -d "$DOMAIN" --agree-tos --redirect --non-interactive)

    if [[ -n "$TLS_EMAIL" ]]; then
        CERTBOT_ARGS+=(-m "$TLS_EMAIL")
    else
        CERTBOT_ARGS+=(--register-unsafely-without-email)
    fi

    if certbot "${CERTBOT_ARGS[@]}"; then
        info "گواهی نصب شد و تمدید خودکار فعال است."
        enable_http2
    else
        warn "دریافت گواهی ناموفق بود (احتمالاً DNS هنوز به این سرور اشاره نمی‌کند)."
        warn "بعد از درست‌شدن DNS اجرا کنید: sudo certbot --nginx -d ${DOMAIN}"
        # بدون HTTPS، مرورگر WebSocket امن را رد می‌کند؛ .env باید صادق بماند
        set_env VITE_REVERB_SCHEME http
        set_env VITE_REVERB_PORT 80
        set_env APP_URL "http://${DOMAIN}"
        as_app "npm run build --silent"
        as_app "$PHP_BIN artisan config:cache"
    fi
fi

# --------------------------------------------------------------- فایروال

if command -v ufw >/dev/null 2>&1; then
    step "تنظیم فایروال"

    UFW_STATUS="$(ufw status 2>/dev/null || true)"

    ufw allow OpenSSH >/dev/null 2>&1 || true
    ufw allow 'Nginx Full' >/dev/null 2>&1 || true

    if grep -q "Status: active" <<<"$UFW_STATUS"; then
        info "فایروال از قبل فعال بود؛ قوانین به‌روز شد."
    else
        # فایروال خاموش روی سرور تازه یعنی هر پورتی که سهواً باز بماند از
        # اینترنت در دسترس است — از جمله ۵۴۳۲ و ۶۳۷۹ و ۸۰۸۰.
        # SSH قبل از فعال‌سازی باز شده تا ارتباط قطع نشود.
        ufw --force enable >/dev/null 2>&1 || warn "فعال‌کردن فایروال ممکن نشد."
        info "فایروال فعال شد: فقط SSH و ۸۰/۴۴۳ باز است."
    fi

    info "۸۰۸۰ (Reverb)، ۵۴۳۲ (PostgreSQL) و ۶۳۷۹ (Redis) بسته می‌مانند؛ همه از لوپ‌بک سرو می‌شوند."
fi

# ----------------------------------------------------------------- پایان

step "بررسی نهایی"

sleep 2
HEALTH_URL="${APP_URL}/up"
if curl -fsS --max-time 10 -o /dev/null "$HEALTH_URL" 2>/dev/null; then
    info "${GREEN}برنامه پاسخ می‌دهد.${RESET}"
else
    warn "پاسخ سلامت از ${HEALTH_URL} گرفته نشد؛ لاگ‌ها را ببینید."
fi

if [[ "$SYSTEMD_OK" == "yes" ]]; then
    for unit in nobatdehi-reverb nobatdehi-horizon; do
        if systemctl is-active --quiet "$unit"; then
            info "${GREEN}${unit}: در حال اجرا${RESET}"
        else
            warn "${unit}: اجرا نشد — journalctl -u ${unit} -n 50"
        fi
    done
fi

cat <<SUMMARY

${BOLD}${GREEN}نصب تمام شد.${RESET}

  آدرس راننده     ${APP_URL}/queue
  آدرس کارکنان    ${APP_URL}/panel

  ${BOLD}کاربران پیش‌فرض${RESET}
  admin@example.test      مدیر ارشد سامانه
  manager@example.test    مدیر کارخانه
  operator@example.test   اپراتور
  gate@example.test       نگهبانی
  ceo@example.test        مدیرعامل

  رمز هر کدام هنگام نصب به‌طور تصادفی ساخته شد:
  ${BOLD}sudo cat ${CREDENTIALS_FILE:-/etc/nobatdehi/initial_passwords}${RESET}

  در اولین ورود، تغییر رمز اجباری است. بعد از آن آن فایل را پاک کنید:
  sudo rm ${CREDENTIALS_FILE:-/etc/nobatdehi/initial_passwords}

  ${YELLOW}اگر رمزی گم شد، از روی سرور رمز تازه بسازید:${RESET}
  sudo -u ${APP_USER} ${PHP_BIN} ${APP_DIR}/artisan user:password admin@example.test

  ${BOLD}قدم‌های بعدی${RESET}
  1) پنل پیامکی را از خودِ سامانه تنظیم کنید:
     ${APP_URL}/panel/settings/sms
     (نام کاربری، رمز و شماره اختصاصی پنل + شماره مدیران)
     همان‌جا دکمه‌ی «ارسال آزمایشی» و «عیب‌یابی هوشمند» هم هست.
     تا وقتی تنظیم نشده، پیامکی ارسال نمی‌شود.
  2) بعد از هر تغییر .env:  sudo -u ${APP_USER} ${PHP_BIN} ${APP_DIR}/artisan config:cache
  3) پشتیبان‌گیری دیتابیس را تنظیم کنید — رمز دیتابیس در ${DB_PASSWORD_FILE}

  ${BOLD}سرویس‌ها${RESET}
  systemctl status nobatdehi-reverb nobatdehi-horizon
  journalctl -u nobatdehi-horizon -f

SUMMARY
