# سامانه نوبت‌دهی بارگیری کامیون

سامانه‌ی نوبت‌دهی، مدیریت صف و بارگیری کامیون‌های کارخانه.

راننده با شماره موبایل و کد یک‌بارمصرف وارد می‌شود، نوبت می‌گیرد و کد QR
دریافت می‌کند؛ نگهبانی ورود را ثبت می‌کند؛ اپراتور صف را به‌صورت زنده اداره
می‌کند؛ مدیر ظرفیت را تنظیم و گزارش می‌گیرد.

## مستندات

- [معماری سیستم](docs/ARCHITECTURE.md) — معماری کلی، ماژول‌ها، دیتابیس، امنیت، Deployment

## Stack

| بخش | تکنولوژی |
|---|---|
| Backend | Laravel 13 / PHP 8.4 |
| Database | PostgreSQL 16 |
| Cache / Session / Queue | Redis |
| Queue Monitor | Laravel Horizon |
| Realtime | Laravel Reverb (WebSocket) |
| Frontend | Vue 3 + TypeScript + Inertia.js + Tailwind 4 |
| Driver UI | PWA (RTL، فونت وزیرمتن) |
| Auth | OTP برای راننده، ایمیل/رمز برای کارکنان |
| SMS | شش پنل: عصر فرا ارتباط، کاوه‌نگار، SMS.ir، ملی‌پیامک، سفارشی، آزمایشی |
| تاریخ | شمسی (morilog/jalali) |

## نصب روی سرور (Ubuntu 24.04)

```bash
curl -fsSL https://raw.githubusercontent.com/ferya3/nobatdehi/claude/system-architecture-b9hlgv/install.sh \
  | sudo bash -s -- --domain factory.ir --email you@example.com
```

دامنه باید از قبل به IP سرور اشاره کند، وگرنه گرفتن گواهی HTTPS شکست می‌خورد
(بقیه‌ی نصب انجام می‌شود و بعداً می‌شود `sudo certbot --nginx -d factory.ir` زد).

اسکریپت PHP 8.4، PostgreSQL، Redis، Nginx و Node را نصب می‌کند، کد را می‌گیرد،
`.env` را با رمزهای تصادفی می‌سازد، مهاجرت‌ها را اجرا می‌کند، Frontend را build
می‌کند و سرویس‌های systemd (Reverb، Horizon، زمان‌بند) را بالا می‌آورد.
اجرای دوباره‌اش نصب را به‌روزرسانی می‌کند و رمزها و داده‌ها را دست نمی‌زند.

| گزینه | کار |
|---|---|
| `--domain factory.ir` | نام دامنه و فعال‌کردن HTTPS |
| `--email you@example.com` | ایمیل برای هشدارهای انقضای گواهی |
| `--no-tls` | بدون HTTPS (برای تست یا وقتی DNS آماده نیست) |
| `--branch main` | نصب از شاخه‌ی دیگر |
| `--demo` | ساخت صف نمونه برای امروز |
| `--skip-packages` | نصب بسته‌های سیستمی را رد کن (وقتی خودتان مدیریتشان می‌کنید) |

بعد از نصب حتماً رمز کاربران نمونه را عوض کنید و پنل پیامکی را از
`/panel/settings/sms` تنظیم کنید — تنظیمات پیامک در دیتابیس است، نه در `.env`.

## به‌روزرسانی سرور

```bash
curl -fsSL https://raw.githubusercontent.com/ferya3/nobatdehi/claude/system-architecture-b9hlgv/update.sh \
  | sudo bash
```

**`git pull` به‌تنهایی روی این سرور هیچ اثری ندارد.** سه چیز جلویش را می‌گیرند:

1. **opcache با `validate_timestamps = 0`** نصب شده (برای سرعت). PHP-FPM فایلِ
   عوض‌شده را اصلاً دوباره نمی‌خواند تا وقتی ری‌استارت شود — یعنی سرور همچنان
   کد قدیمی را اجرا می‌کند.
2. **`route:cache`** فعال است. مسیر جدید تا وقتی کش دوباره ساخته نشود وجود
   ندارد و صفحه‌ی تازه ۴۰۴ می‌دهد.
3. **دارایی‌های Vite هش‌دارند.** بدون `npm run build`، مرورگر همان جاوااسکریپت
   قبلی را می‌گیرد.

`update.sh` هر سه را انجام می‌دهد، مهاجرت‌ها و seedهای بی‌خطر را اجرا می‌کند،
سرویس‌ها را ری‌استارت می‌کند و آخرش بررسی می‌کند که واقعاً اعمال شده باشد.

| گزینه | کار |
|---|---|
| `--branch <name>` | به‌روزرسانی از شاخه‌ی دیگر |
| `--skip-build` | ساخت frontend را رد کن (وقتی فقط تغییر backend داده‌اید) |

یک قدم دستی می‌ماند: Service Worker پنل راننده فایل‌های `/build/` را کش می‌کند،
پس یک بار `Ctrl+Shift+R` در مرورگر لازم است.

