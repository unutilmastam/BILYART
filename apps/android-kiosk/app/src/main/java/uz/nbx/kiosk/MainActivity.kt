package uz.nbx.kiosk

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.app.AlertDialog
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.text.InputType
import android.view.Gravity
import android.view.KeyEvent
import android.view.MotionEvent
import android.view.View
import android.view.ViewGroup
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import android.webkit.PermissionRequest
import android.webkit.RenderProcessGoneDetail
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import android.window.OnBackInvokedDispatcher

/**
 * The hall kiosk: a full-screen WebView locked onto the tablet PWA (BuildConfig.KIOSK_URL).
 * The PWA does all the work (tables, timer, camera + face detection, offline banner); this shell only
 * keeps the customer inside it: no Back/Home/Recents, screen always on, restarts itself, camera allowed
 * only for our own origin, links to other sites are blocked.
 * Staff exit: tap the top-left corner 7 times within 4 s → admin PIN → menu.
 */
class MainActivity : Activity() {
    private lateinit var web: WebView
    private lateinit var offline: TextView
    private val policy by lazy { KioskPolicy(this) }
    private val pin by lazy { AdminPin(this) }
    private val handler = Handler(Looper.getMainLooper())
    private val kioskUri: Uri = Uri.parse(BuildConfig.KIOSK_URL)
    private var pendingCamera: PermissionRequest? = null
    private var cornerTaps = 0
    private var firstTapAt = 0L
    private var dialogOpen = false
    private val reload = Runnable { web.reload() }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        }
        policy.applyDeviceOwnerPolicies()

        web = WebView(this)
        configureWebView(web)
        offline = TextView(this).apply {
            text = getString(R.string.offline_title) + "\n\n" + getString(R.string.offline_body)
            setTextColor(Color.WHITE)
            textSize = 26f
            gravity = Gravity.CENTER
            setBackgroundColor(0xF006352A.toInt())
            visibility = View.GONE
        }
        val corner = View(this).apply { setOnTouchListener { _, e -> onCornerTouch(e); false } }
        val size = (72 * resources.displayMetrics.density).toInt()
        setContentView(FrameLayout(this).apply {
            setBackgroundColor(0xFF06352A.toInt())
            addView(web, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
            addView(offline, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
            addView(corner, FrameLayout.LayoutParams(size, size, Gravity.TOP or Gravity.START))
        })

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            onBackInvokedDispatcher.registerOnBackInvokedCallback(OnBackInvokedDispatcher.PRIORITY_DEFAULT) { /* Back does nothing in the kiosk */ }
        }
        if (!policy.isDeviceOwner && checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(arrayOf(Manifest.permission.CAMERA), REQ_CAMERA)
        }
        if (savedInstanceState != null) web.restoreState(savedInstanceState) else web.loadUrl(BuildConfig.KIOSK_URL)
        if (!pin.isSet) handler.post { askNewPin(cancellable = false) }
    }

    override fun onResume() {
        super.onResume()
        hideSystemUi()
        policy.enterLockTask(this)
        web.onResume()
    }

    override fun onPause() {
        web.onPause()
        super.onPause()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        web.saveState(outState)
    }

    override fun onDestroy() {
        handler.removeCallbacksAndMessages(null)
        web.destroy()
        super.onDestroy()
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus) hideSystemUi()
    }

    // Back (button or gesture) never leaves the kiosk; the PWA has its own on-screen "Orqaga".
    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean =
        if (keyCode == KeyEvent.KEYCODE_BACK) true else super.onKeyDown(keyCode, event)

    override fun onKeyUp(keyCode: Int, event: KeyEvent?): Boolean =
        if (keyCode == KeyEvent.KEYCODE_BACK) true else super.onKeyUp(keyCode, event)

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode != REQ_CAMERA) return
        val request = pendingCamera ?: return
        pendingCamera = null
        if (grantResults.firstOrNull() == PackageManager.PERMISSION_GRANTED) {
            request.grant(arrayOf(PermissionRequest.RESOURCE_VIDEO_CAPTURE))
        } else {
            request.deny()
            showInfo(getString(R.string.camera_needed))
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun configureWebView(view: WebView) {
        view.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            mediaPlaybackRequiresUserGesture = false
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            allowFileAccess = false
            allowContentAccess = false
            setSupportMultipleWindows(false)
            javaScriptCanOpenWindowsAutomatically = false
            setSupportZoom(false)
            builtInZoomControls = false
            textZoom = 100
            userAgentString = "$userAgentString NBXKiosk/${BuildConfig.VERSION_NAME}"
        }
        // No text selection / "Share" / "Web search" pop-ups that could open other apps.
        view.isLongClickable = false
        view.setOnLongClickListener { true }
        view.isHapticFeedbackEnabled = false
        view.overScrollMode = View.OVER_SCROLL_NEVER

        view.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(v: WebView, request: WebResourceRequest): Boolean =
                !isKioskUrl(request.url) // anything outside the platform's /tablet/ is blocked

            override fun onPageFinished(v: WebView, url: String) {
                handler.removeCallbacks(reload)
                offline.visibility = View.GONE
            }

            override fun onReceivedError(v: WebView, request: WebResourceRequest, error: WebResourceError) {
                if (!request.isForMainFrame) return
                offline.visibility = View.VISIBLE
                handler.removeCallbacks(reload)
                handler.postDelayed(reload, RETRY_MS)
            }

            override fun onRenderProcessGone(v: WebView, detail: RenderProcessGoneDetail): Boolean {
                recreate() // the page crashed or was killed for memory: start a fresh WebView
                return true
            }
        }
        view.webChromeClient = object : WebChromeClient() {
            override fun onPermissionRequest(request: PermissionRequest) {
                runOnUiThread { handleCameraRequest(request) }
            }
        }
    }

    /** Camera only for the kiosk origin and only the camera (no microphone, no other resources). */
    private fun handleCameraRequest(request: PermissionRequest) {
        val wantsCameraOnly = request.resources.all { it == PermissionRequest.RESOURCE_VIDEO_CAPTURE }
        if (!wantsCameraOnly || !isKioskOrigin(request.origin)) {
            request.deny()
            return
        }
        if (checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
            request.grant(arrayOf(PermissionRequest.RESOURCE_VIDEO_CAPTURE))
        } else {
            pendingCamera?.deny()
            pendingCamera = request
            requestPermissions(arrayOf(Manifest.permission.CAMERA), REQ_CAMERA)
        }
    }

    private fun isKioskOrigin(uri: Uri): Boolean =
        uri.scheme == "https" && uri.host == kioskUri.host && uri.port == kioskUri.port

    private fun isKioskUrl(uri: Uri): Boolean =
        isKioskOrigin(uri) && (uri.path ?: "").startsWith(kioskUri.path ?: "/")

    private fun hideSystemUi() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            window.setDecorFitsSystemWindows(false)
            window.insetsController?.let {
                it.hide(WindowInsets.Type.statusBars() or WindowInsets.Type.navigationBars())
                it.systemBarsBehavior = WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            }
        } else {
            @Suppress("DEPRECATION")
            window.decorView.systemUiVisibility = (View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY or View.SYSTEM_UI_FLAG_FULLSCREEN
                or View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                or View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION or View.SYSTEM_UI_FLAG_LAYOUT_STABLE)
        }
    }

    // ---- Hidden staff gesture + PIN ----

    private fun onCornerTouch(e: MotionEvent) {
        if (e.actionMasked != MotionEvent.ACTION_DOWN || dialogOpen) return
        val now = System.currentTimeMillis()
        if (now - firstTapAt > TAP_WINDOW_MS) {
            firstTapAt = now
            cornerTaps = 0
        }
        cornerTaps++
        if (cornerTaps >= TAPS_TO_OPEN) {
            cornerTaps = 0
            askPin()
        }
    }

    private fun pinField(): EditText = EditText(this).apply {
        inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD
        textSize = 28f
        gravity = Gravity.CENTER
    }

    private fun box(vararg views: View): LinearLayout = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL
        val pad = (24 * resources.displayMetrics.density).toInt()
        setPadding(pad, pad / 2, pad, 0)
        views.forEach { addView(it) }
    }

    private fun dialog(builder: AlertDialog.Builder): AlertDialog {
        dialogOpen = true
        return builder.setOnDismissListener { dialogOpen = false; hideSystemUi() }.show()
    }

    private fun askNewPin(cancellable: Boolean) {
        val first = pinField()
        val second = pinField().apply { hint = getString(R.string.pin_repeat) }
        val hint = TextView(this).apply { text = getString(R.string.pin_set_hint) }
        val d = dialog(
            AlertDialog.Builder(this)
                .setTitle(R.string.pin_set_title)
                .setView(box(hint, first, second))
                .setCancelable(cancellable)
                .setPositiveButton(R.string.ok, null)
                .apply { if (cancellable) setNegativeButton(R.string.cancel, null) },
        )
        d.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener {
            val a = first.text.toString()
            when {
                !AdminPin.isValid(a) -> first.error = getString(R.string.pin_invalid)
                a != second.text.toString() -> second.error = getString(R.string.pin_mismatch)
                else -> {
                    pin.set(a)
                    d.dismiss()
                }
            }
        }
    }

    private fun askPin() {
        val locked = pin.lockedMinutes()
        if (locked > 0) {
            showInfo(getString(R.string.pin_locked, locked))
            return
        }
        val field = pinField()
        val d = dialog(
            AlertDialog.Builder(this)
                .setTitle(R.string.pin_enter_title)
                .setView(box(field))
                .setPositiveButton(R.string.ok, null)
                .setNegativeButton(R.string.cancel, null),
        )
        d.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener {
            if (pin.check(field.text.toString())) {
                d.dismiss()
                handler.post { showAdminMenu() }
            } else {
                val wait = pin.lockedMinutes()
                if (wait > 0) {
                    d.dismiss()
                    showInfo(getString(R.string.pin_locked, wait))
                } else {
                    field.text.clear()
                    field.error = getString(R.string.pin_wrong)
                }
            }
        }
    }

    private fun showAdminMenu() {
        val status = getString(
            if (policy.isDeviceOwner) R.string.admin_status_owner else R.string.admin_status_pinned,
            BuildConfig.VERSION_NAME,
        )
        val items = arrayOf(
            getString(R.string.admin_reload),
            getString(R.string.admin_change_pin),
            getString(R.string.admin_settings),
            getString(R.string.admin_exit),
            getString(R.string.admin_remove),
        )
        dialog(
            AlertDialog.Builder(this)
                .setTitle(getString(R.string.admin_menu_title) + "\n" + status)
                .setItems(items) { _, which ->
                    when (which) {
                        0 -> web.reload()
                        1 -> handler.post { askNewPin(cancellable = true) }
                        2 -> {
                            policy.exitLockTask(this)
                            startActivity(Intent(Settings.ACTION_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                        }
                        3 -> policy.exitLockTask(this) // locks again the next time the kiosk comes to the front
                        4 -> handler.post { confirmRemove() }
                    }
                }
                .setNegativeButton(R.string.cancel, null),
        )
    }

    private fun confirmRemove() {
        dialog(
            AlertDialog.Builder(this)
                .setTitle(R.string.admin_remove)
                .setMessage(R.string.admin_remove_confirm)
                .setPositiveButton(R.string.ok) { _, _ ->
                    policy.removeKioskMode(this)
                    finishAndRemoveTask()
                }
                .setNegativeButton(R.string.cancel, null),
        )
    }

    private fun showInfo(message: String) {
        dialog(AlertDialog.Builder(this).setMessage(message).setPositiveButton(R.string.ok, null))
    }

    companion object {
        private const val REQ_CAMERA = 1
        private const val RETRY_MS = 10_000L
        private const val TAPS_TO_OPEN = 7
        private const val TAP_WINDOW_MS = 4_000L
    }
}
