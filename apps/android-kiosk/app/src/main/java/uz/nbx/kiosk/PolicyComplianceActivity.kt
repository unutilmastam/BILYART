package uz.nbx.kiosk

import android.app.Activity
import android.os.Bundle

/** Last provisioning step (Android 10+): apply the kiosk policies, then let the wizard finish. */
class PolicyComplianceActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        KioskPolicy(this).applyDeviceOwnerPolicies()
        setResult(RESULT_OK)
        finish()
    }
}
