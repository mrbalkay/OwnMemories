import Foundation
import Photos

enum ExportError: LocalizedError {
    case assetMissing
    case resourceMissing
    var errorDescription: String? {
        switch self {
        case .assetMissing: return "Fotoğraf artık kütüphanede yok."
        case .resourceMissing: return "Fotoğrafın bu parçası bulunamadı."
        }
    }
}

/// Bir kaynağı (orijinal / Live Photo videosu / düzenlenmiş hal) geçici dosyaya yazar. iCloud'daysa indirir.
enum ResourceExporter {
    struct Exported {
        let url: URL
        let filename: String
        let size: Int64
    }

    static var exportDir: URL {
        let d = FileManager.default.temporaryDirectory.appendingPathComponent("om-export", isDirectory: true)
        try? FileManager.default.createDirectory(at: d, withIntermediateDirectories: true)
        return d
    }

    static func export(localId: String, kind: ResourceKind) async throws -> Exported {
        guard let asset = PhotoScanner.asset(localId) else { throw ExportError.assetMissing }
        let resources = PHAssetResource.assetResources(for: asset)
        let original = resources.first { $0.type == (asset.mediaType == .video ? .video : .photo) } ?? resources.first
        let res: PHAssetResource?
        switch kind {
        case .original: res = original
        case .live: res = resources.first { $0.type == .pairedVideo }
        case .edited: res = resources.first { $0.type == .fullSizePhoto || $0.type == .fullSizeVideo }
        }
        guard let res else { throw ExportError.resourceMissing }
        // Ad: orijinal dosya adı; düzenlenmiş/Live hali için uzantı kendi kaynağından.
        let baseName = ((original?.originalFilename ?? res.originalFilename) as NSString).deletingPathExtension
        let ext = (res.originalFilename as NSString).pathExtension
        let filename = ext.isEmpty ? baseName : baseName + "." + ext
        let url = exportDir.appendingPathComponent(UUID().uuidString + (ext.isEmpty ? "" : "." + ext))
        let opts = PHAssetResourceRequestOptions()
        opts.isNetworkAccessAllowed = true
        try await PHAssetResourceManager.default().writeData(for: res, toFile: url, options: opts)
        let size = (try? FileManager.default.attributesOfItem(atPath: url.path)[.size] as? NSNumber)?.int64Value ?? 0
        return Exported(url: url, filename: filename, size: size)
    }

    static func cleanup() {
        try? FileManager.default.removeItem(at: exportDir)
    }

    /// Dosyanın [range] aralığını ayrı bir geçici dosyaya yazar (arka plan yüklemesi dosyadan yapılmak zorunda).
    static func writeChunk(of url: URL, range: Range<Int64>) throws -> URL {
        let out = exportDir.appendingPathComponent(UUID().uuidString + ".part")
        FileManager.default.createFile(atPath: out.path, contents: nil)
        let reader = try FileHandle(forReadingFrom: url)
        let writer = try FileHandle(forWritingTo: out)
        defer { try? reader.close(); try? writer.close() }
        try reader.seek(toOffset: UInt64(range.lowerBound))
        var left = range.upperBound - range.lowerBound
        while left > 0 {
            let n = Int(min(left, 4 * 1024 * 1024))
            guard let data = try reader.read(upToCount: n), !data.isEmpty else { break }
            try writer.write(contentsOf: data)
            left -= Int64(data.count)
        }
        return out
    }
}
