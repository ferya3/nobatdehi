package ir.nobatdehi.driver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * بعد از روشن شدن گوشی، کار دوره‌ای را دوباره بچین.
 *
 * WorkManager خودش کارهای دوره‌ای را بعد از ری‌استارت برمی‌گرداند، ولی
 * سرویسِ اتصال دائم را نه — اندروید اجازه‌ی راه‌اندازیِ خودکارِ سرویسِ
 * پیش‌زمینه از boot را نمی‌دهد. پس همان کارِ دوره‌ای است که در نوبتِ بعدی
 * می‌بیند راننده نوبت دارد و سرویس را بالا می‌آورد.
 *
 * یعنی بعد از ری‌استارتِ گوشی، تا حداکثر ۱۵ دقیقه اعلان از راهِ کند می‌آید
 * و بعد دوباره فوری می‌شود.
 */
class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return

        Notifier.createChannels(context)
        NotificationWorker.schedule(context)
    }
}
