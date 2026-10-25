import Foundation

/// Bridge functions that the PHP side of zx-mobile calls through `nativephp_call()`.
enum ZXGuardFunctions {
    class PendingCrashReports: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            BridgeResponse.success(data: ["reports": ZXGuardCrashReporter.pendingReports()])
        }
    }

    class DeleteCrashReports: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let ids = parameters["ids"] as? [String] ?? []
            ZXGuardCrashReporter.delete(ids: ids)

            return BridgeResponse.success(data: ["deleted": ids.count])
        }
    }

    class TestCrash: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            DispatchQueue.main.async {
                NSException(
                    name: .internalInconsistencyException,
                    reason: "This is a test crash from ZXGuard.",
                    userInfo: nil
                ).raise()
            }

            return BridgeResponse.success(data: [:])
        }
    }
}
