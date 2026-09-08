package ir.nobatdehi.driver

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.util.concurrent.TimeUnit

/**
 * تورِ ایمنی، نه راهِ اصلی.
 *
 * راهِ اصلی اتصال دائم است (RealtimeService) و همان ثانیه خبر می‌دهد. ولی
 * روی گوشی‌های ارزان، مدیرهای باتریِ سازنده سرویس را بی‌خبر می‌کشند و
 * اندروید هم بعد از ری‌استارت چیزی را خودکار بالا نمی‌آورد. آن‌وقت تنها
 * چیزی که باقی می‌ماند همین است.
 *
 * دو کار می‌کند: اعلانِ جامانده را می‌آورد، و سرویس را وقتی باید روشن
 * باشد و نیست، دوباره روشن می‌کند.
 */
class NotificationWorker(
    context: Context,
    params: WorkerParameters,
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        // بدون کوکی یعنی راننده وارد نشده — خطا نیست، فقط کاری نیست
        val cookie = Api.cookie() ?: return@withContext Result.success()

        try {
            syncService(cookie)
            deliverPending(cookie)

            Result.success()
        } catch (_: Exception) {
            // شبکه‌ی محوطه ضعیف است؛ دفعه‌ی بعد دوباره
            Result.retry()
        }
    }

    /** سرویس باید پا به پای «نوبتِ فعال دارد یا نه» روشن و خاموش شود */
    private fun syncService(cookie: String) {
        val config = Api.config(cookie) ?: return

        if (config.realtime) {
            RealtimeService.start(applicationContext)
        } else {
            RealtimeService.stop(applicationContext)
        }
    }

    private fun deliverPending(cookie: String) {
        val (csrf, items) = Api.pending(cookie) ?: return

        if (items.length() == 0) return

        val shown = mutableListOf<Int>()

        for (i in 0 until items.length()) {
            val item = items.getJSONObject(i)

            if (Notifier.show(applicationContext, item)) shown += item.getInt("id")
        }

        /*
         * رسید فقط برای آن‌هایی که واقعاً نشان داده شدند.
         *
         * اگر همه را رسید بزنیم، اعلانی که به‌خاطر نبودِ مجوز نمایش داده
         * نشد برای همیشه گم می‌شود — و راننده هرگز نمی‌فهمد خبری بوده.
         */
        if (shown.isNotEmpty()) Api.acknowledge(shown, csrf, cookie)
    }

    companion object {
        private const val WORK = "nobat-notifications"

        /**
         * KEEP و نه REPLACE: با REPLACE هر بار که راننده برنامه را باز کند
         * شمارنده از صفر شروع می‌شود، و راننده‌ای که روزی ده بار سر می‌زند
         * هیچ‌وقت به دقیقه‌ی پانزدهم نمی‌رسد.
         */
        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<NotificationWorker>(15, TimeUnit.MINUTES)
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build(),
                )
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK,
                ExistingPeriodicWorkPolicy.KEEP,
                request,
            )
        }
    }
}
