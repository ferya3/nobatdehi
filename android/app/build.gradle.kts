import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val props = Properties().apply {
    rootProject.file("gradle.properties").inputStream().use { load(it) }
}

fun prop(key: String): String = (project.findProperty(key) ?: props.getProperty(key)) as String

/*
 * کلید امضا از یک فایل بیرونی می‌آید و نه از داخل مخزن.
 *
 * کلیدِ امضا در گیت یعنی هر کسی می‌تواند نسخه‌ای بسازد که اندروید آن را
 * به‌روزرسانیِ همین برنامه بداند. اگر فایل نباشد، خروجی debug ساخته می‌شود.
 */
val keystoreFile = rootProject.file("keystore.properties")
val keystore = Properties().apply {
    if (keystoreFile.exists()) keystoreFile.inputStream().use { load(it) }
}

android {
    namespace = "ir.nobatdehi.driver"
    compileSdk = 35

    defaultConfig {
        applicationId = "ir.nobatdehi.driver"
        minSdk = 24
        targetSdk = 35
        versionCode = prop("NOBAT_VERSION_CODE").toInt()
        versionName = prop("NOBAT_VERSION_NAME")

        // آدرس‌ها از gradle.properties به کد و به AndroidManifest می‌روند
        buildConfigField("String", "SITE_URL", "\"${prop("NOBAT_URL")}\"")
        buildConfigField("String", "SITE_HOST", "\"${prop("NOBAT_HOST")}\"")
        buildConfigField("String", "START_PATH", "\"${prop("NOBAT_START_PATH")}\"")

        manifestPlaceholders["siteHost"] = prop("NOBAT_HOST")
        manifestPlaceholders["startPath"] = prop("NOBAT_START_PATH")
    }

    signingConfigs {
        if (keystoreFile.exists()) {
            create("release") {
                storeFile = rootProject.file(keystore.getProperty("storeFile"))
                storePassword = keystore.getProperty("storePassword")
                keyAlias = keystore.getProperty("keyAlias")
                keyPassword = keystore.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")

            if (keystoreFile.exists()) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }

    buildFeatures {
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.swiperefreshlayout:swiperefreshlayout:1.1.0")
    implementation("androidx.webkit:webkit:1.12.1")

    /*
     * بررسی دوره‌ای اعلان‌ها.
     *
     * WorkManager و نه Service یا AlarmManager: تنها راهی که اندروید بعد از
     * ری‌استارت گوشی و زیر Doze هم اجرایش می‌کند، بی‌آنکه برنامه لازم باشد
     * یک اعلانِ دائمیِ «در حال اجرا» روی نوار بگذارد.
     */
    implementation("androidx.work:work-runtime-ktx:2.9.1")
}
