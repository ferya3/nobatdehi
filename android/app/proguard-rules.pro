# پوسته‌ی WebView چیزی برای مبهم‌سازی ندارد؛ فقط هشدارهای بی‌ربط را ساکت می‌کنیم
-dontwarn android.webkit.**

# WorkManager کلاسِ Worker را با نامش و از روی بازتاب می‌سازد.
#
# بدون این، ساختِ release کامپایل می‌شود، نصب می‌شود، و بعد در سکوت هیچ
# اعلانی نمی‌آید — چون R8 اسم کلاس را عوض کرده و WorkManager پیدایش نمی‌کند.
-keep class ir.nobatdehi.driver.NotificationWorker { *; }
-keep class * extends androidx.work.ListenableWorker { public <init>(...); }

# OkHttp روی مسیرهای اختیاریِ Conscrypt و BouncyCastle اشاره دارد که در
# APK نیستند. بدون این، R8 با هشدارِ «کلاس گمشده» ساخت را متوقف می‌کند.
-dontwarn okhttp3.internal.platform.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# سرویس و گیرنده را اندروید با نامشان از Manifest می‌سازد
-keep class ir.nobatdehi.driver.RealtimeService { *; }
-keep class ir.nobatdehi.driver.BootReceiver { *; }
