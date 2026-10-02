plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// Built only by GitHub Actions (CLAUDE.md §0). Inputs come from Gradle properties / environment:
//   -PkioskUrl=https://<domain>/tablet/   the PWA this shell locks onto (default: the live platform)
//   -PversionName=1.0.6 -PversionCode=42
//   KIOSK_KEYSTORE_FILE / KIOSK_KEYSTORE_PASSWORD   release signing key (GitHub secret). Without it the
//   release build is signed with the debug key — fine for CI, never for tablets (updates need one key).
val kioskUrl = (findProperty("kioskUrl") as String?) ?: "https://nbx.itcode.uz/tablet/"
require(kioskUrl.startsWith("https://") && kioskUrl.endsWith("/")) { "kioskUrl must be https://… and end with /" }
val keystoreFile = System.getenv("KIOSK_KEYSTORE_FILE")?.takeIf { it.isNotBlank() }
val keystorePassword = System.getenv("KIOSK_KEYSTORE_PASSWORD")

android {
    namespace = "uz.nbx.kiosk"
    compileSdk = 35

    defaultConfig {
        applicationId = "uz.nbx.kiosk"
        minSdk = 26
        targetSdk = 35
        versionCode = ((findProperty("versionCode") as String?) ?: "1").toInt()
        versionName = (findProperty("versionName") as String?) ?: "0.0.0-dev"
        buildConfigField("String", "KIOSK_URL", "\"$kioskUrl\"")
    }

    signingConfigs {
        if (keystoreFile != null) {
            create("release") {
                storeFile = file(keystoreFile)
                storePassword = keystorePassword
                keyAlias = "nbx-kiosk"
                keyPassword = keystorePassword
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.findByName("release") ?: signingConfigs.getByName("debug")
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

    lint {
        abortOnError = true
        warningsAsErrors = false
    }
}
