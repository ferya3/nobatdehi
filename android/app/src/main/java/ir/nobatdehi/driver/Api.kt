package ir.nobatdehi.driver

import android.webkit.CookieManager
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

/** تنظیماتی که سرور می‌دهد تا برنامه بداند به کجا وصل شود */
data class AppConfig(
    val driverId: Int,
    val csrf: String,
    val realtime: Boolean,
    val reverbKey: String,
    val reverbHost: String,
    val reverbPort: Int,
    val reverbTls: Boolean,
) {
    fun socketUrl(): String {
        val scheme = if (reverbTls) "wss" else "ws"

        return "$scheme://$reverbHost:$reverbPort/app/$reverbKey" +
            "?protocol=7&client=android&version=${BuildConfig.VERSION_NAME}"
    }
}

/**
 * تماس‌های برنامه با سرور.
 *
 * احراز هویت همان کوکیِ نشستِ WebView است و نه یک توکنِ جدا. یعنی راننده
 * یک بار وارد می‌شود و هم صفحه‌ها و هم این تماس‌ها با همان نشست کار
 * می‌کنند؛ خروج از حساب هم هر دو را با هم می‌بندد.
 */
object Api {

    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .build()

    private const val BASE = BuildConfig.SITE_URL

    /** null یعنی راننده وارد نشده — خطا نیست */
    fun cookie(): String? = CookieManager.getInstance().getCookie(BASE)

    fun config(cookie: String): AppConfig? {
        val body = get("$BASE/queue/api/config", cookie) ?: return null
        val json = JSONObject(body)
        val reverb = json.getJSONObject("reverb")

        // بدون کلید، اتصال دائم ممکن نیست — یعنی Reverb روی سرور راه نیست
        if (reverb.optString("key").isEmpty()) return null

        return AppConfig(
            driverId = json.getJSONObject("driver").getInt("id"),
            csrf = json.optString("csrf"),
            realtime = json.optBoolean("realtime"),
            reverbKey = reverb.getString("key"),
            reverbHost = reverb.getString("host"),
            reverbPort = reverb.getInt("port"),
            reverbTls = reverb.optBoolean("tls", true),
        )
    }

    fun pending(cookie: String): Pair<String, JSONArray>? {
        val body = get("$BASE/queue/api/notifications", cookie) ?: return null
        val json = JSONObject(body)

        return json.optString("csrf") to (json.optJSONArray("notifications") ?: JSONArray())
    }

    fun acknowledge(ids: List<Int>, csrf: String, cookie: String) {
        post(
            url = "$BASE/queue/api/notifications/ack",
            body = JSONObject().put("ids", JSONArray(ids)).toString(),
            cookie = cookie,
            csrf = csrf,
        )
    }

    /**
     * امضای عضویت در کانال خصوصی.
     *
     * Reverb اجازه‌ی شنیدن روی driver.{id} را فقط با این امضا می‌دهد، و
     * امضا را همان سروری می‌سازد که نشست را می‌شناسد. یعنی راننده‌ای که
     * socket_id دیگری را حدس بزند هم چیزی نمی‌شنود.
     */
    fun channelAuth(channel: String, socketId: String, csrf: String, cookie: String): String? {
        val body = JSONObject()
            .put("socket_id", socketId)
            .put("channel_name", channel)
            .toString()

        val response = post("$BASE/broadcasting/auth", body, cookie, csrf) ?: return null

        return JSONObject(response).optString("auth").takeIf { it.isNotEmpty() }
    }

    private fun get(url: String, cookie: String): String? {
        val request = Request.Builder()
            .url(url)
            .header("Cookie", cookie)
            .header("Accept", "application/json")
            .build()

        return client.newCall(request).execute().use { response ->
            // ۴۰۱ یعنی نشست تمام شده؛ تلاش دوباره دردی را دوا نمی‌کند
            if (response.isSuccessful) response.body?.string() else null
        }
    }

    private fun post(url: String, body: String, cookie: String, csrf: String): String? {
        val request = Request.Builder()
            .url(url)
            .post(body.toRequestBody("application/json".toMediaType()))
            .header("Cookie", cookie)
            .header("Accept", "application/json")
            .header("X-CSRF-TOKEN", csrf)
            .header("X-Requested-With", "XMLHttpRequest")
            .build()

        return client.newCall(request).execute().use { response ->
            if (response.isSuccessful) response.body?.string() else null
        }
    }
}
