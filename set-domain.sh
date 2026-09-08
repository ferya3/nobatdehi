#!/usr/bin/env bash
#
# تغییر دامنه‌ی سامانه نوبت‌دهی بارگیری روی نصبی که از قبل کار می‌کند
#
#   BASE=https://raw.githubusercontent.com/ferya3/nobatdehi/claude/system-architecture-b9hlgv
#   curl -fsSL -o set-domain.sh "$BASE/set-domain.sh"
#   curl -fsSL -o set-domain.sh.sha256 "$BASE/set-domain.sh.sha256"
#   sha256sum -c set-domain.sh.sha256 && sudo bash set-domain.sh --domain sedo.site --email you@example.com
#
# چرا اسکریپت جدا و نه اجرای دوباره‌ی install.sh:
#
#   install.sh در پایان db:seed کامل می‌زند و FactorySeeder با updateOrCreate
#   ساعات کاری، ظرفیت، تعداد لاین و محصولات را به مقدار پیش‌فرض برمی‌گرداند.
#   یعنی هر چیزی که از پنل تنظیم کرده‌اید پاک می‌شود. این اسکریپت فقط دامنه
#   را عوض می‌کند و به داده دست نمی‌زند.
#
# و چرا فقط عوض‌کردن .env کافی نیست:
#
#   VITE_* در زمان build داخل جاوااسکریپت پخته می‌شوند. بدون npm run build،
#   مرورگر همچنان WebSocket را به میزبان قبلی می‌زند و صف زنده نمی‌ماند.

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/nobatdehi}"
APP_USER="${APP_USER:-nobatdehi}"
PHP_VERSION="${PHP_VERSION:-8.4}"
NGINX_SITE="${NGINX_SITE:-/etc/nginx/sites-available/nobatdehi}"

DOMAIN=""
TLS_EMAIL=""
ENABLE_TLS="auto"
CLOUDFLARE="auto"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)         DOMAIN="${2:-}"; shift 2 ;;
        --email)          TLS_EMAIL="${2:-}"; shift 2 ;;
        --no-tls)         ENABLE_TLS="no"; shift ;;
        --cloudflare)     CLOUDFLARE="yes"; shift ;;
        --no-cloudflare)  CLOUDFLARE="no"; shift ;;
        -h|--help)
            grep '^#' "$0" | head -9 | sed 's/^# \{0,1\}//'
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
[[ -n "$DOMAIN" ]] || die "دامنه را بدهید: sudo bash set-domain.sh --domain sedo.site"
[[ -d "$APP_DIR/.git" ]] || die "$APP_DIR یک نصب گیتیِ سامانه نیست. اول install.sh را اجرا کنید."
[[ -f "$APP_DIR/.env" ]] || die "$APP_DIR/.env پیدا نشد."
[[ -f "$NGINX_SITE" ]] || die "پیکربندی Nginx در $NGINX_SITE پیدا نشد."

# دامنه‌ی بی‌ریخت تا مرحله‌ی certbot لو نمی‌رود و آن‌وقت nginx هم خراب شده است
[[ "$DOMAIN" =~ ^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$ ]] \
    || die "دامنه معتبر نیست: $DOMAIN"

if [[ -x "/usr/bin/php${PHP_VERSION}" ]]; then
    PHP_BIN="/usr/bin/php${PHP_VERSION}"
else
    PHP_BIN="$(command -v php)" || die "PHP پیدا نشد."
fi

as_app() { sudo -u "$APP_USER" -H bash -lc "cd '$APP_DIR' && $1"; }
if [[ -d /run/systemd/system ]]; then SYSTEMD_OK="yes"; else SYSTEMD_OK="no"; fi
svc() { [[ "$SYSTEMD_OK" == "yes" ]] && systemctl "$@" >/dev/null 2>&1 || true; }

