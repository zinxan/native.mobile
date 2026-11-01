import Foundation

/// Imports only the allowlisted fields needed for grouping and symbolication.
/// Raw recorder JSON, memory/register contents and device names never leave here.
enum NativeWatchRecordedCrash {
    static func report(_ raw: [String: Any], generation: String) -> [String: Any]? {
        guard let user = raw["user"] as? [String: Any], consentStamp(user) == generation,
              let info = raw["report"] as? [String: Any],
              let id = info["id"] as? String, let uuid = UUID(uuidString: id),
              let timestamp = info["timestamp"] as? String,
              let crash = raw["crash"] as? [String: Any],
              let error = crash["error"] as? [String: Any],
              let kind = error["type"] as? String,
              let threads = crash["threads"] as? [[String: Any]],
              let thread = threads.first(where: { $0["crashed"] as? Bool == true }),
              let backtrace = thread["backtrace"] as? [String: Any],
              let handlerEntries = backtrace["contents"] as? [[String: Any]],
              let images = raw["binary_images"] as? [[String: Any]] else { return nil }
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        var date = formatter.date(from: timestamp)
        if date == nil {
            formatter.formatOptions = [.withInternetDateTime]
            date = formatter.date(from: timestamp)
        }
        guard let date else { return nil }
        let details: (type: String, value: String)
        switch kind {
        case "nsexception":
            guard let exception = error["nsexception"] as? [String: Any], let name = exception["name"] as? String else { return nil }
            details = (name, error["reason"] as? String ?? "")
        case "mach":
            guard let mach = error["mach"] as? [String: Any], let name = mach["exception_name"] as? String else { return nil }
            let signal = error["signal"] as? [String: Any]
            details = (name, signal?["name"] as? String ?? name)
        case "signal":
            guard let signal = error["signal"] as? [String: Any], let name = signal["name"] as? String else { return nil }
            details = (name, name)
        default:
            return nil
        }
        let exceptionTrace = crash["last_exception_backtrace"] as? [String: Any]
        let entries = kind == "nsexception" ? (exceptionTrace?["contents"] as? [[String: Any]] ?? []) : handlerEntries
        let frames = entries.prefix(100).enumerated().compactMap { index, entry in
            frame(entry, images: images, isInstruction: kind != "nsexception" && index == 0)
        }
        let system = raw["system"] as? [String: Any]
        var value = NativeWatchCrashReporter.report(
            type: String(details.type.prefix(255)), value: String(details.value.prefix(8192)), frames: frames,
            mechanism: "ios.\(kind)", occurredAt: date,
            release: system?["CFBundleShortVersionString"] as? String,
            extra: ["trace_origin": kind == "nsexception" ? "exception" : "interrupted_thread"]
        )
        value["event_id"] = uuid.uuidString.lowercased()
        value["_nativewatch_consent_generation"] = generation
        return value
    }

    static func consentStamp(_ user: [String: Any]) -> String? {
        var values: [UInt32] = []
        for index in 0..<4 {
            guard let number = user["nativewatch_generation_\(index)"] as? NSNumber,
                  CFGetTypeID(number) != CFBooleanGetTypeID(),
                  number.doubleValue >= 0, number.doubleValue <= Double(UInt32.max),
                  number.doubleValue.rounded() == number.doubleValue else { return nil }
            values.append(number.uint32Value)
        }
        return values.map { String(format: "%08x", $0) }.joined()
    }

    private static func frame(_ entry: [String: Any], images: [[String: Any]], isInstruction: Bool) -> [String: Any]? {
        guard let number = entry["instruction_addr"] as? NSNumber else { return nil }
        let address = number.uint64Value
        guard let image = images.first(where: { image in
            guard let base = image["image_addr"] as? NSNumber, let size = image["image_size"] as? NSNumber else { return false }
            return address >= base.uint64Value && address - base.uint64Value < size.uint64Value
        }), let base = image["image_addr"] as? NSNumber, let path = image["name"] as? String else { return nil }
        let name = (path as NSString).lastPathComponent
        let offset = address - base.uint64Value
        var result: [String: Any] = [
            "file": String(name.prefix(1024)), "line": 0,
            "function": String((entry["symbol_name"] as? String ?? "\(name) + \(offset)").prefix(255)),
            "in_app": NativeWatchCrashReporter.isAppBinary(name) || path.contains(".app/Frameworks/"),
        ]
        if let id = image["uuid"] as? String, let uuid = UUID(uuidString: id),
           let cpu = image["cpu_type"] as? NSNumber, let subtype = image["cpu_subtype"] as? NSNumber,
           let architecture = NativeWatchArchitecture.from(cpu: cpu.uint32Value, subtype: subtype.uint32Value), offset <= 0xffffffffffff {
            result["native"] = [
                "image_uuid": uuid.uuidString.lowercased(), "architecture": architecture.rawValue,
                "instruction_offset": String(format: "0x%llx", offset),
                "address_kind": isInstruction ? "instruction" : "return_address",
            ]
        }
        return result
    }

}
