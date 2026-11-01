import Foundation

enum NativeWatchConsent {
    private static let lock = NSRecursiveLock()
    private static var loaded = false
    private static var state: [String: Any] = [:]

    private static var file: URL {
        NativeWatchCrashReporter.directory.deletingLastPathComponent().appendingPathComponent("consent.json")
    }

    static var generation: String? {
        withLock {
            load()
            guard let value = state["generation"] as? String,
                  value.range(of: "^[a-f0-9]{32}$", options: .regularExpression) != nil else { return nil }
            return value
        }
    }

    static func accepts(intervalBeginning date: Date) -> Bool {
        withLock {
            generation != nil && date.timeIntervalSince1970 >= (state["granted_at"] as? Double ?? .infinity)
        }
    }

    static func withPermission<T>(_ operation: (String) -> T) -> T? {
        withLock {
            guard let generation else { return nil }
            return operation(generation)
        }
    }

    static func setGranted(_ granted: Bool) -> Bool {
        withLock {
            if granted, generation != nil { return true }
            state = [:]
            loaded = true
            NativeWatchCrashAdmission.disable()
            do {
                try FileManager.default.createDirectory(at: file.deletingLastPathComponent(), withIntermediateDirectories: true)
                try Data("{}".utf8).write(to: file, options: .atomic)
                guard NativeWatchCrashReporter.disable() else { return false }
                let legacySignals = NativeWatchCrashReporter.directory.deletingLastPathComponent().appendingPathComponent("signals")
                for directory in [NativeWatchCrashReporter.directory, legacySignals] {
                    if FileManager.default.fileExists(atPath: directory.path) {
                        try FileManager.default.removeItem(at: directory)
                    }
                }
                if granted {
                    let value: [String: Any] = [
                        "generation": UUID().uuidString.replacingOccurrences(of: "-", with: "").lowercased(),
                        "granted_at": Date().timeIntervalSince1970,
                    ]
                    try JSONSerialization.data(withJSONObject: value).write(to: file, options: .atomic)
                    state = value
                    guard NativeWatchCrashReporter.activate() else {
                        state = [:]
                        try Data("{}".utf8).write(to: file, options: .atomic)
                        return false
                    }
                }
                return true
            } catch {
                return false
            }
        }
    }

    private static func load() {
        guard !loaded else { return }
        loaded = true
        guard let data = try? Data(contentsOf: file),
              let value = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else { return }
        state = value
    }

    private static func withLock<T>(_ operation: () -> T) -> T {
        lock.lock()
        defer { lock.unlock() }
        return operation()
    }
}
