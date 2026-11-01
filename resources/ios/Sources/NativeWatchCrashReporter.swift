import Foundation
import MetricKit

/// Captures native crashes and keeps them on disk until PHP sends them to NativeWatch.
///
/// NativePHP calls `install()` when the app starts (`ios.init_function` in nativephp.json).
/// A maintained native recorder captures Objective-C exceptions and interrupted
/// signal/Mach contexts. Reports are imported and sent on the next launch.
/// MetricKit adds hangs and the crashes that no handler saw.
enum NativeWatchCrashReporter {
    private static let maxReports = 20
    private static var installed = false
    private static let metricSubscriber = NativeWatchMetricSubscriber()
    private static var metricsInstalled = false

    static var directory: URL {
        FileManager.default
            .urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("nativewatch/crashes", isDirectory: true)
    }

    static func install() {
        guard !installed else { return }
        installed = true

        if NativeWatchConsent.generation == nil { _ = disable() } else { _ = activate() }
    }

    static func activate() -> Bool {
        NativeWatchConsent.withPermission { generation in
            guard NativeWatchCrashRecorder.activate(generation) else { return false }
            if !metricsInstalled {
                metricsInstalled = true
                MXMetricManager.shared.add(metricSubscriber)
            }
            return true
        } ?? false
    }

    static func disable() -> Bool {
        if metricsInstalled {
            MXMetricManager.shared.remove(metricSubscriber)
            metricsInstalled = false
        }
        return NativeWatchCrashRecorder.disableAndClear()
    }

    // MARK: - Recording

    /// The main thread was blocked so long that the user saw a frozen app.
    static func record(hang: MXHangDiagnostic, at date: Date) {
        let seconds = hang.hangDuration.converted(to: .seconds).value

        store(report(
            type: "AppHang",
            value: String(format: "The main thread was blocked for %.1f seconds.", seconds),
            frames: frames(from: hang.callStackTree),
            mechanism: "ios.metrickit.hang",
            occurredAt: date,
            release: hang.metaData.applicationBuildVersion,
            extra: ["hang_seconds": seconds]
        ))
    }

    static func record(diagnostic: MXCrashDiagnostic, at date: Date) {
        var type = [
            diagnostic.exceptionType.map { machExceptionName($0.int32Value) },
            diagnostic.signal.map { signalName($0.int32Value) },
        ].compactMap { $0 }.joined(separator: " / ")
        var value = diagnostic.terminationReason ?? ""

        if let reason = diagnostic.exceptionReason {
            type = reason.exceptionName
            value = reason.composedMessage
        }

        store(report(
            type: type.isEmpty ? "Crash" : type,
            value: value,
            frames: frames(from: diagnostic.callStackTree),
            mechanism: "ios.metrickit",
            occurredAt: date,
            release: diagnostic.metaData.applicationBuildVersion,
            extra: [
                "os_version": diagnostic.metaData.osVersion,
                "device_type": diagnostic.metaData.deviceType,
            ]
        ))
    }

    // MARK: - Reports on disk

    static func pendingReports() -> [[String: String]] {
        guard let generation = NativeWatchConsent.generation else { return [] }
        NativeWatchCrashRecorder.collect()
        return reportFiles().prefix(maxReports).compactMap { url in
            guard let data = try? Data(contentsOf: url),
                  let report = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  report["_nativewatch_consent_generation"] as? String == generation,
                  let json = String(data: data, encoding: .utf8) else { return nil }
            return ["id": url.deletingPathExtension().lastPathComponent, "json": json]
        }
    }

    static func delete(ids: [String]) {
        for id in ids where !id.contains("/") {
            try? FileManager.default.removeItem(at: directory.appendingPathComponent("\(id).json"))
        }
    }

    @discardableResult
    static func store(_ report: [String: Any]) -> Bool {
        NativeWatchConsent.withPermission { generation in
            var report = report
            if let original = report["_nativewatch_consent_generation"] as? String, original != generation { return false }
            report["_nativewatch_consent_generation"] = generation
            guard let id = report["event_id"] as? String,
                  let data = try? JSONSerialization.data(withJSONObject: report) else { return false }
            do {
                try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
                try data.write(to: directory.appendingPathComponent("\(id).json"), options: .atomic)
                for url in reportFiles().dropLast(maxReports) { try? FileManager.default.removeItem(at: url) }
                return true
            } catch { return false }
        } ?? false
    }

    private static func reportFiles() -> [URL] {
        let files = (try? FileManager.default.contentsOfDirectory(
            at: directory,
            includingPropertiesForKeys: [.creationDateKey]
        )) ?? []

        return files
            .filter { $0.pathExtension == "json" }
            .sorted { creationDate($0) < creationDate($1) }
    }

