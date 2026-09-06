# سامانه نوبت‌دهی بارگیری کامیون

سامانه‌ی نوبت‌دهی، مدیریت صف و بارگیری کامیون‌های کارخانه.

## مستندات

- [معماری سیستم](docs/ARCHITECTURE.md) — معماری کلی، ماژول‌ها، دیتابیس، امنیت، Deployment

## خلاصه Stack

| بخش | تکنولوژی |
|---|---|
| Backend | Laravel 13 / PHP 8.4 |
| Database | PostgreSQL |
| Cache / Queue | Redis + Laravel Queue + Horizon |
| Realtime | Laravel Reverb |
| Frontend | Vue 3 + TypeScript + Inertia.js + Tailwind |
| Driver UI | PWA |
| Web Server | Nginx |
| Edge | Cloudflare (WAF / DDoS / Rate Limit) |
| Auth | OTP (SMS) |
| Container | Docker |

## وضعیت

پیش از شروع کدنویسی. قدم بعدی: ERD، API Specification، Wireframe سه پنل، و State Machine دقیق نوبت.
