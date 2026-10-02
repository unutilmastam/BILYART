package uz.nbx.kiosk

import android.app.admin.DeviceAdminReceiver
import android.content.Context
import android.content.Intent

/** Device admin component; becomes Device Owner through QR provisioning (docs/TABLET_SETUP.md). */
class AdminReceiver : DeviceAdminReceiver() {
    override fun onProfileProvisioningComplete(context: Context, intent: Intent) {
        KioskPolicy(context).applyDeviceOwnerPolicies()
        context.startActivity(Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }
}
