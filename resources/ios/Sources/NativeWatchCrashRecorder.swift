import Foundation
import KSCrashRecording
import KSCrashRecordingCore

/// KSCrash owns native exception/signal handlers and optimized-stack unwinding.
/// NativeWatch owns consent, the allowlisted report shape and delivery.
enum NativeWatchCrashRecorder {
    private static var installed = false
    private static var activeGeneration: String?

    static var directory: URL {
        NativeWatchCrashReporter.directory.deletingLastPathComponent().appendingPathComponent("recorder", isDirectory: true)
    }

    static func activate(_ generation: String) -> Bool {
        if activeGeneration == generation { return true }
        guard NativeWatchCrashAdmission.enable(generation) else { return false }
        if !installed {
            let config = KSCrashConfiguration()
            config.installPath = directory.path
            config.monitors = [.machException, .signal, .nsException]
            config.enableMemoryIntrospection = false
            config.enableQueueNameSearch = false
            config.addConsoleLogToReport = false
            config.printPreviousLogOnStartup = false
            config.enableSwapCxaThrow = false
            config.enableHangReporting = false
            config.enableCPUExceptionReporting = false
            config.enableCompactBinaryImages = true
            config.reportStoreConfiguration.maxReportCount = 20
            config.willWriteReportCallback = { plan, _ in NativeWatchCrashAdmission.admit(plan) }
            config.isWritingReportCallback = { _, writer in NativeWatchCrashAdmission.write(writer) }
            do {
                try KSCrash.shared.install(with: config)
                installed = true
            } catch {
                NativeWatchCrashAdmission.disable()
                return false
            }
        } else if !kscm_enableMonitors() {
            NativeWatchCrashAdmission.disable()
            return false
        }
        activeGeneration = generation
        collect()
        return true
    }

    static func disableAndClear() -> Bool {
        NativeWatchCrashAdmission.disable()
        activeGeneration = nil
        if installed {
            kscm_disableAllMonitors()
            KSCrash.shared.reportStore?.deleteAllReports()
            if KSCrash.shared.reportStore?.reportCount != 0 { return false }
        }
        do {
            for name in ["Reports", "Sidecars", "RunSidecars"] {
                let url = directory.appendingPathComponent(name, isDirectory: true)
                if FileManager.default.fileExists(atPath: url.path) {
                    for child in try FileManager.default.contentsOfDirectory(at: url, includingPropertiesForKeys: nil) {
                        try FileManager.default.removeItem(at: child)
                    }
                }
            }
            return true
        } catch {
            return false
        }
    }

    static func collect() {
        NativeWatchConsent.withPermission { generation in
            guard let store = KSCrash.shared.reportStore else { return }
            for id in store.reportIDs.prefix(20) {
                guard let raw = store.report(for: id.int64Value)?.value,
                      let report = NativeWatchRecordedCrash.report(raw, generation: generation) else {
                    store.deleteReport(with: id.int64Value)
                    continue
                }
                if NativeWatchCrashReporter.store(report) {
                    store.deleteReport(with: id.int64Value)
                }
            }
        }
    }
}
