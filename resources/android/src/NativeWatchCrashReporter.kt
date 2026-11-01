package com.zxn.nativewatch.mobile

import android.app.ActivityManager
import android.app.ApplicationExitInfo
import android.content.Context
import android.os.Build
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.UUID
import java.util.concurrent.atomic.AtomicBoolean

private val installed = AtomicBoolean(false)

/**
 * NativePHP calls this when the app starts (`android.init_function` in nativephp.json).
 *
 * Uncaught JVM exceptions are written the moment they happen, then the previous handler
 * still runs. ANRs and native (NDK) crashes are read from ApplicationExitInfo on the next launch.
 */
fun installNativeWatchCrashReporter(context: Context) {
    if (!installed.compareAndSet(false, true)) return

    val appContext = context.applicationContext
    val previous = Thread.getDefaultUncaughtExceptionHandler()

    Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
        try {
            NativeWatchCrashReporter.record(appContext, throwable, thread)
        } catch (_: Throwable) {
        }

        previous?.uncaughtException(thread, throwable)
    }

    try {
        NativeWatchCrashReporter.recordPastExits(appContext)
    } catch (_: Throwable) {
    }
}

object NativeWatchCrashReporter {
    private const val MAX_REPORTS = 20
    private const val MAX_FRAMES = 100

    fun directory(context: Context): File = File(context.filesDir, "nativewatch/crashes")

    fun record(context: Context, throwable: Throwable, thread: Thread) {
        NativeWatchConsent.withPermission(context) { _ ->
            store(context, report(
                context = context,
                type = throwable.javaClass.name,
                value = throwable.message ?: "",
                frames = frames(context, throwable),
                mechanism = "android.uncaught",
                extra = JSONObject().put("thread", thread.name).put("previous", causes(throwable)),
            ))
        }
    }

    fun recordPastExits(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) return
        NativeWatchConsent.withPermission(context) { collectPastExits(context) }
    }

    private fun collectPastExits(context: Context) {
        val preferences = context.getSharedPreferences("nativewatch", Context.MODE_PRIVATE)
        val lastSeen = preferences.getLong("last_exit_timestamp", 0L)
        val manager = context.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
        val exits = manager.getHistoricalProcessExitReasons(null, 0, 10)
        val newest = exits.maxOfOrNull { it.timestamp } ?: return

        preferences.edit().putLong("last_exit_timestamp", newest).apply()

        if (lastSeen == 0L) return

        exits.filter { it.timestamp > lastSeen && it.timestamp >= NativeWatchConsent.grantedAt(context) }.forEach { exit ->
            val (type, mechanism) = when (exit.reason) {
                ApplicationExitInfo.REASON_ANR -> "ApplicationNotResponding" to "android.anr"
                ApplicationExitInfo.REASON_CRASH_NATIVE -> "NativeCrash" to "android.native"
                else -> return@forEach
            }

            val trace = try {
                exit.traceInputStream?.bufferedReader()?.use { it.readText().take(8_000) } ?: ""
            } catch (_: Throwable) {
                ""
            }

            store(context, report(
                context = context,
                type = type,
                value = exit.description ?: "",
                frames = JSONArray(),
                mechanism = mechanism,
                occurredAt = Date(exit.timestamp),
                extra = JSONObject().put("trace", trace),
            ))
        }
    }

    fun pending(context: Context): List<Map<String, String>> =
        NativeWatchConsent.withPermission(context) { generation ->
            files(context).take(MAX_REPORTS).mapNotNull { file ->
                try {
                    val json = file.readText()
                    if (JSONObject(json).optString("_nativewatch_consent_generation") != generation) null
                    else mapOf("id" to file.nameWithoutExtension, "json" to json)
                } catch (_: Throwable) {
                    null
                }
            }
        } ?: emptyList()

    fun delete(context: Context, ids: List<String>) {
        ids.filter { !it.contains('/') }.forEach { File(directory(context), "$it.json").delete() }
    }

    private fun store(context: Context, report: JSONObject) {
        NativeWatchConsent.withPermission(context) { generation ->
            report.put("_nativewatch_consent_generation", generation)
            val directory = directory(context)
            directory.mkdirs()

            // Write to a temporary file first, so a report that is cut off by the dying process is never read.
            val id = report.getString("event_id")
            val temporary = File(directory, "$id.tmp")
            temporary.writeText(report.toString())
            temporary.renameTo(File(directory, "$id.json"))

            files(context).dropLast(MAX_REPORTS).forEach { it.delete() }
        }
    }


    private fun files(context: Context): List<File> =
        directory(context).listFiles { file -> file.extension == "json" }
            ?.sortedBy { it.lastModified() }
            ?: emptyList()

    private fun report(
        context: Context,
        type: String,
        value: String,
        frames: JSONArray,
        mechanism: String,
        occurredAt: Date = Date(),
        extra: JSONObject = JSONObject(),
    ): JSONObject {
        val crash = JSONObject().put("mechanism", mechanism).put("handled", false)
        extra.keys().forEach { crash.put(it, extra.get(it)) }

        val report = JSONObject()
            .put("event_id", UUID.randomUUID().toString())
            .put("timestamp", iso8601(occurredAt))
            .put("level", "fatal")
            .put("exception", JSONObject().put("type", type).put("value", value).put("frames", frames))
            .put("context", JSONObject().put("crash", crash))

        versionName(context)?.let { report.put("release", it) }

        return report
    }

    private fun frames(context: Context, throwable: Throwable): JSONArray {
        val frames = JSONArray()

        throwable.stackTrace.take(MAX_FRAMES).forEach { element ->
            frames.put(JSONObject()
                .put("file", element.fileName ?: "Unknown")
                .put("line", maxOf(element.lineNumber, 0))
                .put("function", element.methodName)
                .put("class", element.className)
                .put("in_app", element.className.startsWith(context.packageName)))
        }

        return frames
    }

    private fun causes(throwable: Throwable): JSONArray {
        val causes = JSONArray()
        var cause = throwable.cause

        while (cause != null && causes.length() < 5) {
            causes.put(JSONObject().put("type", cause.javaClass.name).put("value", cause.message ?: ""))
            cause = cause.cause
        }

        return causes
    }

    private fun versionName(context: Context): String? = try {
        context.packageManager.getPackageInfo(context.packageName, 0).versionName
    } catch (_: Throwable) {
        null
    }

    private fun iso8601(date: Date): String =
        SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.US)
            .apply { timeZone = TimeZone.getTimeZone("UTC") }
            .format(date)
}
