package com.zxn.nativewatch.mobile

import android.content.Context
import android.os.Handler
import android.os.Looper
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import org.json.JSONArray

/** Bridge functions that the PHP side of native-mobile calls through `nativephp_call()`. */
object NativeWatchFunctions {
    class GetConsent(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            BridgeResponse.success(mapOf("generation" to (NativeWatchConsent.generation(context) ?: org.json.JSONObject.NULL)))
    }

    class SetConsent(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val granted = parameters["granted"] as? Boolean
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "A consent choice is required.")
            return BridgeResponse.success(mapOf("saved" to NativeWatchConsent.setGranted(context, granted)))
        }
    }

    class PendingCrashReports(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            BridgeResponse.success(mapOf("reports" to NativeWatchCrashReporter.pending(context)))
    }

    class DeleteCrashReports(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val ids = when (val raw = parameters["ids"]) {
                is List<*> -> raw.filterIsInstance<String>()
                is JSONArray -> (0 until raw.length()).map { raw.getString(it) }
                else -> emptyList()
            }

            NativeWatchCrashReporter.delete(context, ids)

            return BridgeResponse.success(mapOf("deleted" to ids.size))
        }
    }

    class TestCrash(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (parameters["kind"] == "fatal") {
                Thread({ throw IllegalStateException("This is a test crash from NativeWatch, on a background thread.") }, "nativewatch-test").start()
            } else {
                Handler(Looper.getMainLooper()).post {
                    throw RuntimeException("This is a test crash from NativeWatch.")
                }
            }

            return BridgeResponse.success()
        }
    }
}
