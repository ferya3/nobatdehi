#!/usr/bin/env bash
#
# به‌روزرسانی سامانه نوبت‌دهی بارگیری روی سروری که قبلاً install.sh نصبش کرده
#
#   curl -fsSL https://raw.githubusercontent.com/ferya3/nobatdehi/claude/system-architecture-b9hlgv/update.sh | sudo bash
#
# چرا این اسکریپت لازم است و «git pull» کافی نیست:
#
#   1. opcache با validate_timestamps=0 نصب شده — PHP-FPM فایل عوض‌شده را
#      دوباره نمی‌خواند تا وقتی ری‌استارت شود. یعنی بعد از pull، سرور هنوز
#      کد قدیمی را اجرا می‌کند و «هیچ اتفاقی نمی‌افتد».
#   2. route:cache یعنی مسیرهای جدید تا وقتی کش دوباره ساخته نشود وجود ندارند.
#   3. دارایی‌های Vite هش‌دار هستند و بدون npm run build، مرورگر همان
#      جاوااسکریپت قبلی را می‌گیرد.

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/nobatdehi}"
APP_USER="${APP_USER:-nobatdehi}"
PHP_VERSION="${PHP_VERSION:-8.4}"
REPO_BRANCH="${REPO_BRANCH:-claude/system-architecture-b9hlgv}"

SKIP_BUILD="no"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --branch)     REPO_BRANCH="${2:-}"; shift 2 ;;
        --skip-build) SKIP_BUILD="yes"; shift ;;
        -h|--help)
            grep '^#' "$0" | head -6 | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) echo "گزینه‌ی ناشناخته: $1" >&2; exit 1 ;;
    esac
done