## راه‌اندازی محلی

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

`.env` را برای PostgreSQL و Redis تنظیم کنید، سپس:

```bash
php artisan migrate --seed     # نقش‌ها، کارخانه‌ی نمونه، کاربران، قالب پیامک
php artisan slots:generate     # ساخت اسلات‌های ظرفیت افق نوبت‌دهی
php artisan db:seed --class=DemoQueueSeeder   # صف نمونه‌ی امروز (اختیاری)
```

چهار فرآیند برای اجرای کامل:

```bash
php artisan serve              # وب
php artisan reverb:start       # WebSocket
php artisan horizon            # کارگر صف (یا: php artisan queue:work)
npm run dev                    # Vite
```

### کاربران نمونه

رمز همه: `password`

| نقش | ایمیل |
|---|---|
| مدیر ارشد سامانه | admin@example.test |
| مدیر کارخانه | manager@example.test |
| اپراتور | operator@example.test |
| نگهبانی | gate@example.test |
| باسکول | scale@example.test |
| انبار | warehouse@example.test |
| مدیرعامل | ceo@example.test |

راننده رمز ندارد؛ در محیط توسعه با `OTP_EXPOSE_IN_RESPONSE=true` کد ورود
روی صفحه نمایش داده می‌شود.

### مسیرها

| مسیر | برای |
|---|---|
| `/queue` | پنل راننده (PWA) |
| `/panel` | پنل کارکنان — بر اساس نقش هدایت می‌شود |
| `/panel/queue` | صف اپراتور |
| `/panel/gate` | نگهبانی و ثبت ورود |
| `/panel/dashboard` | داشبورد مدیرعامل |
| `/panel/reports` | گزارش‌ها |
| `/panel/settings` | تنظیمات نوبت‌دهی |
| `/horizon` | پایش صف (فقط مدیر ارشد سامانه) |

## تست

```bash
./vendor/bin/phpunit       # تست‌ها روی PostgreSQL اجرا می‌شوند، نه SQLite
npm run typecheck
npm run build
```

تست‌ها به PostgreSQL نیاز دارند چون schema به CHECK constraint و jsonb تکیه
دارد. دیتابیس تست: `nobatdehi_test`.

`ConcurrentBookingTest` با `pcntl_fork` چند پروسه‌ی واقعی می‌سازد و بررسی
می‌کند که رزرو هم‌زمان از ظرفیت اسلات عبور نکند.

## کارهای زمان‌بندی‌شده

```bash
php artisan schedule:work
```

| فرمان | زمان | کار |
|---|---|---|
| `slots:generate` | ۰۰:۱۰ | جلو بردن اسلات‌های افق نوبت‌دهی |
| `appointments:expire` | ۰۰:۲۰ | بستن نوبت‌های سررسیدگذشته و آزادسازی ظرفیت |
| `appointments:no-show` | هر ۵ دقیقه | ثبت عدم حضور برای نوبت‌هایی که مهلت حضورشان گذشته |

## نکات پیاده‌سازی

**ظرفیت.** تخصیص بیش از ظرفیت با سه لایه جلوگیری می‌شود: قفل advisory روی
(کارخانه، روز)، قفل ردیف اسلات، و `CHECK (reserved_count <= capacity)` در
خود PostgreSQL. لایه‌ی سوم نباید هرگز فعال شود؛ اگر شد یعنی دو لایه‌ی قبل
دور زده شده‌اند.

**کاتالوگ.** محصولات از `/panel/products` و انواع کامیون از `/panel/truck-types`
مدیریت می‌شوند (دسترسی `products.manage`). هر نوع کامیون دو عدد زمانی دارد:
`loading_minutes` که تخمین انتظارِ صف را می‌سازد و `grace_minutes` که مهلت حضور
راننده است. خالی گذاشتنشان یعنی «از محصول/کارخانه بگیر». بعد از پایان مهلت،
`appointments:no-show` ظرفیت را همان روز آزاد می‌کند.

**وضعیت نوبت.** تنها راه تغییر `status`، اکشن `TransitionAppointment` است.
انتقال‌های مجاز و بازگردانی‌ها در `AppointmentStateMachine` تعریف شده‌اند و
بازگردانی دسترسی جداگانه می‌خواهد.

**QR.** محتوای QR توکن امضاشده با HMAC است، نه شناسه‌ی نوبت. دیتابیس فقط
hash آن را نگه می‌دارد و بعد از ثبت ورود باطل می‌شود.

**پیامک.** هرگز داخل Request اصلی ارسال نمی‌شود؛ فقط یک ردیف
`sms_messages` ساخته و روی صف گذاشته می‌شود.

**Realtime.** WebSocket مسیر اصلی به‌روزرسانی است و polling فقط پشتیبان؛
با برقراری اتصال زنده، فاصله‌ی polling بلند می‌شود.
