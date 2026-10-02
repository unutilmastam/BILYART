package uz.nbx.kiosk

import android.content.Context
import android.util.Base64
import java.security.MessageDigest
import java.security.SecureRandom
import javax.crypto.SecretKeyFactory
import javax.crypto.spec.PBEKeySpec

/**
 * The admin PIN that unlocks the kiosk menu. Stored only as a salted PBKDF2 hash in the app's private
 * storage (never the PIN itself, never sent anywhere). After 5 wrong tries the menu is locked for 5 minutes.
 */
class AdminPin(context: Context) {
    private val prefs = context.getSharedPreferences("admin_pin", Context.MODE_PRIVATE)

    val isSet: Boolean get() = prefs.contains(KEY_HASH)

    fun set(pin: String) {
        require(isValid(pin))
        val salt = ByteArray(16).also { SecureRandom().nextBytes(it) }
        prefs.edit()
            .putString(KEY_SALT, b64(salt))
            .putString(KEY_HASH, b64(hash(pin, salt)))
            .putInt(KEY_FAILS, 0)
            .putLong(KEY_LOCKED_UNTIL, 0)
            .apply()
    }

    /** Minutes left in the lock-out, or 0 when a try is allowed. */
    fun lockedMinutes(now: Long = System.currentTimeMillis()): Int {
        val until = prefs.getLong(KEY_LOCKED_UNTIL, 0)
        return if (until > now) (((until - now) / 60_000) + 1).toInt() else 0
    }

    fun check(pin: String, now: Long = System.currentTimeMillis()): Boolean {
        if (lockedMinutes(now) > 0) return false
        val salt = prefs.getString(KEY_SALT, null)?.let { unb64(it) } ?: return false
        val expected = prefs.getString(KEY_HASH, null)?.let { unb64(it) } ?: return false
        val ok = MessageDigest.isEqual(expected, hash(pin, salt))
        if (ok) {
            prefs.edit().putInt(KEY_FAILS, 0).apply()
        } else {
            val fails = prefs.getInt(KEY_FAILS, 0) + 1
            val edit = prefs.edit().putInt(KEY_FAILS, fails)
            if (fails >= MAX_FAILS) edit.putInt(KEY_FAILS, 0).putLong(KEY_LOCKED_UNTIL, now + LOCK_MS)
            edit.apply()
        }
        return ok
    }

    companion object {
        private const val KEY_HASH = "hash"
        private const val KEY_SALT = "salt"
        private const val KEY_FAILS = "fails"
        private const val KEY_LOCKED_UNTIL = "lockedUntil"
        private const val MAX_FAILS = 5
        private const val LOCK_MS = 5 * 60_000L
        private const val ITERATIONS = 120_000

        fun isValid(pin: String): Boolean = pin.length in 4..8 && pin.all { it in '0'..'9' }

        private fun hash(pin: String, salt: ByteArray): ByteArray {
            val spec = PBEKeySpec(pin.toCharArray(), salt, ITERATIONS, 256)
            try {
                return SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256").generateSecret(spec).encoded
            } finally {
                spec.clearPassword()
            }
        }

        private fun b64(bytes: ByteArray) = Base64.encodeToString(bytes, Base64.NO_WRAP)
        private fun unb64(s: String) = Base64.decode(s, Base64.NO_WRAP)
    }
}
