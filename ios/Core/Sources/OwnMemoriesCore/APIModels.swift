import Foundation

// Sunucu JSON şekilleri — docs/ARCHITECTURE.md §4 ile birebir.

struct ServerUser: Codable, Equatable {
    let id: Int
    let email: String
    let name: String
    let isAdmin: Bool
    let usedBytes: Int64?
    let quotaBytes: Int64?
    /// Görünüm teması: "sinema" | "galeri" | "yerel" (eski sunucularda yok → varsayılan Sinema).
    let theme: String?

    enum CodingKeys: String, CodingKey {
        case id, email, name, theme
        case isAdmin = "is_admin"
        case usedBytes = "used_bytes"
        case quotaBytes = "quota_bytes"
    }
}

struct LoginResponse: Codable {
    let token: String
    let user: ServerUser
}

struct MeResponse: Codable {
    let user: ServerUser
}

struct Asset: Codable, Identifiable, Hashable {
    let id: String
    let type: String
    let filename: String
    let mime: String?
    let size: Int64
    let width: Int?
    let height: Int?
    let durationMs: Int?
    let takenAt: String
    let takenLocal: String?
    let tzOffset: Int?
    let dateSource: String?
    let latitude: Double?
    let longitude: Double?
    let cameraMake: String?
    let cameraModel: String?
    let caption: String?
    var isFavorite: Bool
    let isArchived: Bool
    let livePairId: String?
    let editedOfId: String?
    let thumbState: String?
    let access: String?

    var isVideo: Bool { type == "video" }

    /// Yerel gün anahtarı ("2019-07-01"), çekildiği yerin saatine göre.
    var dayKey: String { String((takenLocal ?? takenAt).prefix(10)) }

    enum CodingKeys: String, CodingKey {
        case id, type, filename, mime, size, width, height, latitude, longitude, access
        case durationMs = "duration_ms"
        case takenAt = "taken_at"
        case takenLocal = "taken_local"
        case tzOffset = "tz_offset"
        case dateSource = "date_source"
        case cameraMake = "camera_make"
        case cameraModel = "camera_model"
        case caption = "description"
        case isFavorite = "is_favorite"
        case isArchived = "is_archived"
        case livePairId = "live_pair_id"
        case editedOfId = "edited_of_id"
        case thumbState = "thumb_state"
    }
}

struct AssetCursor: Codable, Equatable {
    let before: String
    let beforeId: String?

    enum CodingKeys: String, CodingKey {
        case before
        case beforeId = "before_id"
    }
}

struct AssetPage: Codable {
    let assets: [Asset]
    let next: AssetCursor?
}

struct AssetEnvelope: Codable {
    let asset: Asset
}

struct CheckResponse: Codable {
    let missing: [String]
}

struct UploadStart: Codable {
    let id: String
    let chunkSize: Int64
    let received: Int64

    enum CodingKeys: String, CodingKey {
        case id, received
        case chunkSize = "chunk_size"
    }
}

struct UploadReceived: Codable {
    let received: Int64
}

struct UploadComplete: Codable {
    /// created | duplicate | deleted_before
    let status: String
    let asset: Asset?
}

struct APIErrorEnvelope: Codable {
    struct Body: Codable {
        let code: String
        let message: String
        let received: Int64?
        let field: String?
    }
    let error: Body
}

/// Yükleme başlatırken gönderilen meta veri.
struct UploadMeta: Codable, Equatable {
    var takenAt: String?
    var tzOffset: Int?
    var latitude: Double?
    var longitude: Double?
    var isFavorite: Bool?
    var sourceRef: String
    var livePairSourceRef: String?
    var editedOfSourceRef: String?

    enum CodingKeys: String, CodingKey {
        case latitude, longitude
        case takenAt = "taken_at"
        case tzOffset = "tz_offset"
        case isFavorite = "is_favorite"
        case sourceRef = "source_ref"
        case livePairSourceRef = "live_pair_source_ref"
        case editedOfSourceRef = "edited_of_source_ref"
    }
}

struct UploadStartRequest: Codable {
    let filename: String
    let size: Int64
    let sha1: String?
    let meta: UploadMeta
}

struct DeviceReport: Codable, Equatable {
    let deviceId: String
    let name: String
    let model: String
    let osVersion: String
    let appVersion: String
    let pending: Int
    let uploadedTotal: Int
    let lastBackupAt: String?
    let lastError: String?

    enum CodingKeys: String, CodingKey {
        case name, model, pending
        case deviceId = "device_id"
        case osVersion = "os_version"
        case appVersion = "app_version"
        case uploadedTotal = "uploaded_total"
        case lastBackupAt = "last_backup_at"
        case lastError = "last_error"
    }
}

struct Memory: Codable, Identifiable, Hashable {
    let id: String
    let kind: String
    let title: String
    let yearsAgo: Int?
    let date: String
    let count: Int
    let coverAssetId: String
    let assetIds: [String]
    let assets: [Asset]

    enum CodingKeys: String, CodingKey {
        case id, kind, title, date, count, assets
        case yearsAgo = "years_ago"
        case coverAssetId = "cover_asset_id"
        case assetIds = "asset_ids"
    }
}

struct MemoriesResponse: Codable {
    let date: String
    let memories: [Memory]
}

struct AssetList: Codable {
    let assets: [Asset]
}
