#!/usr/bin/env bash
#
# ساخت APK برنامه‌ی راننده
#
#   cd android && ./build-apk.sh
#
# نیازها: Java 17+ و Android SDK. اگر SDK نباشد، خودش دانلود می‌کند.

set -Eeuo pipefail

cd "$(dirname "$0")"

BOLD=$'\e[1m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; RED=$'\e[31m'; RESET=$'\e[0m'

step() { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
info() { printf '    %s\n' "$1"; }
warn() { printf '    %s%s%s\n' "$YELLOW" "$1" "$RESET"; }
ok()   { printf '    %s%s%s\n' "$GREEN" "$1" "$RESET"; }
die()  { printf '\n%sخطا: %s%s\n' "$RED" "$1" "$RESET" >&2; exit 1; }

command -v java >/dev/null 2>&1 || die "Java نصب نیست. روی اوبونتو: sudo apt install -y openjdk-17-jdk"

# ----------------------------------------------------------- Android SDK

export ANDROID_HOME="${ANDROID_HOME:-$HOME/Android/sdk}"

if [[ ! -d "$ANDROID_HOME/cmdline-tools/latest" ]]; then
    step "دانلود ابزارهای Android SDK"
    info "یک بار انجام می‌شود و حدود ۱۵۰ مگابایت است."

    TOOLS_ZIP="$(mktemp -d)/tools.zip"
    curl -fsSL -o "$TOOLS_ZIP" \
        "https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip" \
        || die "دانلود نشد. اگر داخل ایران هستید، این آدرس معمولاً مسدود است — با تحریم‌شکن دوباره اجرا کنید."

    mkdir -p "$ANDROID_HOME/cmdline-tools"
    unzip -q "$TOOLS_ZIP" -d "$ANDROID_HOME/cmdline-tools"
    mv "$ANDROID_HOME/cmdline-tools/cmdline-tools" "$ANDROID_HOME/cmdline-tools/latest"
fi

export PATH="$ANDROID_HOME/cmdline-tools/latest/bin:$ANDROID_HOME/platform-tools:$PATH"

step "آماده‌سازی SDK"
yes | sdkmanager --licenses >/dev/null 2>&1 || true
sdkmanager --install "platforms;android-35" "build-tools;35.0.0" "platform-tools" >/dev/null
ok "SDK آماده است."

# --------------------------------------------------------------- امضا

if [[ ! -f keystore.properties ]]; then
    warn "keystore.properties نیست — خروجی debug ساخته می‌شود."
    warn "برای نسخه‌ی قابل انتشار، اول کلید بسازید:"
    warn ""
    warn "  keytool -genkeypair -v -keystore nobat.keystore \\"
    warn "    -alias nobat -keyalg RSA -keysize 2048 -validity 10000"
    warn ""
    warn "  cat > keystore.properties <<EOF"
    warn "  storeFile=nobat.keystore"
    warn "  storePassword=<رمز>"
    warn "  keyAlias=nobat"
    warn "  keyPassword=<رمز>"
    warn "  EOF"
    warn ""

    TASK="assembleDebug"
    OUT="app/build/outputs/apk/debug/app-debug.apk"
else
    TASK="assembleRelease"
    OUT="app/build/outputs/apk/release/app-release.apk"
fi

step "ساخت APK"
info "آدرس سامانه: $(grep '^NOBAT_URL=' gradle.properties | cut -d= -f2)"

if [[ -x ./gradlew ]]; then
    ./gradlew "$TASK"
else
    command -v gradle >/dev/null 2>&1 || die "نه gradlew هست نه gradle. یکی را نصب کنید."
    gradle "$TASK"
fi

[[ -f "$OUT" ]] || die "APK ساخته نشد."

cp "$OUT" ./nobatdehi-driver.apk

step "تمام شد"
ok "فایل: $(pwd)/nobatdehi-driver.apk  ($(du -h nobatdehi-driver.apk | cut -f1))"

if [[ -f keystore.properties ]]; then
    printf '\n%sاثر انگشت کلید — این را در .env سرور بگذارید:%s\n' "$BOLD" "$RESET"

    STORE="$(grep '^storeFile=' keystore.properties | cut -d= -f2-)"
    ALIAS="$(grep '^keyAlias=' keystore.properties | cut -d= -f2-)"
    PASS="$(grep '^storePassword=' keystore.properties | cut -d= -f2-)"

    FP="$(keytool -list -v -keystore "$STORE" -alias "$ALIAS" -storepass "$PASS" 2>/dev/null \
        | grep -i 'SHA256:' | head -1 | sed 's/.*SHA256: *//')"

    printf '\n    ANDROID_FINGERPRINTS=%s\n\n' "$FP"
    info "بعد از گذاشتنش در .env سرور: php artisan config:cache"
    info "آن‌وقت لینکِ پیامک مستقیم داخل برنامه باز می‌شود."
fi
