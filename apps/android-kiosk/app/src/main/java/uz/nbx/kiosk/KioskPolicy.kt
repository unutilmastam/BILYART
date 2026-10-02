package uz.nbx.kiosk

import android.Manifest
import android.app.Activity
import android.app.ActivityManager
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.BatteryManager
import android.os.Build
import android.os.UserManager
import android.provider.Settings
import android.util.Log

/**
 * Device lock-down. Two levels:
 *  - Device Owner (installed by QR provisioning on a factory-reset tablet): full kiosk — Lock Task with no
 *    Home/Recents/notifications/status bar, this app is the launcher (starts after every reboot), no lock
 *    screen, screen stays on while charging, camera permission pre-granted, no safe boot.
 *  - Otherwise: Android screen pinning (the user confirms once) — the same as the PWA + App pinning setup.
 * Leaving is only possible through the hidden admin gesture + PIN (MainActivity).
 */
class KioskPolicy(private val context: Context) {
    private val dpm = context.getSystemService(DevicePolicyManager::class.java)
    private val am = context.getSystemService(ActivityManager::class.java)
    private val admin = ComponentName(context, AdminReceiver::class.java)

    val isDeviceOwner: Boolean get() = dpm.isDeviceOwnerApp(context.packageName)

    /** Idempotent; called on every start so a policy lost by an OS update is re-applied. */
    fun applyDeviceOwnerPolicies() {
        if (!isDeviceOwner) return
        runCatching {
            dpm.setLockTaskPackages(admin, arrayOf(context.packageName))
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                // Nothing but our screen: no Home, Recents, notifications, power menu or status bar info.
                dpm.setLockTaskFeatures(admin, DevicePolicyManager.LOCK_TASK_FEATURE_NONE)
            }
            val home = IntentFilter(Intent.ACTION_MAIN).apply {
                addCategory(Intent.CATEGORY_HOME)
                addCategory(Intent.CATEGORY_DEFAULT)
            }
            dpm.addPersistentPreferredActivity(admin, home, ComponentName(context, MainActivity::class.java))
            dpm.setKeyguardDisabled(admin, true)
            dpm.setStatusBarDisabled(admin, true)
            val plugged = BatteryManager.BATTERY_PLUGGED_AC or BatteryManager.BATTERY_PLUGGED_USB or BatteryManager.BATTERY_PLUGGED_WIRELESS
            dpm.setGlobalSetting(admin, Settings.Global.STAY_ON_WHILE_PLUGGED_IN, plugged.toString())
            dpm.setPermissionGrantState(admin, context.packageName, Manifest.permission.CAMERA, DevicePolicyManager.PERMISSION_GRANT_STATE_GRANTED)
            dpm.addUserRestriction(admin, UserManager.DISALLOW_SAFE_BOOT)
        }.onFailure { Log.e(TAG, "applying device owner policies failed", it) }
    }

    fun isLocked(): Boolean = am.lockTaskModeState != ActivityManager.LOCK_TASK_MODE_NONE

    /** Device Owner: silent full lock. Otherwise Android asks once to pin the screen. */
    fun enterLockTask(activity: Activity) {
        if (isLocked()) return
        runCatching { activity.startLockTask() }.onFailure { Log.w(TAG, "startLockTask failed", it) }
    }

    fun exitLockTask(activity: Activity) {
        runCatching { activity.stopLockTask() }.onFailure { Log.w(TAG, "stopLockTask failed", it) }
    }

    /** Returns the tablet to a normal Android device (keeps the app installed). */
    fun removeKioskMode(activity: Activity) {
        exitLockTask(activity)
        if (!isDeviceOwner) return
        runCatching {
            dpm.clearPackagePersistentPreferredActivities(admin, context.packageName)
            dpm.setStatusBarDisabled(admin, false)
            dpm.setKeyguardDisabled(admin, false)
            dpm.setGlobalSetting(admin, Settings.Global.STAY_ON_WHILE_PLUGGED_IN, "0")
            dpm.clearUserRestriction(admin, UserManager.DISALLOW_SAFE_BOOT)
            dpm.setLockTaskPackages(admin, arrayOf())
            dpm.clearDeviceOwnerApp(context.packageName)
        }.onFailure { Log.e(TAG, "removing kiosk mode failed", it) }
    }

    companion object {
        private const val TAG = "NbxKiosk"
    }
}
