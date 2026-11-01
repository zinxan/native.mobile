package com.zxn.nativewatch.mobile

import android.content.Context
import org.json.JSONObject
import java.io.File
import java.util.UUID

object NativeWatchConsent {
    private var blocked = false

    private fun file(context: Context) = File(context.filesDir, "nativewatch/consent.json")

    private fun state(context: Context): JSONObject = try {
        JSONObject(file(context).readText())
    } catch (_: Throwable) {
        JSONObject()
    }

    @Synchronized
    fun generation(context: Context): String? {
        if (blocked) return null
        return state(context).optString("generation").takeIf { it.matches(Regex("[a-f0-9]{32}")) }
    }

    @Synchronized
    fun grantedAt(context: Context): Long = state(context).optLong("granted_at", Long.MAX_VALUE)

    @Synchronized
    fun <T> withPermission(context: Context, operation: (String) -> T): T? =
        generation(context)?.let(operation)

    @Synchronized
    fun setGranted(context: Context, granted: Boolean): Boolean {
        if (granted && generation(context) != null) return true
        blocked = true
        return try {
            val target = file(context)
            target.parentFile?.mkdirs()
            write(target, JSONObject())
            val reports = NativeWatchCrashReporter.directory(context)
            if (reports.exists() && !reports.deleteRecursively()) return false
            if (granted) {
                write(target, JSONObject()
                    .put("generation", UUID.randomUUID().toString().replace("-", ""))
                    .put("granted_at", System.currentTimeMillis()))
            }
            blocked = false
            true
        } catch (_: Throwable) {
            false
        }
    }

    private fun write(target: File, value: JSONObject) {
        val temporary = File(target.parentFile, "consent.tmp")
        temporary.writeText(value.toString())
        check(temporary.renameTo(target)) { "Could not persist diagnostic consent" }
    }
}