set_env() {
    local key="$1" value="$2" file="$APP_DIR/.env"
    python3 - "$file" "$key" "$value" <<'PY'
import sys, pathlib
path, key, value = sys.argv[1], sys.argv[2], sys.argv[3]
p = pathlib.Path(path)
out, written = [], False
for line in p.read_text().splitlines():
    if line.startswith(f'{key}='):
        if not written:
            out.append(f'{key}={value}')
            written = True
        continue
    out.append(line)
if not written:
    out.append(f'{key}={value}')
p.write_text('\n'.join(out) + '\n')
PY
}

# --------------------------------------------------------------- تشخیص DNS

step "بررسی DNS"

SERVER_IPS="$(hostname -I 2>/dev/null || true)"
RESOLVED="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ' || true)"

if [[ -z "$RESOLVED" ]]; then
    warn "$DOMAIN به هیچ IPv4 اشاره نمی‌کند. اگر تازه در Cloudflare ثبت کرده‌اید چند دقیقه صبر کنید."
else
    info "$DOMAIN → ${RESOLVED}"
    info "IP این سرور: ${SERVER_IPS:-نامشخص}"
fi

# Cloudflare با پروکسیِ روشن، IP خودش را برمی‌گرداند و نه IP سرور. این نه
# خطاست و نه چیزی که بشود نادیده گرفت: بدون اعتماد به پروکسیِ آن‌ها، همه‌ی
# درخواست‌ها از یک IP دیده می‌شوند و rate limit و لاگ امنیتی بی‌معنی می‌شوند.
CF_V4_URL="https://www.cloudflare.com/ips-v4"
CF_V6_URL="https://www.cloudflare.com/ips-v6"

# فهرست پشتیبان.
#
# اگر سرور به اینترنت آزاد نرسد یا Cloudflare جواب ندهد، بدون این فهرست
# TRUSTED_PROXIES روی لوپ‌بک می‌ماند و آن‌وقت همه‌ی درخواست‌ها از یک IP دیده
# می‌شوند — rate limit بی‌اثر و لاگ امنیتی بی‌معنی. فهرست زنده همیشه مرجع
# است؛ این فقط جلوی «بی‌صدا اشتباه ماندن» را می‌گیرد.
CF_FALLBACK_V4="173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22
141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20
197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13
104.24.0.0/14 172.64.0.0/13 131.0.72.0/22"

CF_FALLBACK_V6="2400:cb00::/32 2606:4700::/32 2803:f800::/32 2405:b500::/32
2405:8100::/32 2a06:98c0::/29 2c0f:f248::/32"

CF_SOURCE=""
CF_RANGES=""

# نتیجه در CF_RANGES می‌نشیند و نه روی stdout: با $(...) تابع داخل زیرپوسته
# اجرا می‌شد و CF_SOURCE هیچ‌وقت به بیرون نمی‌رسید — یعنی هشدارِ «از فهرست
# پشتیبان استفاده شد» هرگز دیده نمی‌شد.
load_cloudflare_ranges() {
    local v4 v6

    # یک بار بس است؛ هر دو جای صدا زدن از همین نتیجه استفاده می‌کنند
    if [[ -n "$CF_RANGES" ]]; then
        return 0
    fi

    v4="$(curl -fsSL --max-time 20 "$CF_V4_URL" 2>/dev/null || true)"
    v6="$(curl -fsSL --max-time 20 "$CF_V6_URL" 2>/dev/null || true)"

    if [[ -n "$v4" ]]; then
        CF_SOURCE="live"
    else
        CF_SOURCE="fallback"
        v4="$CF_FALLBACK_V4"
        v6="$CF_FALLBACK_V6"
    fi

    CF_RANGES="$(printf '%s\n%s\n' "$v4" "$v6" | tr ' ' '\n' \
        | grep -E '^[0-9a-fA-F:.]+/[0-9]+$' | sort -u | paste -sd, -)"

    [[ -n "$CF_RANGES" ]]
}

if [[ "$CLOUDFLARE" == "auto" ]]; then
    CLOUDFLARE="no"

    if [[ -n "$RESOLVED" ]] && load_cloudflare_ranges; then
        for ip in $RESOLVED; do
            if python3 - "$ip" "$CF_RANGES" <<'PY'
