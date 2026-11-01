enum NativeWatchArchitecture: String {
    case arm64, arm64e, x86_64

    static func from(cpu: UInt32, subtype: UInt32) -> Self? {
        switch (cpu, subtype & 0xffffff) {
        case (0x100000c, 0): return .arm64
        case (0x100000c, 2): return .arm64e
        case (0x1000007, 3): return .x86_64
        default: return nil
        }
    }
}
