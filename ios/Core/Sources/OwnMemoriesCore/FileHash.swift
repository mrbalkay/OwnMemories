import Foundation
#if canImport(CryptoKit)
import CryptoKit
#endif

enum FileHash {
    /// Dosyanın SHA-1 özeti (küçük harf onaltılık); dosya belleğe alınmadan 4 MB'lık parçalarla okunur.
    static func sha1Hex(of url: URL) throws -> String {
        #if canImport(CryptoKit)
        let handle = try FileHandle(forReadingFrom: url)
        defer { try? handle.close() }
        var hasher = Insecure.SHA1()
        while true {
            let chunk = try handle.read(upToCount: 4 * 1024 * 1024) ?? Data()
            if chunk.isEmpty { break }
            hasher.update(data: chunk)
        }
        return hasher.finalize().map { String(format: "%02x", $0) }.joined()
        #else
        throw CocoaError(.featureUnsupported)
        #endif
    }
}