import ipaddress, sys
addr = ipaddress.ip_address(sys.argv[1])
for net in sys.argv[2].split(','):
    try:
        if addr in ipaddress.ip_network(net, strict=False):
            sys.exit(0)
    except ValueError:
        continue
sys.exit(1)
PY
            then
                CLOUDFLARE="yes"
                break
            fi
        done
    fi

    if [[ "$CLOUDFLARE" == "yes" ]]; then
        info "دامنه از پشت پروکسی Cloudflare می‌آید (ابر نارنجی)."
    fi
fi

# این هشدار باید *قبل* از certbot دیده شود و نه بعدش: روی حالت Flexible،
# ریدایرکتی که certbot می‌سازد یک حلقه‌ی بی‌پایان است و سایت از دسترس خارج
# می‌شود. بعد از آن، خواندن هشدار دیر است.
if [[ "$CLOUDFLARE" == "yes" && "$ENABLE_TLS" != "no" ]]; then
    warn "پیش از ادامه، در Cloudflare بخش SSL/TLS باید روی Full (strict) باشد."
    warn "روی Flexible، ریدایرکت HTTPS این سرور حلقه می‌سازد و سایت باز نمی‌شود."
    warn "اگر مطمئن نیستید: Ctrl+C، اول آن را درست کنید، بعد دوباره اجرا کنید."
    sleep 5
fi

# ---------------------------------------------------------------- Nginx

step "به‌روزرسانی Nginx"

cp "$NGINX_SITE" "${NGINX_SITE}.bak.$(date +%Y%m%d%H%M%S)"

# certbot ممکن است بلوک ۴۴۳ را جدا اضافه کرده باشد؛ همه‌ی server_nameها
# باید عوض شوند وگرنه HTTPS روی دامنه‌ی قبلی می‌ماند
python3 - "$NGINX_SITE" "$DOMAIN" <<'PY'
import re, sys, pathlib
path, domain = sys.argv[1], sys.argv[2]
p = pathlib.Path(path)
text = re.sub(r'(?m)^(\s*server_name\s+).*?;', rf'\g<1>{domain};', p.read_text())
p.write_text(text)
PY

nginx -t >/dev/null 2>&1 || die "پیکربندی Nginx معتبر نیست؛ نسخه‌ی پشتیبان کنار همان فایل است."
svc reload nginx
ok "server_name روی $DOMAIN تنظیم شد."

# ------------------------------------------------------------------ TLS

CERT_OK="no"

if [[ "$ENABLE_TLS" == "no" ]]; then
    info "TLS رد شد (--no-tls)."
else
    step "گواهی HTTPS"

    if ! command -v certbot >/dev/null 2>&1; then
        apt-get install -y -qq certbot python3-certbot-nginx >/dev/null
    fi

    CERTBOT_ARGS=(--nginx -d "$DOMAIN" --agree-tos --redirect --non-interactive)

    if [[ -n "$TLS_EMAIL" ]]; then
        CERTBOT_ARGS+=(-m "$TLS_EMAIL")
    else
        CERTBOT_ARGS+=(--register-unsafely-without-email)
    fi

    if certbot "${CERTBOT_ARGS[@]}"; then
        CERT_OK="yes"
        ok "گواهی نصب شد و تمدید خودکار فعال است."
    else
        warn "دریافت گواهی ناموفق بود."
        if [[ "$CLOUDFLARE" == "yes" ]]; then
            warn "با پروکسی روشن، ابر را موقتاً خاکستری کنید (DNS only)، این اسکریپت را دوباره بزنید،"
            warn "و بعد دوباره نارنجی‌اش کنید."
        else
            warn "بعد از درست‌شدن DNS اجرا کنید: sudo certbot --nginx -d ${DOMAIN}"
        fi
    fi
fi

# ------------------------------------------------------------------ .env

step "به‌روزرسانی .env"

if [[ "$CERT_OK" == "yes" || "$CLOUDFLARE" == "yes" ]]; then
    # Cloudflare حتی وقتی مبدأ HTTP است، به مرورگر HTTPS می‌دهد
    SCHEME="https"; PUBLIC_PORT="443"
