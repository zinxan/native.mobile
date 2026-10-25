import Foundation

/// Bridge functions that the PHP side of native-mobile calls through `nativephp_call()`.
enum NativeWatchFunctions {
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
            DispatchQueue.main.async {
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
