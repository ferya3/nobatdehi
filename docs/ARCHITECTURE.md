# معماری سیستم نوبت‌دهی بارگیری کامیون

سند مرجع معماری برای سامانه‌ی نوبت‌دهی و مدیریت صف بارگیری کارخانه.

- وضعیت: پیش‌نویس ۱ (قبل از شروع کدنویسی)
- دامنه: پنل راننده، پنل اپراتور/نگهبانی/باسکول، پنل مدیریت و داشبورد مدیرعامل
- رویکرد: Monolithic Modular Laravel (نه Microservice)

---

## فهرست

1. [معماری کلی سیستم](#1-معماری-کلی-سیستم)
2. [بخش‌های اصلی سیستم](#2-بخشهای-اصلی-سیستم)
3. [پنل و فرآیند راننده](#3-پنل-و-فرآیند-راننده)
4. [ظرفیت و صدور نوبت](#4-ظرفیت-و-صدور-نوبت)
5. [چرخه وضعیت نوبت (State Machine)](#5-چرخه-وضعیت-نوبت-state-machine)
6. [پنل اپراتور و Real-Time](#6-پنل-اپراتور-و-real-time)
7. [ورود کامیون، QR و Check-in](#7-ورود-کامیون-qr-و-check-in)
8. [پنل مدیریت و داشبورد مدیرعامل](#8-پنل-مدیریت-و-داشبورد-مدیرعامل)
9. [نقش‌ها و دسترسی (RBAC)](#9-نقشها-و-دسترسی-rbac)
10. [معماری Backend و ساختار پروژه](#10-معماری-backend-و-ساختار-پروژه)
11. [Frontend](#11-frontend)
12. [ساختار دیتابیس](#12-ساختار-دیتابیس)
13. [همزمانی، Race Condition و Idempotency](#13-همزمانی-race-condition-و-idempotency)
14. [احراز هویت و امنیت API](#14-احراز-هویت-و-امنیت-api)
15. [Notification و SMS](#15-notification-و-sms)
16. [Event-Driven](#16-event-driven)
17. [فهرست APIها](#17-فهرست-apiها)
18. [گزارش‌گیری](#18-گزارشگیری)
19. [Audit Log و لاگ امنیتی](#19-audit-log-و-لاگ-امنیتی)
20. [معماری شبکه و امنیت زیرساخت](#20-معماری-شبکه-و-امنیت-زیرساخت)
21. [Deployment و Docker](#21-deployment-و-docker)
22. [Backup](#22-backup)
23. [Monitoring و Alerting](#23-monitoring-و-alerting)
24. [Security Checklist](#24-security-checklist)
25. [معماری نهایی پیشنهادی](#25-معماری-نهایی-پیشنهادی)
26. [Stack نهایی](#26-stack-نهایی)
27. [قدم بعدی](#27-قدم-بعدی)

---

## 1. معماری کلی سیستم

```
                    ┌─────────────────────┐
                    │      راننده          │
                    │ موبایل / PWA / Web   │
                    └──────────┬──────────┘
                               │
                         HTTPS / TLS
                               │
                               ▼
                    ┌─────────────────────┐
                    │     Cloudflare      │
                    │ DNS + WAF + DDoS    │
                    │ Rate Limit + CDN    │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │ Nginx / Reverse     │
                    │ Proxy               │
                    └──────────┬──────────┘
                               │
                    ┌──────────▼──────────┐
                    │     Laravel API     │
                    │ Authentication      │
                    │ Queue Engine        │
                    │ Business Logic      │
                    └─────┬────────┬──────┘
                          │        │
              ┌───────────┘        └──────────────┐
              ▼                                   ▼
      ┌──────────────┐                    ┌──────────────┐
      │ PostgreSQL   │                    │    Redis     │
      │ Main DB      │                    │ Cache/Queue  │
      └──────────────┘                    └──────┬───────┘
                                                 │
                                                 ▼
                                       ┌──────────────────┐
                                       │ Laravel Reverb   │
                                       │ WebSocket        │
                                       └────────┬─────────┘
                                                │
                                                ▼
                                       ┌──────────────────┐
                                       │ Operator Panel   │
                                       │ Live Queue       │
                                       └──────────────────┘

                          Laravel Queue
                                │
                                ▼
                      ┌──────────────────┐
                      │   SMS Gateway    │
                      │ Kavenegar / ...  │
                      └────────┬─────────┘
                               │
                               ▼
                        📱 مدیرعامل
```

نتیجه‌ی این معماری: اپراتور مجبور نیست هر ۱۰ ثانیه صفحه را Refresh کند؛ تغییرات از طریق WebSocket به پنل Push می‌شود.

---

## 2. بخش‌های اصلی سیستم

سیستم به ۵ بخش اصلی تقسیم می‌شود:

| بخش | کاربر | کارکرد |
|---|---|---|
| A. پنل راننده | راننده | ورود با OTP، ثبت کامیون، دریافت نوبت، مشاهده وضعیت |
| B. پنل اپراتور | اپراتور صف | مدیریت صف Real-Time، تغییر وضعیت نوبت‌ها |
| C. صفحه ورود/نگهبانی/باسکول | نگهبان، باسکول | اعتبارسنجی نوبت، Check-in، ثبت وزن |
| D. پنل مدیریت کارخانه | مدیر کارخانه، Super Admin | ظرفیت، محصولات، کاربران، تنظیمات، گزارش |
| E. داشبورد مدیرعامل | مدیرعامل | فقط KPI و گزارش (بدون دسترسی عملیاتی) |

### A. پنل راننده

اپلیکیشن نصب‌شونده نمی‌سازیم؛ در فاز اول **PWA / Web App**:

```
factory.ir/queue
```

مسیر راننده:

```
شماره موبایل → OTP → ورود → ثبت کامیون → نوع بار → تاریخ → دریافت نوبت
```

---

## 3. پنل و فرآیند راننده

### 3.1 صفحه اول

```
┌──────────────────────────┐
│        لوگوی کارخانه      │
│                          │
│       نوبت بارگیری       │
│                          │
│ شماره موبایل             │
│ ┌──────────────────────┐ │
│ │ 09xxxxxxxxx          │ │
│ └──────────────────────┘ │
│                          │
│      [ دریافت کد ]       │
└──────────────────────────┘
```

### 3.2 OTP

```
کد ارسال شده را وارد کنید
[  _  _  _  _  _  ]
ارسال مجدد 00:42
```

### 3.3 اطلاعات کامیون

```
شماره پلاک
[ ایران ] [ 12 ] [ 345 ] [ 67 ]

نوع خودرو
○ تک   ○ جفت   ○ تریلی   ○ خاور   ○ کامیون

نام راننده
شماره موبایل
```

اگر راننده قبلاً ثبت شده باشد، اطلاعاتش از دیتابیس خوانده و فرم Pre-fill می‌شود.

### 3.4 انتخاب نوع بار

```
┌───────────────────┐      ┌───────────────────┐
│ محصول A           │      │ محصول B           │
│ بارگیری: 30 تن    │      │ بارگیری: 20 تن    │
└───────────────────┘      └───────────────────┘
```

فهرست محصولات و ظرفیت بارگیری هر کدام باید از پنل مدیریت قابل تغییر باشد (بدون Deploy).

### 3.5 UX Flow کامل راننده

```
ورود → شماره موبایل → OTP → اطلاعات کامیون → نوع بار
   → انتخاب تاریخ → انتخاب ساعت → تأیید → شماره نوبت
   → QR → SMS Confirmation → مراجعه به کارخانه
   → QR Scan → Check-in → انتظار → فراخوان → بارگیری → پایان
```

### 3.6 پیگیری وضعیت نوبت توسط راننده

راننده با شماره موبایل + OTP وضعیت لحظه‌ای را می‌بیند:

```
نوبت شما
#128

وضعیت: 🟡 در انتظار
تعداد خودروهای قبل از شما: 4
زمان تقریبی: 35 دقیقه
```

و به‌صورت Real-Time:

```
🟢 نوبت شما فرا رسید
به لاین شماره 2 مراجعه کنید.
```

این قابلیت بخش بزرگی از تماس‌های تلفنی با کارخانه را حذف می‌کند.

### 3.7 ETA

```
12 کامیون در انتظار
Average Loading Time: 24 min
Estimated Waiting: 48 min
→ مراجعه تقریبی: 11:20
```

در UI صریحاً «تخمینی» نوشته شود؛ عدد را با لحن قطعی نشان ندهیم.

---

## 4. ظرفیت و صدور نوبت

### 4.1 مدل ظرفیت

نوبت صرفاً یک شماره‌ی افزایشی نیست؛ ظرفیت واقعی کارخانه مدل می‌شود:

```
ساعت       ظرفیت       رزرو شده
08:00       5             5
09:00       5             4
10:00       5             2
11:00       5             0
```

### 4.2 تنظیمات قابل کنترل توسط مدیر

```
روز: شنبه
شروع نوبت‌دهی:      07:00
پایان نوبت‌دهی:     18:00
ظرفیت هر ساعت:       5 کامیون
حداکثر نوبت روزانه:  80 کامیون
زمان هر نوبت:        30 دقیقه
تعداد لاین بارگیری:  3
زمان متوسط بارگیری:  25 دقیقه
```

### 4.3 خروجی صدور نوبت

```
        ✓ نوبت شما ثبت شد

        شماره نوبت
          128

        تاریخ:  1405/06/16
        ساعت مراجعه: 10:30
        پلاک: 12 ـ 345 ـ 67
        نوع بار: محصول A

        ┌─────────────────┐
        │ نمایش QR Code   │
        └─────────────────┘
```

راننده هنگام ورود QR را نشان می‌دهد.

---

## 5. چرخه وضعیت نوبت (State Machine)

مسیر اصلی:

```
BOOKED
   ↓
WAITING
   ↓
CALLED
   ↓
CHECKED_IN
   ↓
LOADING
   ↓
LOADED
   ↓
COMPLETED
```

وضعیت‌های استثنا:

```
CANCELLED
NO_SHOW
REJECTED
EXPIRED
```

قوانین:

- انتقال وضعیت فقط از طریق Action مجاز انجام شود، نه `update(['status' => ...])` پراکنده در Controller.
- بازگشت به عقب (مثلاً `LOADING → BOOKED`) فقط با Permission ویژه و حتماً با Audit Log.
- هر انتقال با `changed_by`, `changed_at`, `reason` ثبت شود — این داده بعداً ستون فقرات گزارش‌گیری است.

---

## 6. پنل اپراتور و Real-Time

### 6.1 صفحه اصلی

```
┌──────────────────────────────────────────────────────────┐
│ کارخانه        مدیریت صف کامیون‌ها        اپراتور        │
├──────────────────────────────────────────────────────────┤
│  امروز                                                   │
│  کل نوبت      منتظر     درحال بارگیری    پایان‌یافته      │
│    84           12            3              69          │
├──────────────────────────────────────────────────────────┤
│  صف فعلی                                                 │
│ #125   12-345-67    محصول A    منتظر        10:10        │
│ #126   45-231-11    محصول B    ورود         10:20        │
│ #127   65-982-43    محصول A    بارگیری      10:25        │
│ #128   21-123-55    محصول A    منتظر        10:30        │
└──────────────────────────────────────────────────────────┘
```

### 6.2 Real-Time

```
                    نوبت جدید
                       ↓
                  WebSocket (Reverb)
                       ↓
              ┌───────────────┐
              │ Operator Panel│
              └───────────────┘
                       ↓
                 نمایش #128
```

پیاده‌سازی: **Laravel Reverb + Redis** — نه Ajax Polling که هر چند ثانیه دیتابیس را بکشد.

کانال‌ها (خصوصی/Presence):

- `private-factory.{factory_id}.queue` — تغییرات صف
- `private-factory.{factory_id}.dashboard` — شمارنده‌های داشبورد
- `private-driver.{driver_id}` — وضعیت نوبت راننده
- `private-factory.{factory_id}.gate` — رویدادهای نگهبانی

### 6.3 دکمه‌های اپراتور

```
[ فراخوانی ]  [ ورود به محوطه ]  [ شروع بارگیری ]  [ پایان بارگیری ]
[ عدم حضور ]  [ لغو ]           [ مشاهده جزئیات ]
```

UX باید جلوی خطا را بگیرد: دکمه‌ای که در وضعیت فعلی مجاز نیست، اصلاً فعال نباشد. اعتبارسنجی هم در UI و هم در Backend (Policy + State Machine) انجام شود.

---

## 7. ورود کامیون، QR و Check-in

### 7.1 صفحه نگهبانی/باسکول

```
┌───────────────────────────────┐
│       ورود کامیون             │
├───────────────────────────────┤
│ پلاک: 12 - 345 - 67           │
│                               │
│ وضعیت: ✓ نوبت معتبر           │
│                               │
│ نوبت: 128                     │
│ ساعت: 10:30                   │
│ نوع بار: محصول A              │
│                               │
│       [ ثبت ورود ]            │
└───────────────────────────────┘
```

### 7.2 مسیر QR

```
QR → Scan → Validate Ticket → Check-in
```

### 7.3 امنیت QR

QR **نباید** این باشد:

```
appointment_id=128
```

چون بی‌زحمت قابل جعل است. به‌جای آن:

```
QR Payload → Signed / Encrypted Token → Server Validation → Appointment
```

- توکن امضاشده (HMAC یا JWT کوتاه‌عمر) با `appointment_id`, `nonce`, `exp`
- تاریخ انقضا داشته باشد (مثلاً تا پایان روز نوبت)
- Token پس از Check-in یک‌بارمصرف شود (Replay Protection روی Redis)

---

## 8. پنل مدیریت و داشبورد مدیرعامل

### 8.1 Dashboard مدیریت

```
نوبت امروز       84
ورود امروز       72
بارگیری          18
تکمیل شده        51
لغو شده           3
عدم حضور          4
```

### 8.2 داشبورد مدیرعامل

برای مدیرعامل همان پنل اپراتور را نشان نده — یک داشبورد جداگانه:

```
┌────────────────────────────────────┐
│       وضعیت بارگیری امروز          │
├────────────────────────────────────┤
│              84                    │
│         کامیون امروز               │
├───────────┬──────────┬─────────────┤
│ انتظار    │ بارگیری  │ تکمیل       │
│ 12        │ 3        │ 69          │
└───────────┴──────────┴─────────────┘
```

به‌علاوه: نمودار ورود کامیون، نمودار بارگیری، متوسط انتظار، محصولات، تناژ، عملکرد اپراتورها.

### 8.3 Notification Center

```
🔔 3
نوبت جدید #128
کامیون وارد شد #127
بارگیری #125 تکمیل شد
```

---

## 9. نقش‌ها و دسترسی (RBAC)

| نقش | دسترسی |
|---|---|
| Super Admin | همه چیز |
| مدیر کارخانه | گزارش + مدیریت (ظرفیت، محصولات، کاربران عملیاتی) |
| اپراتور | صف و تغییر وضعیت نوبت |
| نگهبان | Check-in و ورود |
| باسکول | ثبت وزن |
| انبار | بارگیری |
| مدیرعامل | داشبورد و گزارش (Read-Only) |
| راننده | فقط نوبت خودش |

اصول:

- مدیرعامل نباید بتواند تنظیمات دیتابیس یا کاربران را دستکاری کند. سیستم امن با عنوان شغلی مذاکره نمی‌کند.
- Permission-based، نه صرفاً Role-based: نقش‌ها مجموعه‌ای از Permission هستند تا بعداً بدون تغییر کد قابل بازتعریف باشند.
- هر Endpoint یک Policy/Gate مشخص دارد؛ `if (auth())` کافی نیست.

---

## 10. معماری Backend و ساختار پروژه

### 10.1 Stack

```
Laravel 13
PHP 8.4
PostgreSQL
Redis
Laravel Reverb
Laravel Queue
Laravel Horizon
Nginx
Cloudflare
```

### 10.2 ساختار

```
app/
  Domain/
      Appointment/
      Queue/
      Loading/
      Driver/
      Truck/
      Notification/
  Services/
      AppointmentService.php
      QueueService.php
      SmsService.php
      NotificationService.php
  Actions/
      CreateAppointment.php
      CheckInTruck.php
      StartLoading.php
      CompleteLoading.php
  Policies/
      AppointmentPolicy.php
      QueuePolicy.php
  Jobs/
      SendAppointmentSms.php
      SendNotification.php
  Events/
      AppointmentCreated.php
      TruckCheckedIn.php
      LoadingStarted.php
      LoadingCompleted.php
  Listeners/
  Http/
      Controllers/
      Requests/
      Resources/
  Models/
```

این ساختار در پروژه‌های صنعتی خیلی بهتر از ریختن ۴۰۰۰ خط منطق داخل Controller است.

### 10.3 Modular Monolith، نه Microservice

```
Laravel
├── Appointment
├── Queue
├── Loading
├── Driver
├── Truck
├── Notification
├── Reporting
└── Administration
```

هر ماژول مرزبندی خودش را دارد (Model, Service, Action, Event مخصوص خودش) و ارتباط بین ماژول‌ها از طریق Service/Event است، نه دسترسی مستقیم به جدول‌های همدیگر.

وقتی کارخانه به چند شعبه، چند انبار، چند لاین بارگیری و چند هزار نوبت روزانه رسید، جدا کردن سرویس‌ها معنی پیدا می‌کند. Microservice از روز اول برای یک کارخانه متوسط معمولاً فقط تعداد چیزهایی را که می‌توانند خراب شوند زیاد می‌کند.

---

## 11. Frontend

انتخاب پیشنهادی:

```
Laravel + Inertia.js + Vue 3 + TypeScript + Tailwind CSS
```

گزینه جایگزین (SPA کامل):

```
Vue 3 + TypeScript + Pinia + Tailwind + Laravel API
```

برای این پروژه **Laravel + Inertia + Vue** ترجیح دارد: پیچیدگی معماری کمتر است و لازم نیست برای هر چیز ساده سه سرور و هفت لایه Abstraction ساخته شود.

- پنل راننده: PWA (Installable, Offline Shell, Push آماده برای فاز بعد)
- پنل اپراتور/نگهبانی: Inertia + Vue، با Echo متصل به Reverb
- پنل مدیریت و داشبورد: همان Stack، با نمودارها

---

## 12. ساختار دیتابیس

### 12.1 جداول اصلی

```
users
roles
permissions
drivers
trucks
truck_types
products
loading_points
appointments
appointment_statuses
queue_entries
loading_sessions
checkins
loading_records
sms_messages
sms_templates
notifications
factories
factory_settings
audit_logs
login_attempts
otp_requests
```

### 12.2 جدول appointments

```
appointments
  id
  appointment_number
  driver_id
  truck_id
  product_id
  date
  time_slot
  status
  created_at
  updated_at
  cancelled_at
  created_ip
  user_agent
```

فیلدهای پیشنهادی اضافه برای انسجام و همزمانی:

```
  factory_id
  slot_id              -- FK به جدول ظرفیت ساعتی
  idempotency_key      -- UNIQUE
  qr_token_hash
  checked_in_at
  called_at
  loading_started_at
  loading_completed_at
```

### 12.3 جدول ظرفیت (پیشنهادی)

```
appointment_slots
  id
  factory_id
  date
  start_time
  end_time
  capacity          -- ظرفیت کل
  reserved_count    -- تعداد رزروشده
  UNIQUE (factory_id, date, start_time)
  CHECK (reserved_count <= capacity)
```

`CHECK (reserved_count <= capacity)` در سطح دیتابیس یعنی حتی اگر یک باگ در کد از قفل عبور کرد، دیتابیس اجازه‌ی ظرفیت منفی نمی‌دهد.

### 12.4 Index های کلیدی

```
appointments (factory_id, date, status)
appointments (driver_id, status)
appointments (truck_id, status)
appointments (appointment_number) UNIQUE
appointments (idempotency_key) UNIQUE
otp_requests (mobile, created_at)
audit_logs (entity_type, entity_id, created_at)
```

---

## 13. همزمانی، Race Condition و Idempotency

### 13.1 مشکل

```
Request A → ظرفیت 1
Request B → ظرفیت 1

اگر طراحی درست نباشد:
A → قبول
B → قبول
ظرفیت = -1     ← فاجعه کوچک صنعتی
```

### 13.2 راه‌حل: Transaction + Lock + Constraint

```
BEGIN TRANSACTION
    SELECT ... FROM appointment_slots
      WHERE id = :slot FOR UPDATE          -- Row Lock
    IF reserved_count >= capacity → ROLLBACK / خطای «ظرفیت تکمیل»
    INSERT INTO appointments (...)
    UPDATE appointment_slots
      SET reserved_count = reserved_count + 1
      WHERE id = :slot
COMMIT
```

سه لایه دفاع:

1. `SELECT ... FOR UPDATE` روی ردیف Slot
2. `CHECK (reserved_count <= capacity)` در دیتابیس
3. Unique Constraint روی محدودیت‌های کسب‌وکار (مثلاً یک نوبت فعال به ازای هر پلاک)

### 13.3 Idempotency

اگر راننده سه بار روی «ثبت نوبت» کلیک کند، نباید سه نوبت صادر شود.

- کلاینت برای هر تلاش ثبت، یک `idempotency_key` (UUID) تولید و در Header ارسال می‌کند.
- سرور: اگر کلید تکراری بود، همان پاسخ قبلی برگردانده شود (نه ایجاد رکورد جدید، نه خطا).
- `UNIQUE(idempotency_key)` روی جدول تضمین نهایی است.
- دکمه‌ی Submit در UI بعد از اولین کلیک Disable شود (لایه‌ی اول، نه تنها لایه).

---

## 14. احراز هویت و امنیت API

### 14.1 احراز هویت راننده

راننده Username/Password ندارد:

```
Mobile → OTP → Session / Token
```

سیاست OTP:

```
OTP validity:  2 min
Max attempts:  5
Resend:        60 sec
```

- OTP در دیتابیس **Hash** شود (نه Plain).
- انقضا، محدودیت تعداد تلاش، محدودیت تعداد ارسال SMS.
- Rate Limit بر اساس IP **و** شماره موبایل.
- بعد از تأیید موفق، OTP باطل شود.

### 14.2 امنیت API

هر Endpoint Authorization جداگانه دارد:

```
/api/auth/request-otp
/api/auth/verify-otp
/api/appointments
/api/appointments/{id}
/api/operator/queue
/api/operator/checkin
/api/operator/loading
```

`if (auth())` و تمام — همان نوع امنیتی است که تا روز حادثه بسیار قانع‌کننده به نظر می‌رسد. به‌جای آن: Policy برای هر Resource، Form Request برای Validation، و API Resource برای خروجی (تا فیلد اضافه لو نرود).

### 14.3 Anti-Abuse

برای اینکه یک نفر با اسکریپت ۵۰۰ نوبت نگیرد:

```
IP Rate Limit
Mobile Rate Limit
Device Fingerprint
OTP Rate Limit
Daily Appointment Limit
Plate Limit
```

قوانین پیش‌فرض (همه باید از پنل مدیریت قابل تنظیم باشند):

```
هر شماره موبایل: حداکثر 2 نوبت فعال
هر پلاک:         حداکثر 1 نوبت فعال
```

---

## 15. Notification و SMS

### 15.1 SMS نباید داخل Request اصلی باشد

اشتباه:

```
Driver → Laravel → Create Ticket → SMS API → Response
```

اگر SMS Provider کند شود، صدور نوبت هم کند می‌شود.

درست:

```
Driver
   ↓
Laravel
   ↓
Create Ticket
   ↓
Commit DB
   ↓
Response سریع به راننده
   ↓
Redis Queue
   ↓
SMS Worker
   ↓
SMS Provider
```

Job با Retry و Backoff، و در صورت شکست نهایی → `failed_jobs` + Alert.

### 15.2 Abstraction Provider

در کد مستقیماً به یک Provider وابسته نباش:

```
SmsService (Interface)
     │
     ├── KavenegarProvider
     ├── IPPanelProvider
     └── AnotherProvider
```

اگر فردا پنل پیامکی عوض شد، لازم نیست نصف پروژه جراحی شود — فقط Binding در Service Container تغییر می‌کند.

### 15.3 نمونه پیام مدیرعامل

```
نوبت جدید بارگیری
شماره نوبت: 128
پلاک: 12-345-67
راننده: علی رضایی
نوع بار: محصول A
تاریخ: 1405/06/16
ساعت: 10:30
```

### 15.4 Notification فقط برای مدیرعامل نیست

سیستم Notification از ابتدا عمومی طراحی شود:

| گیرنده | رویداد |
|---|---|
| مدیرعامل | نوبت جدید صادر شد |
| اپراتور | کامیون جدید وارد صف شد |
| راننده | نوبت شما نزدیک است |
| نگهبانی | کامیون #128 وارد شد |
| مدیر کارخانه | گزارش پایان روز |

کانال‌ها: SMS، In-App (Notification Center)، و در فاز بعد Web Push.

---

## 16. Event-Driven

```
AppointmentCreated
        │
        ├── Send SMS
        ├── Notify Operator
        ├── Update Dashboard (Broadcast)
        └── Write Audit Log
```

```
TruckCheckedIn
        │
        ├── Update Queue
        ├── Notify Operator
        └── Audit
```

Listener ها Queued باشند (به‌جز Audit که بهتر است Sync و در همان Transaction ثبت شود).

---

## 17. فهرست APIها

```
POST   /auth/request-otp
POST   /auth/verify-otp

GET    /appointments
POST   /appointments
GET    /appointments/{id}
DELETE /appointments/{id}

GET    /queue/today
GET    /queue/current
POST   /queue/{id}/call
POST   /queue/{id}/checkin
POST   /queue/{id}/start-loading
POST   /queue/{id}/complete-loading

GET    /dashboard
GET    /reports
```

قراردادها:

- Versioning: `/api/v1/...`
- خطاها با ساختار یکسان: `{ code, message, errors }`
- `POST /appointments` هدر `Idempotency-Key` می‌پذیرد.
- خروجی‌ها از طریق API Resource، نه `->toArray()` مستقیم مدل.

---

## 18. گزارش‌گیری

```
تعداد کامیون‌ها
  امروز: 84
  هفته:  523
  ماه:   2,180

متوسط زمان انتظار:  23 دقیقه
متوسط بارگیری:      31 دقیقه
No Show:            7.4%

شلوغ‌ترین ساعات
  08:00 → 18 کامیون
  09:00 → 22 کامیون
  10:00 → 25 کامیون
```

چون هر انتقال وضعیت Timestamp دارد، این اعداد مشتق‌شده از داده‌ی واقعی‌اند نه تخمین دستی. از این داده می‌توان فهمید مشکل کارخانه واقعاً کجاست، نه اینکه طبق سنت صنعتی تقصیر گردن راننده بیفتد.

خروجی: نمایش در پنل + Export به Excel/CSV.

---

## 19. Audit Log و لاگ امنیتی

### 19.1 Audit Log

هر عملیات حساس ثبت شود:

```
User | Action | Entity | Old Value | New Value | IP | User Agent | Timestamp
```

نمونه:

```
Operator:    Ali
Action:      CANCEL_APPOINTMENT
Appointment: #128
Old:         WAITING
New:         CANCELLED
IP:          10.10.20.14
Time:        10:32
```

### 19.2 لاگ امنیتی (جدا از Audit Log)

```
login_success
login_failed
otp_requested
otp_failed
rate_limit
permission_denied
suspicious_request
admin_action
```

این‌ها بعداً برای بررسی نفوذ حیاتی‌اند. Retention مشخص داشته باشند و غیرقابل حذف از UI باشند.

---

## 20. معماری شبکه و امنیت زیرساخت

### 20.1 Cloudflare

```
factory.ir → Cloudflare → Nginx → Laravel
```

فعال: DNS, Proxy, WAF, DDoS Protection, Rate Limiting, Bot Protection, TLS.

### 20.2 شبکه امن

Database نباید Public IP داشته باشد:

```
Internet
   │
   ▼
Cloudflare
   │
   ▼
Firewall
   │
   ▼
Nginx
   │
   ▼
Application Server
   │
   ├──── PostgreSQL
   │
   └──── Redis
```

```
PostgreSQL و Redis: NO PUBLIC ACCESS
```

### 20.3 اگر سرور داخل کارخانه باشد

```
                   INTERNET
                       │
                  Cloudflare
                       │
                       ▼
                Public VPS
                Nginx / WAF
                       │
                  WireGuard
                       │
                       ▼
              ┌─────────────────┐
              │ Factory Network │
              │                 │
              │ App Server      │
              │ DB              │
              │ Redis           │
              └─────────────────┘
```

این مدل نیاز به طراحی دقیق Network Segmentation دارد.

### 20.4 VLAN پیشنهادی کارخانه

```
VLAN 10  Management
VLAN 20  Servers
VLAN 30  Operator PCs
VLAN 40  CCTV / IoT
VLAN 50  Guest
VLAN 60  Industrial Devices
```

Firewall بین VLANها:

```
Guest  ─X→  Database
CCTV   ─X→  Database
```

---

## 21. Deployment و Docker

### 21.1 شروع (Single VPS)

```
Internet
   │
Cloudflare
   │
   ▼
┌─────────────────────┐
│ VPS                 │
│ Nginx               │
│ Laravel             │
│ Reverb              │
│ Queue Worker        │
│ Horizon             │
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│ PostgreSQL Server   │
│ Redis               │
└─────────────────────┘
```

### 21.2 Production بزرگ‌تر

```
             Cloudflare
                  │
             Load Balancer
                  │
        ┌─────────┴─────────┐
        ▼                   ▼
    App Server 1        App Server 2
        │                   │
        └─────────┬─────────┘
                  ▼
              Redis
                  │
             PostgreSQL
```

### 21.3 Docker

```
docker-compose
  nginx
  app
  queue
  reverb
  scheduler
  postgres
  redis
```

PostgreSQL در Production می‌تواند خارج از Docker و روی سرور اختصاصی باشد.

---

## 22. Backup

```
Production
     ↓
Encrypted Backup
     ↓
Separate Storage (Off-site)
```

حداقل‌ها:

- Daily Full Backup + Point-in-Time Recovery (WAL Archiving)
- Backup روی همان سرور DB نباشد
- رمزنگاری در حالت At-Rest و در انتقال
- Retention مشخص (مثلاً ۷ روزانه، ۴ هفتگی، ۱۲ ماهانه)
- **Restore را تست کن.** Backupای که هیچ‌وقت Restore نشده، بیشتر یک امیدواری است تا Backup.

---

## 23. Monitoring و Alerting

پایش:

```
CPU | RAM | Disk
PostgreSQL | Redis | Queue
Laravel | Nginx | SSL
```

Alert:

```
Queue failed
Disk > 80%
DB unavailable
Redis unavailable
SMS failure > threshold
Application error spike
SSL expiry < 14 days
```

ابزار: Horizon برای صف، Error Tracking (Sentry یا مشابه)، Uptime Monitor خارجی.

---

## 24. Security Checklist

### Network

- [ ] Cloudflare (WAF, DDoS, Rate Limit, Bot Protection)
- [ ] Firewall
- [ ] TLS
- [ ] SSH فقط با Key (تغییر Port به‌تنهایی امنیت محسوب نمی‌شود)
- [ ] DB بدون Public Access
- [ ] Redis بدون Public Access
- [ ] VPN برای مدیریت سرور
- [ ] VLAN در شبکه کارخانه

### Application

- [ ] CSRF
- [ ] XSS Protection
- [ ] SQL Injection Protection (Query Builder / Eloquent، بدون Raw Concatenation)
- [ ] Rate Limiting
- [ ] RBAC
- [ ] OTP Protection (Hash, Expiry, Attempt Limit)
- [ ] Session Security
- [ ] Input Validation (Form Request)
- [ ] Authorization در هر Endpoint
- [ ] Audit Log
- [ ] Idempotency
- [ ] Signed QR

### Infrastructure

- [ ] Automatic Security Updates
- [ ] Fail2ban در صورت نیاز
- [ ] Docker Hardening (non-root, read-only fs در حد ممکن)
- [ ] Secrets خارج از Git
- [ ] `.env` خارج از Repository
- [ ] Backup
- [ ] Monitoring
- [ ] Error Tracking

### Secret Management

این‌ها هرگز داخل Git نباشند:

```
DB_PASSWORD
SMS_API_KEY
APP_KEY
JWT_SECRET
CLOUDFLARE_TOKEN
```

روش: Environment Variables، و در Production ترجیحاً Secret Manager.

---

## 25. معماری نهایی پیشنهادی

```
                 ┌─────────────────────┐
                 │       Driver        │
                 │   Mobile / PWA      │
                 └──────────┬──────────┘
                            │
                           HTTPS
                            │
                            ▼
                 ┌─────────────────────┐
                 │     Cloudflare      │
                 │ WAF / DDoS / Rate   │
                 └──────────┬──────────┘
                            │
                            ▼
                 ┌─────────────────────┐
                 │       Nginx         │
                 └──────────┬──────────┘
                            │
                            ▼
                 ┌─────────────────────┐
                 │      Laravel        │
                 │                     │
                 │ Auth                │
                 │ Appointment         │
                 │ Queue               │
                 │ Loading             │
                 │ Reports             │
                 └──────┬───────┬──────┘
                        │       │
              ┌─────────┘       └──────────┐
              ▼                            ▼
       ┌──────────────┐             ┌──────────────┐
       │ PostgreSQL   │             │    Redis     │
       │              │             │              │
       │ Appointments │             │ Queue        │
       │ Drivers      │             │ Cache        │
       │ Trucks       │             │ Realtime     │
       │ Loading      │             └──────┬───────┘
       │ Audit        │                    │
       └──────────────┘                    ▼
                                  ┌─────────────────┐
                                  │ Laravel Reverb  │
                                  └────────┬────────┘
                                           │
                                           ▼
                                  ┌─────────────────┐
                                  │ Operator Panel  │
                                  │ Vue + Inertia   │
                                  └─────────────────┘

                         Redis Queue
                              │
                              ▼
                       ┌─────────────┐
                       │ SMS Service │
                       └──────┬──────┘
                              │
                              ▼
                         📱 مدیرعامل
```

---

## 26. Stack نهایی

| بخش | تکنولوژی |
|---|---|
| Driver UI | PWA / Vue |
| Operator | Vue 3 + Inertia |
| Backend | Laravel 13 |
| PHP | 8.4 |
| Database | PostgreSQL |
| Cache | Redis |
| Queue | Laravel Queue |
| Queue Monitor | Horizon |
| Realtime | Laravel Reverb |
| Web Server | Nginx |
| Edge Security | Cloudflare |
| Authentication | OTP |
| SMS | SMS Provider API |
| QR | Signed Token |
| Server | Ubuntu 24.04 |
| Container | Docker |
| Backup | Encrypted Off-site Backup |

---

## 27. قدم بعدی

قبل از شروع کدنویسی، این چهار مورد باید دقیق شوند:

1. **Database Schema + ERD** — جدول‌ها، روابط، Constraintها، Indexها
2. **API Specification** — OpenAPI، شامل Request/Response/Error هر Endpoint
3. **Wireframe کامل سه پنل** — راننده، اپراتور، مدیرعامل
4. **State Machine دقیق نوبت** — انتقال‌های مجاز و Permission هر انتقال

تغییر منطق صف در وسط پروژه تقریباً همان لذتی را دارد که تعویض موتور هواپیما هنگام پرواز.
