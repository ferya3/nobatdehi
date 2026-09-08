package ir.nobatdehi.driver

import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import org.json.JSONObject
import java.util.concurrent.TimeUnit
import kotlin.concurrent.thread
import kotlin.math.min
import kotlin.math.pow

/**
 * اتصال دائم به Reverb، تا خبر همان ثانیه برسد.
 *
 * چرا سرویسِ پیش‌زمینه و نه یک اتصالِ ساده: اندروید هر اتصالی را که برنامه
 * در پس‌زمینه باز نگه دارد ظرف چند دقیقه می‌بندد. تنها راهِ زنده ماندن،
 * سرویسِ پیش‌زمینه است و بهایش اعلانِ دائمی روی نوار — که راننده نمی‌تواند
 * ببنددش. برای همین کانالش IMPORTANCE_MIN است و خودِ سرویس فقط وقتی
 * زنده می‌ماند که راننده نوبتِ فعال داشته باشد.
 *
 * پروتکل همان Pusher است که Reverb حرف می‌زند:
 *
 *   ۱. وصل شو، socket_id بگیر
 *   ۲. آن را به /broadcasting/auth بده و امضا بگیر
 *   ۳. با امضا روی private-driver.{id} مشترک شو
 *   ۴. به ping جواب pong بده، وگرنه سرور قطع می‌کند
 */
class RealtimeService : Service() {

    private var socket: WebSocket? = null
    private var config: AppConfig? = null
    private var attempt = 0
    private var stopping = false

    /*
     * pingInterval کارِ اصلی را می‌کند.
     *
     * روی آنتنِ ضعیفِ محوطه، اتصال بی‌آنکه بسته شود می‌میرد: سوکت باز به
     * نظر می‌رسد و هیچ‌وقت خطایی نمی‌دهد. بدون ping، برنامه ساعت‌ها فکر
     * می‌کند وصل است و راننده هیچ خبری نمی‌گیرد.
     */
    private val client = OkHttpClient.Builder()
        .pingInterval(30, TimeUnit.SECONDS)
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(0, TimeUnit.MILLISECONDS)
        .build()

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startForeground()

        if (socket == null) connect()

