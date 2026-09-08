package ir.nobatdehi.driver

import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.webkit.CookieManager
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.LinearLayout
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout

/**
 * پوسته‌ی برنامه‌ی راننده.
 *
 * عمداً یک WebView ساده و نه Capacitor یا TWA:
 *
 *  • TWA به نصب‌بودنِ Chrome وابسته است. روی گوشیِ راننده‌ای که سالی یک بار
 *    به‌روزرسانی می‌شود، این فرض خطرناکی است.
 *  • Capacitor یک زنجیره‌ی ابزار کامل می‌آورد برای چیزی که پنل راننده اصلاً
 *    لازم ندارد — نه دوربین، نه GPS، نه فایل. فقط صفحه و پیامک.
 *
 * پس همان چند رفتاری که واقعاً لازم است، دستی نوشته شده.
 */
class MainActivity : AppCompatActivity() {

    private lateinit var web: WebView
    private lateinit var refresh: SwipeRefreshLayout
    private lateinit var offline: LinearLayout

    /** خطای شبکه فقط برای خودِ صفحه معنا دارد، نه برای یک تصویر یا اسکریپت */
    private var pageFailed = false

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        web = findViewById(R.id.web)
        refresh = findViewById(R.id.refresh)
        offline = findViewById(R.id.offline)

        with(web.settings) {
            javaScriptEnabled = true

            // نشست و Service Worker هر دو به این وابسته‌اند
            domStorageEnabled = true
            databaseEnabled = true

            cacheMode = WebSettings.LOAD_DEFAULT
            useWideViewPort = true
            loadWithOverviewMode = true

            // راننده با دستکش و زیر آفتاب کار می‌کند؛ بزرگ‌نمایی را نمی‌بندیم
            setSupportZoom(true)
            builtInZoomControls = true
            displayZoomControls = false
        }

        /*
         * کوکی‌ها باید بمانند.
         *
         * بدون flush، بستنِ برنامه نشست را می‌برد و راننده هر بار باید
         * دوباره کد پیامکی بگیرد — همان چیزی که قرار بود حذف شود.
         */
        CookieManager.getInstance().setAcceptCookie(true)
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, false)

        web.webViewClient = Client()

        refresh.setOnRefreshListener { web.reload() }

        findViewById<Button>(R.id.retry).setOnClickListener {
            showOffline(false)
            web.loadUrl(startUrl())
        }

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (web.canGoBack()) web.goBack() else finish()
            }
        })

        if (savedInstanceState == null) {
            web.loadUrl(intent?.data?.toString()?.takeIf { insideApp(it.toUri()) } ?: startUrl())
        } else {
            web.restoreState(savedInstanceState)
        }
    }

    /** لینکِ پیامک وقتی برنامه از قبل باز است */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)

        intent.data?.let { if (insideApp(it)) web.loadUrl(it.toString()) }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        web.saveState(outState)
    }

    override fun onPause() {
        super.onPause()

        // همین‌جا نوشته می‌شود، نه وقتی سیستم برنامه را کشت
        CookieManager.getInstance().flush()
    }

    private fun startUrl() = BuildConfig.SITE_URL + BuildConfig.START_PATH

    /** فقط صفحه‌های خودِ سامانه داخل برنامه باز می‌شوند */
    private fun insideApp(uri: Uri): Boolean =
        uri.scheme in setOf("http", "https") && uri.host == BuildConfig.SITE_HOST

    private fun String.toUri(): Uri = Uri.parse(this)

    private fun showOffline(show: Boolean) {
        offline.visibility = if (show) View.VISIBLE else View.GONE
        refresh.visibility = if (show) View.GONE else View.VISIBLE
    }

    private fun openOutside(uri: Uri) {
        try {
            startActivity(Intent(Intent.ACTION_VIEW, uri).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        } catch (_: ActivityNotFoundException) {
            // برنامه‌ای برای این نوع لینک نصب نیست؛ سکوت بهتر از کرش است
        }
    }

    private inner class Client : WebViewClient() {

        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            val uri = request.url

            if (insideApp(uri)) return false

            /*
             * هر چیز دیگری بیرون باز می‌شود: tel: و sms: که کار خودشان را
             * دارند، و لینکِ دامنه‌ی دیگر که نباید کاربر را داخل پوسته‌ای
             * بدون نوار آدرس گیر بیندازد.
             */
            openOutside(uri)

            return true
        }

        override fun onPageStarted(view: WebView, url: String, favicon: Bitmap?) {
            pageFailed = false
        }

        override fun onPageFinished(view: WebView, url: String) {
            refresh.isRefreshing = false

            if (!pageFailed) showOffline(false)
        }

        override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
            // خطای یک تصویر نباید کل صفحه را «آفلاین» نشان دهد
            if (!request.isForMainFrame) return

            pageFailed = true
            refresh.isRefreshing = false
            showOffline(true)
        }
    }
}
