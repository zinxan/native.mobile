import Atomics
import KSCrashRecording

/// Only integer atomics and the recorder's C writer are used by crash callbacks.
/// Storage is preinitialized and lives for the process. One immutable fatal
/// admission prevents a writer from adopting a later consent interval.
enum NativeWatchCrashAdmission {
    private static let sequence = UnsafeAtomic<UInt64>.create(1)
    private static let first = UnsafeAtomic<UInt64>.create(0)
    private static let second = UnsafeAtomic<UInt64>.create(0)
    private static let admission = UnsafeAtomic<UInt32>.create(0)
    private static let admittedFirst = UnsafeAtomic<UInt64>.create(0)
    private static let admittedSecond = UnsafeAtomic<UInt64>.create(0)
    private static let key0: StaticString = "nativewatch_generation_0"
    private static let key1: StaticString = "nativewatch_generation_1"
    private static let key2: StaticString = "nativewatch_generation_2"
    private static let key3: StaticString = "nativewatch_generation_3"

    /// Called only under NativeWatchConsent's normal-thread lock.
    static func enable(_ generation: String) -> Bool {
        let version = sequence.load(ordering: .sequentiallyConsistent)
        guard version % 2 == 1, version < UInt64.max - 1,
              generation.count == 32,
              let a = UInt64(generation.prefix(16), radix: 16),
              let b = UInt64(generation.suffix(16), radix: 16) else { return false }
        first.store(a, ordering: .sequentiallyConsistent)
        second.store(b, ordering: .sequentiallyConsistent)
        _ = admission.load(ordering: .sequentiallyConsistent)
        _ = admittedFirst.load(ordering: .sequentiallyConsistent)
        _ = admittedSecond.load(ordering: .sequentiallyConsistent)
        _ = key0.utf8Start; _ = key1.utf8Start; _ = key2.utf8Start; _ = key3.utf8Start
        sequence.store(version + 1, ordering: .sequentiallyConsistent)
        return true
    }

    static func disable() {
        let version = sequence.load(ordering: .sequentiallyConsistent)
        if version % 2 == 0 { sequence.store(version + 1, ordering: .sequentiallyConsistent) }
    }

    static func admit(_ plan: UnsafeMutablePointer<ExceptionHandlingPlan>) {
        guard plan.pointee.isFatal, !plan.pointee.isCleanExit, !plan.pointee.crashedDuringExceptionHandling else {
            plan.pointee.shouldWriteReport = false
            return
        }
        let before = sequence.load(ordering: .sequentiallyConsistent)
        let a = first.load(ordering: .sequentiallyConsistent)
        let b = second.load(ordering: .sequentiallyConsistent)
        guard before % 2 == 0, before == sequence.load(ordering: .sequentiallyConsistent),
              admission.compareExchange(expected: 0, desired: 1, ordering: .sequentiallyConsistent).exchanged else {
            plan.pointee.shouldWriteReport = false
            return
        }
        admittedFirst.store(a, ordering: .sequentiallyConsistent)
        admittedSecond.store(b, ordering: .sequentiallyConsistent)
        admission.store(2, ordering: .releasing)
    }

    static func write(_ writer: UnsafePointer<ReportWriter>) {
        guard admission.load(ordering: .acquiring) == 2 else { return }
        let a = admittedFirst.load(ordering: .sequentiallyConsistent)
        let b = admittedSecond.load(ordering: .sequentiallyConsistent)
        writer.pointee.addUIntegerElement(writer, UnsafeRawPointer(key0.utf8Start).assumingMemoryBound(to: CChar.self), a >> 32)
        writer.pointee.addUIntegerElement(writer, UnsafeRawPointer(key1.utf8Start).assumingMemoryBound(to: CChar.self), a & 0xffffffff)
        writer.pointee.addUIntegerElement(writer, UnsafeRawPointer(key2.utf8Start).assumingMemoryBound(to: CChar.self), b >> 32)
        writer.pointee.addUIntegerElement(writer, UnsafeRawPointer(key3.utf8Start).assumingMemoryBound(to: CChar.self), b & 0xffffffff)
    }
}
