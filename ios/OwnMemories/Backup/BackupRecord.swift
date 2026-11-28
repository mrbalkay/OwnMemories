import Foundation
import SwiftData

/// Yedeklenecek her kaynak için yerel durum (orijinal, Live Photo videosu, düzenlenmiş hali ayrı kayıtlar).
@Model
final class BackupRecord {
    @Attribute(.unique) var sourceRef: String
    var localId: String
    var kindRaw: String
    var isVideo: Bool
    var assetDate: Date
    /// pending | uploading (ön planda parça parça) | queued (arka plan oturumuna teslim) | done | failed
    var state: String
    /// Arka plan yüklemesi için kalıcı geçici dosya (yükleme bitince silinir).
    var stagedPath: String?
    var sha1: String?
    var uploadId: String?
    var fileSize: Int64
    var assetId: String?
    var attempts: Int
    var lastError: String?
    var updatedAt: Date

    init(sourceRef: String, localId: String, kind: ResourceKind, isVideo: Bool, assetDate: Date) {
        self.sourceRef = sourceRef
        self.localId = localId
        self.kindRaw = kind.rawValue
        self.isVideo = isVideo
        self.assetDate = assetDate
        self.state = "pending"
        self.fileSize = 0
        self.attempts = 0
        self.updatedAt = Date()
    }

    var kind: ResourceKind { ResourceKind(rawValue: kindRaw) ?? .original }

    /// İşleme sırası: aynı varlıkta önce orijinal (bağlantılar onu gösterir).
    var kindOrder: Int { kind == .original ? 0 : 1 }
}