        // START_STICKY: اگر سیستم زیر فشار حافظه سرویس را کشت، دوباره
        // بالا بیاید. راننده‌ای که منتظر نوبت است نباید قربانیِ یک بازیِ
        // سنگین شود که همان لحظه باز بوده.
        return START_STICKY
    }

    override fun onDestroy() {
        stopping = true
        socket?.close(NORMAL_CLOSURE, null)
        socket = null
        super.onDestroy()
    }

    private fun startForeground() {
        val pending = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val notification = NotificationCompat.Builder(this, Notifier.CHANNEL_SERVICE)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(getString(R.string.service_title))
            .setContentText(getString(R.string.service_body))
            .setPriority(NotificationCompat.PRIORITY_MIN)
            .setOngoing(true)
            .setShowWhen(false)
            .setContentIntent(pending)
            .build()

        ServiceCompat.startForeground(
            this,
            SERVICE_NOTIFICATION,
            notification,
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
                ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE
            } else {
                0
            },
        )
    }

    private fun connect() {
        thread(name = "nobat-realtime") {
            val cookie = Api.cookie()

            if (cookie == null) {
                stopSelf()
                return@thread
            }

            val loaded = try {
                Api.config(cookie)
            } catch (_: Exception) {
                null
            }

            /*
             * سرور می‌گوید دیگر نوبتِ فعالی نیست — کار سرویس تمام است.
             *
             * خودکشیِ سرویس عمدی است: هیچ‌کس بعداً یادش نمی‌ماند خاموشش
             * کند، و سرویسی که یادش می‌رود بمیرد، همان چیزی است که باتری
             * را می‌خورد.
             */
            if (loaded == null || !loaded.realtime) {
                stopSelf()
                return@thread
            }

            config = loaded
            open(loaded, cookie)
        }
    }

    private fun open(config: AppConfig, cookie: String) {
        val request = Request.Builder().url(config.socketUrl()).build()

        socket = client.newWebSocket(request, Listener(config, cookie))
    }

    /** قطع شد؛ دوباره وصل شو، ولی نه با ضربانِ ثابتی که باتری را می‌خورد */
    private fun reconnect() {
        if (stopping) return

        attempt += 1

        // ۲، ۴، ۸ … تا سقف ۶۰ ثانیه. سرورِ خاموش نباید هر ثانیه صدا شود.
        val delay = min(60.0, 2.0.pow(min(attempt, 6))) * 1000

        thread(name = "nobat-reconnect") {
            Thread.sleep(delay.toLong())

            if (stopping) return@thread

            val cookie = Api.cookie() ?: return@thread run { stopSelf() }
            val current = config ?: return@thread run { stopSelf() }

            open(current, cookie)
        }
    }

    private inner class Listener(
        private val config: AppConfig,
        private val cookie: String,
    ) : WebSocketListener() {

        override fun onMessage(webSocket: WebSocket, text: String) {
            val frame = try {
                JSONObject(text)
            } catch (_: Exception) {
                return
            }

            when (frame.optString("event")) {
                "pusher:connection_established" -> subscribe(webSocket, frame)

                // بی‌جواب گذاشتنش یعنی سرور اتصال را مرده می‌داند و می‌بندد
                "pusher:ping" -> webSocket.send("""{"event":"pusher:pong","data":{}}""")

                "pusher:error" -> webSocket.close(NORMAL_CLOSURE, null)

                EVENT -> deliver(frame)
            }
        }

        override fun onOpen(webSocket: WebSocket, response: Response) {
            attempt = 0
        }

        override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
            reconnect()
        }

        override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
            if (!stopping) reconnect()
        }

        private fun subscribe(webSocket: WebSocket, frame: JSONObject) {
            thread(name = "nobat-subscribe") {
                val socketId = JSONObject(frame.getString("data")).getString("socket_id")
                val channel = "private-driver.${config.driverId}"

                val auth = try {
                    Api.channelAuth(channel, socketId, config.csrf, cookie)
                } catch (_: Exception) {
                    null
                }

                if (auth == null) {
                    // امضا نگرفتیم یعنی نشست تمام شده؛ سر زدنِ دوره‌ای
                    // کارش را می‌کند تا راننده دوباره وارد شود
                    stopSelf()
                    return@thread
                }

                webSocket.send(
                    JSONObject()
                        .put("event", "pusher:subscribe")
                        .put(
                            "data",
                            JSONObject().put("channel", channel).put("auth", auth),
                        )
                        .toString(),
                )
            }
        }

        private fun deliver(frame: JSONObject) {
            val payload = try {
                JSONObject(frame.getString("data"))
            } catch (_: Exception) {
                return
            }

            if (!Notifier.show(applicationContext, payload)) return

            // رسید همان‌جا: وگرنه سر زدنِ بعدی همین را دوباره می‌آورد
            thread(name = "nobat-ack") {
                val cookie = Api.cookie() ?: return@thread

                try {
                    Api.acknowledge(listOf(payload.optInt("id")), config.csrf, cookie)
                } catch (_: Exception) {
                    // نرسید؛ سر زدنِ دوره‌ای دوباره تلاش می‌کند
                }
            }
        }
    }

    companion object {
        private const val SERVICE_NOTIFICATION = 1
        private const val NORMAL_CLOSURE = 1000
        private const val EVENT = "driver.notification"

        fun start(context: Context) {
            val intent = Intent(context, RealtimeService::class.java)

            try {
                ContextCompat_startForegroundService(context, intent)
            } catch (_: Exception) {
                // اندروید اجازه‌ی شروع از پس‌زمینه را نداد؛ دفعه‌ی بعد که
                // راننده برنامه را باز کند شروع می‌شود
            }
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, RealtimeService::class.java))
        }

        private fun ContextCompat_startForegroundService(context: Context, intent: Intent) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }
    }
}
