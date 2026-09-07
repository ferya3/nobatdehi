#!/usr/bin/env bash
#
# چک‌سام اسکریپت‌های نصب را از نو می‌سازد.
#
# بعد از هر تغییر در install.sh یا update.sh این را اجرا کنید، وگرنه
# دستور نصبِ مستند شکست می‌خورد — که بهتر از این است که بی‌صدا از یک
# فایل تأییدنشده رد شود.

set -Eeuo pipefail

cd "$(dirname "$0")/.."

for script in install.sh update.sh; do
    sha256sum "$script" > "${script}.sha256"
    echo "$(cat "${script}.sha256")"
done
