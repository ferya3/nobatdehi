package ir.nobatdehi.driver

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.webkit.CookieManager
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.TimeUnit

/**
 * اعلان‌های تازه را از سرور می‌گیرد و روی نوار اعلان می‌گذارد.
 *
 * چرا سر زدن و نه push واقعی: push روی اندروید یعنی Firebase، و Firebase
 * از ایران نه ثبت‌نام می‌شود و نه روی گوشیِ بدون سرویس‌های گوگل کار می‌کند.
 * پس برنامه خودش سر می‌زند.
 *
 * قیمتش تأخیر است: اندروید کمتر از ۱۵ دقیقه اجازه‌ی کار دوره‌ای نمی‌دهد و
 * زیر Doze حتی دیرتر. برای «کارخانه فردا تعطیل است» کافی است؛ برای «نوبت
 * شما فرا رسید» پیامک هست و باید باشد.
 */
class NotificationWorker(
    context: Context,
    params: WorkerParameters,
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        // بدون کوکی یعنی راننده وارد نشده — خطا نیست، فقط کاری نیست
        val cookie = CookieManager.getInstance().getCookie(BuildConfig.SITE_URL)
            ?: return@withContext Result.success()

        try {
            val payload = get(LIST_URL, cookie) ?: return@withContext Result.success()

            val body = JSONObject(payload)
            val items = body.optJSONArray("notifications") ?: return@withContext Result.success()

            if (items.length() == 0) return@withContext Result.success()

            val shown = mutableListOf<Int>()

            for (i in 0 until items.length()) {
                val item = items.getJSONObject(i)

                if (show(item)) shown += item.getInt("id")
            }

            /*
             * رسید فقط برای آن‌هایی که واقعاً نشان داده شدند.
             *
             * اگر همه را رسید بزنیم، اعلانی که به‌خاطر نبودِ مجوز نمایش داده
             * نشد برای همیشه گم می‌شود — و راننده هرگز نمی‌فهمد خبری بوده.
             */
            if (shown.isNotEmpty()) {
                acknowledge(shown, body.optString("csrf"), cookie)
            }

            Result.success()
        } catch (_: Exception) {
            // شبکه‌ی کارخانه ضعیف است؛ دفعه‌ی بعد دوباره
            Result.retry()
        }
    }

    private fun get(url: String, cookie: String): String? {
        val connection = (URL(url).openConnection() as HttpURLConnection).apply {
            requestMethod = "GET"
            setRequestProperty("Cookie", cookie)
            setRequestProperty("Accept", "application/json")
            connectTimeout = 15_000
            readTimeout = 15_000
        }

        return try {
            // ۴۰۱ یعنی نشست تمام شده؛ تلاش دوباره دردی را دوا نمی‌کند
            if (connection.responseCode != 200) return null

            connection.inputStream.bufferedReader().use(BufferedReader::readText)
        } finally {
            connection.disconnect()
        }
    }

    private fun acknowledge(ids: List<Int>, csrf: String, cookie: String) {
        val connection = (URL(ACK_URL).openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            doOutput = true
            setRequestProperty("Cookie", cookie)
            setRequestProperty("Content-Type", "application/json")
            setRequestProperty("Accept", "application/json")
            setRequestProperty("X-CSRF-TOKEN", csrf)
            setRequestProperty("X-Requested-With", "XMLHttpRequest")
            connectTimeout = 15_000
            readTimeout = 15_000
        }

        try {
            val body = JSONObject().put("ids", JSONArray(ids)).toString()

            connection.outputStream.use { it.write(body.toByteArray()) }
            connection.responseCode
        } finally {
            connection.disconnect()
        }
    }

    /** @return آیا واقعاً روی نوار اعلان نشست */
    private fun show(item: JSONObject): Boolean {
        if (!allowed()) return false

        val id = item.getInt("id")
        val path = item.optString("path").takeIf { it.isNotEmpty() && it.startsWith("/") }

        val intent = Intent(applicationContext, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            data = Uri.parse(BuildConfig.SITE_URL + (path ?: BuildConfig.START_PATH))
        }

        val pending = PendingIntent.getActivity(
            applicationContext,
            id,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val body = item.optString("body")

        val notification = NotificationCompat.Builder(applicationContext, CHANNEL)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(item.optString("title"))
            .setContentText(body)
            // متن بلند روی نوار بریده می‌شود؛ با باز کردنش کامل دیده شود
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setContentIntent(pending)
            .setAutoCancel(true)
            .build()

        return try {
            NotificationManagerCompat.from(applicationContext).notify(id, notification)
            true
        } catch (_: SecurityException) {
            false
        }
    }

    /** اندروید ۱۳ به بعد، اعلان مجوز جداگانه می‌خواهد */
    private fun allowed(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return true

        return ContextCompat.checkSelfPermission(
            applicationContext,
            Manifest.permission.POST_NOTIFICATIONS,
        ) == PackageManager.PERMISSION_GRANTED
    }

    companion object {
        private const val CHANNEL = "nobat-queue"
        private const val WORK = "nobat-notifications"

        private val LIST_URL = BuildConfig.SITE_URL + "/queue/api/notifications"
        private val ACK_URL = BuildConfig.SITE_URL + "/queue/api/notifications/ack"

        fun createChannel(context: Context) {
            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

            val channel = NotificationChannel(
                CHANNEL,
                context.getString(R.string.channel_queue),
                NotificationManager.IMPORTANCE_HIGH,
            ).apply {
                description = context.getString(R.string.channel_queue_description)
            }

            context.getSystemService(NotificationManager::class.java)
                .createNotificationChannel(channel)
        }

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