BOLD=$'\e[1m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; RED=$'\e[31m'; RESET=$'\e[0m'

step()  { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
info()  { printf '    %s\n' "$1"; }
warn()  { printf '    %s%s%s\n' "$YELLOW" "$1" "$RESET"; }
ok()    { printf '    %s%s%s\n' "$GREEN" "$1" "$RESET"; }
die()   { printf '\n%sخطا: %s%s\n' "$RED" "$1" "$RESET" >&2; exit 1; }

trap 'die "اجرا در خط $LINENO متوقف شد (فرمان: $BASH_COMMAND)"' ERR

[[ $EUID -eq 0 ]] || die "این اسکریپت را با sudo اجرا کنید."
[[ -d "$APP_DIR/.git" ]] || die "$APP_DIR یک نصب گیتیِ سامانه نیست. اول install.sh را اجرا کنید."

if [[ -x "/usr/bin/php${PHP_VERSION}" ]]; then
    PHP_BIN="/usr/bin/php${PHP_VERSION}"
else
    PHP_BIN="$(command -v php)" || die "PHP پیدا نشد."
fi

as_app() { sudo -u "$APP_USER" -H bash -lc "cd '$APP_DIR' && $1"; }

if [[ -d /run/systemd/system ]]; then SYSTEMD_OK="yes"; else SYSTEMD_OK="no"; fi

svc() {
    local action="$1"; shift

    if [[ "$SYSTEMD_OK" == "yes" ]]; then
        systemctl "$action" "$@" 2>/dev/null || true
        return
    fi

    local unit
    for unit in "$@"; do
        service "${unit%.service}" "$action" >/dev/null 2>&1 || true
    done
}

# ------------------------------------------------------------------ کد

step "گرفتن آخرین کد از شاخه‌ی $REPO_BRANCH"

BEFORE="$(as_app 'git rev-parse HEAD')"

# اگر کسی روی سرور فایلی را دستی عوض کرده باشد، pull وسط کار می‌ایستد.
if ! as_app 'git diff --quiet && git diff --cached --quiet'; then
    warn "روی سرور تغییرات ثبت‌نشده وجود دارد:"
    as_app 'git status --short' | sed 's/^/      /'
    die "اول آن‌ها را commit یا «git checkout -- .» کنید، بعد دوباره اجرا کنید."
fi

as_app "git fetch --quiet origin '$REPO_BRANCH'"
as_app "git checkout --quiet -B '$REPO_BRANCH' 'origin/$REPO_BRANCH'"

AFTER="$(as_app 'git rev-parse HEAD')"

if [[ "$BEFORE" == "$AFTER" ]]; then
    info "کد از قبل به‌روز بود ($(as_app 'git rev-parse --short HEAD'))."
    info "با این حال کش‌ها و سرویس‌ها بازسازی می‌شوند — همان چیزی که معمولاً جا می‌ماند."
else
    ok "کد به‌روز شد: ${BEFORE:0:7} → ${AFTER:0:7}"
    as_app "git --no-pager log --oneline '${BEFORE}..${AFTER}'" | sed 's/^/      /'
fi

# ------------------------------------------------------------ وابستگی‌ها

step "نصب وابستگی‌های PHP"
as_app "COMPOSER_ALLOW_SUPERUSER=0 composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader --quiet"

if [[ "$SKIP_BUILD" == "yes" ]]; then
    warn "ساخت دارایی‌های frontend رد شد (--skip-build)."
else
    step "ساخت دارایی‌های frontend"
    # npm ci به package-lock.json وفادار است؛ install می‌تواند نسخه بالا ببرد
    as_app "npm ci --silent"
    as_app "npm run build --silent"
    ok "فایل‌های public/build بازسازی شدند."
fi

# --------------------------------------------------------------- دیتابیس

step "اجرای مهاجرت‌های دیتابیس"

as_app "$PHP_BIN artisan migrate --force --no-interaction"

# داده‌های پایه (نقش‌ها، دسترسی‌ها، قالب‌های پیامک) با updateOrCreate نوشته
# می‌شوند، پس اجرای دوباره چیزی را خراب نمی‌کند و دسترسی‌های تازه را اضافه می‌کند.
as_app "$PHP_BIN artisan db:seed --class=RoleSeeder --force --no-interaction"
as_app "$PHP_BIN artisan db:seed --class=SmsTemplateSeeder --force --no-interaction"

as_app "$PHP_BIN artisan slots:generate"

# ------------------------------------------------------------------ کش

step "بازسازی کش‌های Laravel"

# ترتیب مهم است: اول پاک، بعد ساخت. route:cache قدیمی یعنی مسیرهای تازه
# اصلاً وجود ندارند و صفحه‌ی جدید 404 می‌دهد.
as_app "$PHP_BIN artisan config:clear"
as_app "$PHP_BIN artisan route:clear"
as_app "$PHP_BIN artisan view:clear"
as_app "$PHP_BIN artisan cache:clear"

as_app "$PHP_BIN artisan config:cache"
as_app "$PHP_BIN artisan route:cache"
as_app "$PHP_BIN artisan view:cache"

chown -R "$APP_USER:www-data" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

# -------------------------------------------------------------- سرویس‌ها

step "ری‌استارت سرویس‌ها"

# این تنها کاری است که opcache را وادار می‌کند کد جدید را بخواند.
# بدون آن، هر کار دیگری در این اسکریپت بی‌اثر است.
svc restart "php${PHP_VERSION}-fpm"
info "php${PHP_VERSION}-fpm ری‌استارت شد (پاک‌سازی opcache)"

svc restart nobatdehi-horizon.service
svc restart nobatdehi-reverb.service
info "horizon و reverb ری‌استارت شدند"

svc reload nginx

# ------------------------------------------------------------ بررسی نهایی

step "بررسی نهایی"

FAILED=0

check() {
    local label="$1" cmd="$2"

    if as_app "$cmd" >/dev/null 2>&1; then
        ok "✓ $label"
    else
        warn "✗ $label"
        FAILED=1
    fi
}

# عمداً «... | grep -q» نیست: با pipefail، بسته‌شدن زودهنگام لوله توسط grep
# به دستور سمت چپ SIGPIPE می‌دهد و یک شکستِ دروغین می‌سازد.
check_output() {
    local label="$1" cmd="$2" needle="$3"

    if [[ "$(as_app "$cmd" 2>/dev/null || true)" == *"$needle"* ]]; then
        ok "✓ $label"
    else
        warn "✗ $label"
        FAILED=1
    fi
}

check_output "مسیر مدیریت محصولات ثبت شده" \
    "$PHP_BIN artisan route:list --name=staff.products.index" "panel/products"

check_output "مسیر انواع کامیون ثبت شده" \
    "$PHP_BIN artisan route:list --name=staff.truck-types.index" "panel/truck-types"

check "فرمان عدم حضور خودکار موجود است" "$PHP_BIN artisan appointments:no-show --dry-run"

# tinker همیشه با کد خروج ۱ برمی‌گردد، پس به خروجی متنی‌اش نگاه می‌کنیم
check_output "ستون لغوکننده در دیتابیس هست" \
    "$PHP_BIN artisan tinker --execute='echo Schema::hasColumn(\"appointments\",\"cancelled_by_type\") ? \"HAS-IT\" : \"MISSING\";'" \
    "HAS-IT"

check_output "ستون‌های زمانی نوع کامیون هست" \
    "$PHP_BIN artisan tinker --execute='echo Schema::hasColumn(\"truck_types\",\"grace_minutes\") ? \"HAS-IT\" : \"MISSING\";'" \
    "HAS-IT"

ASSET_AGE="$(( $(date +%s) - $(stat -c %Y "$APP_DIR/public/build/manifest.json" 2>/dev/null || echo 0) ))"

if [[ "$SKIP_BUILD" == "no" && "$ASSET_AGE" -gt 600 ]]; then
    warn "✗ public/build تازه ساخته نشده — خروجی npm run build را بررسی کنید."
    FAILED=1
elif [[ "$SKIP_BUILD" == "no" ]]; then
    ok "✓ دارایی‌های frontend تازه‌اند"
fi

printf '\n'

if [[ "$FAILED" -eq 0 ]]; then
    printf '%sبه‌روزرسانی کامل شد — نسخه‌ی %s%s\n' "$GREEN$BOLD" "$(as_app 'git rev-parse --short HEAD')" "$RESET"
else
    warn "بعضی بررسی‌ها رد شدند؛ خروجی بالا را بفرستید."
fi

cat <<'NOTE'

    یک قدم در مرورگر باقی است:

    Service Worker پنل راننده، فایل‌های /build/ را کش می‌کند. یک بار
    Ctrl+Shift+R (روی موبایل: بستن و باز کردن دوباره‌ی صفحه) لازم است تا
    نسخه‌ی تازه برداشته شود.

    صفحه‌های جدید:  /panel/products   و   /panel/truck-types
    (با کاربری که دسترسی «مدیریت محصولات» دارد — مثلاً مدیر کارخانه)
NOTE
