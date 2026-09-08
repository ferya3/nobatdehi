# پوسته‌ی WebView چیزی برای مبهم‌سازی ندارد؛ فقط هشدارهای بی‌ربط را ساکت می‌کنیم
-dontwarn android.webkit.**

# WorkManager کلاسِ Worker را با نامش و از روی بازتاب می‌سازد.
#
# بدون این، ساختِ release کامپایل می‌شود، نصب می‌شود، و بعد در سکوت هیچ
# اعلانی نمی‌آید — چون R8 اسم کلاس را عوض کرده و WorkManager پیدایش نمی‌کند.
-keep class ir.nobatdehi.driver.NotificationWorker { *; }
-keep class * extends androidx.work.ListenableWorker { public <init>(...); }
