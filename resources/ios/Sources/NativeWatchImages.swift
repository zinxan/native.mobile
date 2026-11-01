import Foundation
import KSCrashRecordingCore
import MachO

/// A concurrent dlclose can invalidate dyld's pointers. Parse only owned,
/// bounded snapshots; unreadable or changing images supply no architecture.
enum NativeWatchImages {
    struct Image: Equatable {
        let uuid: UUID
        let architecture: NativeWatchArchitecture
    }

    static func architectures() -> [UUID: NativeWatchArchitecture] {
        var result: [UUID: NativeWatchArchitecture] = [:]
        for index in 0..<_dyld_image_count() {
            guard let address = _dyld_get_image_header(index), let image = image(at: address) else { continue }
            result[image.uuid] = image.architecture
        }
        return result
    }

    static func image(at address: UnsafeRawPointer) -> Image? {
        var header = mach_header_64()
        guard ksmem_copySafely(address, &header, Int32(MemoryLayout<mach_header_64>.size)),
              header.magic == MH_MAGIC_64, header.sizeofcmds <= 65536, header.ncmds <= 1024 else { return nil }
        var snapshot = Data(count: MemoryLayout<mach_header_64>.size + Int(header.sizeofcmds))
        let copied = snapshot.withUnsafeMutableBytes { bytes in
            guard let destination = bytes.baseAddress else { return false }
            return ksmem_copySafely(address, destination, Int32(bytes.count))
        }
        return copied ? image(header: header, snapshot: snapshot) : nil
    }

    static func image(header original: mach_header_64, snapshot: Data) -> Image? {
        guard snapshot.count >= MemoryLayout<mach_header_64>.size else { return nil }
        return snapshot.withUnsafeBytes { bytes in
            let header = bytes.loadUnaligned(as: mach_header_64.self)
            guard header.magic == MH_MAGIC_64, header.sizeofcmds <= 65536, header.ncmds <= 1024,
                  header.sizeofcmds == original.sizeofcmds, header.ncmds == original.ncmds,
                  snapshot.count == MemoryLayout<mach_header_64>.size + Int(header.sizeofcmds),
                  let architecture = NativeWatchArchitecture.from(
                    cpu: UInt32(bitPattern: header.cputype), subtype: UInt32(bitPattern: header.cpusubtype)
                  ) else { return nil }
            var position = MemoryLayout<mach_header_64>.size
            var uuid: UUID?
            for _ in 0..<header.ncmds {
                guard snapshot.count - position >= MemoryLayout<load_command>.size else { return nil }
                let command = bytes.loadUnaligned(fromByteOffset: position, as: load_command.self)
                guard command.cmdsize >= MemoryLayout<load_command>.size,
                      Int(command.cmdsize) <= snapshot.count - position else { return nil }
                if command.cmd == LC_UUID {
                    guard command.cmdsize == MemoryLayout<uuid_command>.size, uuid == nil else { return nil }
                    uuid = UUID(uuid: bytes.loadUnaligned(fromByteOffset: position, as: uuid_command.self).uuid)
                }
                position += Int(command.cmdsize)
            }
            guard position == snapshot.count, let uuid else { return nil }
            return Image(uuid: uuid, architecture: architecture)
        }
    }
}
