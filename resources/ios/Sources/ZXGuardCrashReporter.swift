import Foundation
import MetricKit

/// Captures native crashes and keeps them on disk until PHP sends them to ZXGuard.
///
/// NativePHP calls `install()` when the app starts (`ios.init_function` in nativephp.json).
/// Objective-C exceptions are written the moment they happen. Hard crashes, such as a
/// bad memory access or a Swift trap, arrive from MetricKit on the next launch.
enum ZXGuardCrashReporter {
    private static let maxReports = 20
    private static var installed = false
    private static var previousHandler: (@convention(c) (NSException) -> Void)?
    private static let metricSubscriber = ZXGuardMetricSubscriber()

    static var directory: URL {
        FileManager.default
            .urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("zxguard/crashes", isDirectory: true)
    }

    static func install() {
        guard !installed else { return }
        installed = true

        previousHandler = NSGetUncaughtExceptionHandler()
        NSSetUncaughtExceptionHandler { exception in
            ZXGuardCrashReporter.record(exception: exception)
            ZXGuardCrashReporter.previousHandler?(exception)
        }

        MXMetricManager.shared.add(metricSubscriber)
    }

    // MARK: - Recording

    static func record(exception: NSException) {
        store(report(
            type: exception.name.rawValue,
            value: exception.reason ?? "",
            frames: exception.callStackSymbols.map(frame(fromSymbol:)),
            mechanism: "ios.nsexception"
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
        reportFiles().prefix(maxReports).compactMap { url in
            guard let json = try? String(contentsOf: url, encoding: .utf8) else { return nil }
            return ["id": url.deletingPathExtension().lastPathComponent, "json": json]
        }
    }

    static func delete(ids: [String]) {
        for id in ids where !id.contains("/") {
            try? FileManager.default.removeItem(at: directory.appendingPathComponent("\(id).json"))
        }
    }

    private static func store(_ report: [String: Any]) {
        guard let id = report["event_id"] as? String,
              let data = try? JSONSerialization.data(withJSONObject: report) else { return }

        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        try? data.write(to: directory.appendingPathComponent("\(id).json"), options: .atomic)

        for url in reportFiles().dropLast(maxReports) {
            try? FileManager.default.removeItem(at: url)
        }
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

    private static func report(
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
    private static func isAppBinary(_ binary: String) -> Bool {
        binary == appBinaryName || binary == "\(appBinaryName).debug.dylib"
    }

    /// Parses a line of `callStackSymbols`, such as "3   MyApp   0x0000000104b2c3d4 symbol + 52".
    private static func frame(fromSymbol line: String) -> [String: Any] {
        let parts = line.split(separator: " ", omittingEmptySubsequences: true)
        let binary = parts.count > 1 ? String(parts[1]) : "unknown"
        let symbol = parts.count > 3 ? parts[3...].joined(separator: " ") : line

        return ["file": binary, "line": 0, "function": symbol, "in_app": isAppBinary(binary)]
    }

    /// Flattens the call stack tree of the crashed thread into frames, the crash site first.
    private static func frames(from tree: MXCallStackTree) -> [[String: Any]] {
        guard let root = try? JSONSerialization.jsonObject(with: tree.jsonRepresentation()) as? [String: Any],
              let stacks = root["callStacks"] as? [[String: Any]] else { return [] }

        let crashed = stacks.first { $0["threadAttributed"] as? Bool == true } ?? stacks.first
        var frames: [[String: Any]] = []
        var next = (crashed?["callStackRootFrames"] as? [[String: Any]])?.first

        while let frame = next, frames.count < 100 {
            let binary = frame["binaryName"] as? String ?? "unknown"
            let offset = frame["offsetIntoBinaryTextSegment"] as? UInt64 ?? 0

            // The offset stays the same between launches of one build, the address does not (ASLR).
            frames.append([
                "file": binary,
                "line": 0,
                "function": "\(binary) + \(offset)",
                "in_app": isAppBinary(binary),
            ])

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

    private static func signalName(_ signal: Int32) -> String {
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
final class ZXGuardMetricSubscriber: NSObject, MXMetricManagerSubscriber {
    func didReceive(_ payloads: [MXDiagnosticPayload]) {
        for payload in payloads {
            for crash in payload.crashDiagnostics ?? [] {
                ZXGuardCrashReporter.record(diagnostic: crash, at: payload.timeStampEnd)
            }
        }
    }
}