    private static func creationDate(_ url: URL) -> Date {
        (try? url.resourceValues(forKeys: [.creationDateKey]).creationDate) ?? .distantPast
    }

    // MARK: - Building a report

    static func report(
        type: String,
        value: String,
        frames: [[String: Any]],
        mechanism: String,
        occurredAt: Date = Date(),
        release: String? = nil,
        extra: [String: Any] = [:]
    ) -> [String: Any] {
        var crash: [String: Any] = ["mechanism": mechanism, "handled": false]
        crash.merge(extra) { current, _ in current }

        var report: [String: Any] = [
            "event_id": UUID().uuidString.lowercased(),
            "timestamp": ISO8601DateFormatter().string(from: occurredAt),
            "level": "fatal",
            "exception": ["type": type, "value": value, "frames": Array(frames.prefix(100))],
            "context": ["crash": crash],
        ]

        if let release = release ?? Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String {
            report["release"] = release
        }

        return report
    }

    private static var appBinaryName: String {
        Bundle.main.infoDictionary?["CFBundleExecutable"] as? String ?? ""
    }

    /// Debug builds of Xcode move the code of the app into "<executable>.debug.dylib".
    static func isAppBinary(_ binary: String) -> Bool {
        binary == appBinaryName || binary == "\(appBinaryName).debug.dylib"
    }

    /// Flattens the call stack tree of the crashed thread into frames, the crash site first.
    private static func frames(from tree: MXCallStackTree) -> [[String: Any]] {
        guard let json = try? JSONSerialization.jsonObject(with: tree.jsonRepresentation()) as? [String: Any] else { return [] }
        let root = json["callStackTree"] as? [String: Any] ?? json
        guard let stacks = root["callStacks"] as? [[String: Any]] else { return [] }
        let architectures = NativeWatchImages.architectures()

        let crashed = stacks.first { $0["threadAttributed"] as? Bool == true } ?? stacks.first
        var frames: [[String: Any]] = []
        var next = (crashed?["callStackRootFrames"] as? [[String: Any]])?.first

        while let frame = next, frames.count < 100 {
            let binary = frame["binaryName"] as? String ?? "unknown"
            let offset = frame["offsetIntoBinaryTextSegment"] as? UInt64

            // The offset stays the same between launches of one build, the address does not (ASLR).
            var value: [String: Any] = [
                "file": binary,
                "line": 0,
                "function": offset.map { "\(binary) + \($0)" } ?? binary,
                "in_app": isAppBinary(binary),
            ]
            if let offset, offset <= 0xffffffffffff,
               let id = frame["binaryUUID"] as? String, let uuid = UUID(uuidString: id),
               let architecture = architectures[uuid] {
                value["native"] = [
                    "image_uuid": uuid.uuidString.lowercased(), "architecture": architecture.rawValue,
                    "instruction_offset": String(format: "0x%llx", offset),
                    "address_kind": frames.isEmpty ? "instruction" : "return_address",
                ]
            }
            frames.append(value)

            next = (frame["subFrames"] as? [[String: Any]])?.first
        }

        return frames
    }

    private static func machExceptionName(_ code: Int32) -> String {
        switch code {
        case 1: return "EXC_BAD_ACCESS"
        case 2: return "EXC_BAD_INSTRUCTION"
        case 3: return "EXC_ARITHMETIC"
        case 5: return "EXC_SOFTWARE"
        case 6: return "EXC_BREAKPOINT"
        case 10: return "EXC_CRASH"
        case 11: return "EXC_RESOURCE"
        case 12: return "EXC_GUARD"
        default: return "EXC_\(code)"
        }
    }

    static func signalName(_ signal: Int32) -> String {
        switch signal {
        case SIGABRT: return "SIGABRT"
        case SIGSEGV: return "SIGSEGV"
        case SIGBUS: return "SIGBUS"
        case SIGILL: return "SIGILL"
        case SIGFPE: return "SIGFPE"
        case SIGTRAP: return "SIGTRAP"
        case SIGKILL: return "SIGKILL"
        default: return "SIG\(signal)"
        }
    }
}

/// Receives the crash diagnostics that iOS delivers on the next launch.
final class NativeWatchMetricSubscriber: NSObject, MXMetricManagerSubscriber {
    func didReceive(_ payloads: [MXDiagnosticPayload]) {
        for payload in payloads {
            NativeWatchConsent.withPermission { _ in
                guard NativeWatchConsent.accepts(intervalBeginning: payload.timeStampBegin) else { return }
                for crash in payload.crashDiagnostics ?? [] {
                    NativeWatchCrashReporter.record(diagnostic: crash, at: payload.timeStampEnd)
                }
                for hang in payload.hangDiagnostics ?? [] {
                    NativeWatchCrashReporter.record(hang: hang, at: payload.timeStampEnd)
                }
            }
        }
    }
}
