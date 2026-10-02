package uz.nbx.kiosk

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * Brings the kiosk back after a reboot or an app update. As Device Owner the app is also the launcher,
 * so this is a second path; Android may ignore it for a non-owner app (background start limits).
 */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED && intent.action != Intent.ACTION_MY_PACKAGE_REPLACED) return
        runCatching {
            context.startActivity(Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        }
    }
}