else
    SCHEME="http"; PUBLIC_PORT="80"
fi

set_env APP_URL "${SCHEME}://${DOMAIN}"
set_env REVERB_SCHEME "$SCHEME"

# میزبان WebSocket دیگر داخل build پخته نمی‌شود؛ مرورگر آن را از آدرس خودِ
# صفحه می‌سازد. مقدارِ کهنه در این سه کلید همان چیزی بود که بعد از تغییر
# دامنه، اتصال زنده را بی‌صدا می‌کشت.
set_env VITE_REVERB_HOST ""
set_env VITE_REVERB_PORT ""
set_env VITE_REVERB_SCHEME ""

info "APP_URL = ${SCHEME}://${DOMAIN}"

if [[ "$CLOUDFLARE" == "yes" ]]; then
    if load_cloudflare_ranges; then
        set_env TRUSTED_PROXIES "127.0.0.1,::1,${CF_RANGES}"
        ok "رنج‌های Cloudflare به TRUSTED_PROXIES اضافه شد."
        info "بدون این، همه‌ی درخواست‌ها از یک IP دیده می‌شوند و rate limit بی‌اثر است."

        if [[ "$CF_SOURCE" == "fallback" ]]; then
            warn "فهرست زنده دانلود نشد و از فهرست پشتیبانِ داخل اسکریپت استفاده شد."
            warn "یک بار با ${CF_V4_URL} و ${CF_V6_URL} مقایسه‌اش کنید."
        fi
    else
        warn "هیچ فهرستی از IPهای Cloudflare به دست نیامد؛ TRUSTED_PROXIES دست‌نخورده ماند."
        warn "دستی اضافه کنید: ${CF_V4_URL} و ${CF_V6_URL}"
    fi
fi

chown "$APP_USER:$APP_USER" "$APP_DIR/.env"
chmod 640 "$APP_DIR/.env"

# ------------------------------------------------------- build و کش و سرویس

step "ساخت دوباره‌ی Frontend"
info "VITE_* در زمان build پخته می‌شوند؛ بدون این، مرورگر به میزبان قبلی وصل می‌ماند."
as_app "npm run build --silent"

step "بازسازی کش‌ها"
as_app "$PHP_BIN artisan config:clear >/dev/null"
as_app "$PHP_BIN artisan config:cache"
as_app "$PHP_BIN artisan route:cache"
as_app "$PHP_BIN artisan view:cache"

step "ری‌استارت سرویس‌ها"
# opcache با validate_timestamps=0 نصب شده: بدون ری‌استارت، PHP همان کد و
# همان کش قبلی را اجرا می‌کند
svc restart "php${PHP_VERSION}-fpm"
svc restart nobatdehi-reverb
svc restart nobatdehi-horizon
svc reload nginx
ok "سرویس‌ها ری‌استارت شدند."

# ---------------------------------------------------------------- بررسی

step "بررسی نهایی"

HEALTH="${SCHEME}://${DOMAIN}/up"

if curl -fsS --max-time 20 "$HEALTH" >/dev/null 2>&1; then
    ok "$HEALTH پاسخ داد."
else
    warn "$HEALTH پاسخ نداد. اگر Cloudflare تازه تنظیم شده چند دقیقه صبر کنید."
fi

cat <<EOF

${BOLD}${GREEN}دامنه عوض شد.${RESET}

  آدرس راننده     ${SCHEME}://${DOMAIN}/queue
  آدرس کارکنان    ${SCHEME}://${DOMAIN}/panel

EOF

if [[ "$CLOUDFLARE" == "yes" ]]; then
    cat <<EOF
  ${BOLD}در پنل Cloudflare بررسی کنید:${RESET}

  1) SSL/TLS → حالت رمزنگاری روی ${BOLD}Full (strict)${RESET} باشد.
     روی Flexible، ریدایرکت HTTPS این سرور یک حلقه‌ی بی‌پایان می‌سازد.

  2) Network → ${BOLD}WebSockets${RESET} روشن باشد، وگرنه صف زنده به‌روز نمی‌شود.

EOF
fi
