import Foundation

/// Bridge functions that the PHP side of native-mobile calls through `nativephp_call()`.
enum NativeWatchFunctions {
    class GetConsent: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            BridgeResponse.success(data: ["generation": NativeWatchConsent.generation as Any? ?? NSNull()])
        }
    }

    class SetConsent: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let granted = parameters["granted"] as? Bool else {
                return BridgeResponse.error(code: "INVALID_PARAMETERS", message: "A consent choice is required.")
            }
            return BridgeResponse.success(data: ["saved": NativeWatchConsent.setGranted(granted)])
        }
    }

    class PendingCrashReports: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            BridgeResponse.success(data: ["reports": NativeWatchCrashReporter.pendingReports()])
        }
    }

    class DeleteCrashReports: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let ids = parameters["ids"] as? [String] ?? []
            NativeWatchCrashReporter.delete(ids: ids)

            return BridgeResponse.success(data: ["deleted": ids.count])
        }
    }

    class TestCrash: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let fatal = parameters["kind"] as? String == "fatal"

            DispatchQueue.main.async {
                if fatal {
                    fatalError("This is a test crash from NativeWatch.")
                }

                NSException(
                    name: .internalInconsistencyException,
                    reason: "This is a test crash from NativeWatch.",
                    userInfo: nil
                ).raise()
            }

            return BridgeResponse.success(data: [:])
        }
    }
}
