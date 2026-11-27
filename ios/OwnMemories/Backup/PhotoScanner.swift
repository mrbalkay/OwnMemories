import Foundation
import Photos

struct ResourceSpec {
    let localId: String
    let kind: ResourceKind
    let isVideo: Bool
    let assetDate: Date
    var sourceRef: String { SourceRef.make(localId: localId, kind: kind) }
}

/// Fotoğraf kütüphanesini tarar ve yedeklenecek kaynakları çıkarır.
enum PhotoScanner {
    static var authorization: PHAuthorizationStatus { PHPhotoLibrary.authorizationStatus(for: .readWrite) }

    static func requestAuthorization() async -> PHAuthorizationStatus {
        await PHPhotoLibrary.requestAuthorization(for: .readWrite)
    }

    /// `known`: zaten kaydı olan orijinal kaynakların localIdentifier'ları. Bunların kaynak listesi yalnız
    /// `modifiedSince` sonrası değiştiyse (ör. yeni düzenleme) yeniden incelenir.
    static func scan(known: Set<String>, modifiedSince: Date?, settings: BackupSettings) -> (specs: [ResourceSpec], changedEdits: Set<String>) {
        let opts = PHFetchOptions()
        opts.sortDescriptors = [NSSortDescriptor(key: "creationDate", ascending: false)]
        opts.includeHiddenAssets = false
        let result = PHAsset.fetchAssets(with: opts)
        var specs: [ResourceSpec] = []
        var changedEdits = Set<String>()
        result.enumerateObjects { asset, _, _ in
            guard asset.mediaType == .image || asset.mediaType == .video else { return }
            let isVideo = asset.mediaType == .video
            guard BackupPolicy.includes(isVideo: isVideo, settings: settings) else { return }
            let id = asset.localIdentifier
            let date = asset.creationDate ?? asset.modificationDate ?? Date()
            let isKnown = known.contains(id)
            let modified = modifiedSince.map { (asset.modificationDate ?? .distantPast) > $0 } ?? false
            if isKnown && !modified { return }
            if !isKnown {
                specs.append(ResourceSpec(localId: id, kind: .original, isVideo: isVideo, assetDate: date))
            }
            let types = Set(PHAssetResource.assetResources(for: asset).map(\.type))
            if !isKnown && !isVideo && types.contains(.pairedVideo) {
                specs.append(ResourceSpec(localId: id, kind: .live, isVideo: true, assetDate: date))
            }
            if types.contains(.fullSizePhoto) || types.contains(.fullSizeVideo) {
                specs.append(ResourceSpec(localId: id, kind: .edited, isVideo: isVideo, assetDate: date))
                if isKnown { changedEdits.insert(id) }
            }
        }
        return (specs, changedEdits)
    }

    static func asset(_ localId: String) -> PHAsset? {
        PHAsset.fetchAssets(withLocalIdentifiers: [localId], options: nil).firstObject
    }
}
