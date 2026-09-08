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
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import org.json.JSONObject

/**
 * ساخت و نمایش اعلان — یک جا، برای هر دو راهِ رسیدن.
 *
 * اعلان از دو مسیر می‌آید: اتصال دائم (فوری) و سر زدنِ دوره‌ای (تور
 * ایمنی). هر دو باید دقیقاً یک شکل دیده شوند و مهم‌تر: با یک شناسه. اگر
 * شناسه‌ها فرق کنند، راننده یک خبر را دو بار روی نوار می‌بیند.
 */
object Notifier {

    const val CHANNEL_QUEUE = "nobat-queue"
    const val CHANNEL_SERVICE = "nobat-service"

    fun createChannels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        val manager = context.getSystemService(NotificationManager::class.java)

        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_QUEUE,
                context.getString(R.string.channel_queue),
                NotificationManager.IMPORTANCE_HIGH,
            ).apply { description = context.getString(R.string.channel_queue_description) },
        )

        /*
         * کانالِ خودِ سرویس عمداً کم‌اهمیت است.
         *
         * اندروید برای سرویسِ پیش‌زمینه اعلانِ دائمی را اجبار می‌کند و
         * راننده نمی‌تواند ببنددش. IMPORTANCE_MIN یعنی بی‌صدا، بدون لرزش،
         * و جمع‌شده در پایینِ فهرست — هست، ولی سرِ راه نیست.
         */
        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_SERVICE,
                context.getString(R.string.channel_service),
                NotificationManager.IMPORTANCE_MIN,
            ).apply {
                description = context.getString(R.string.channel_service_description)
                setShowBadge(false)
            },
        )
    }

    /**
     * @param item همان ساختاری که سرور می‌فرستد: id، title، body، path
     * @return آیا واقعاً روی نوار نشست
     */
    fun show(context: Context, item: JSONObject): Boolean {
        if (!allowed(context)) return false

        val id = item.optInt("id")
        val body = item.optString("body")
        val path = item.optString("path").takeIf { it.isNotEmpty() && it.startsWith("/") }

        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            data = Uri.parse(BuildConfig.SITE_URL + (path ?: BuildConfig.START_PATH))
        }

        val pending = PendingIntent.getActivity(
            context,
            id,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val notification = NotificationCompat.Builder(context, CHANNEL_QUEUE)
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
            // شناسه همان شناسه‌ی سرور است: همین یک سطر جلوی اعلانِ تکراری
            // را می‌گیرد وقتی هم اتصال دائم و هم سر زدن آن را می‌آورند
            NotificationManagerCompat.from(context).notify(id, notification)
            true
        } catch (_: SecurityException) {
            false
        }
    }

    /** اندروید ۱۳ به بعد، اعلان مجوز جداگانه می‌خواهد */
    fun allowed(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return true

        return ContextCompat.checkSelfPermission(
            context,
            Manifest.permission.POST_NOTIFICATIONS,
        ) == PackageManager.PERMISSION_GRANTED
    }
}
