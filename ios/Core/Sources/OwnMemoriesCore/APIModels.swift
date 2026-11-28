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

/// Akıllı arama dizin durumu: GET /v1/search yanıtındaki "status" ve GET /v1/search/status.
/// Makine öğrenimi servisi yoksa /v1/search/status yalnızca {"available":false} döner; eksik alanlar varsayılanla dolar.
struct SearchStatus: Codable, Equatable {
    let indexed: Int
    let total: Int
    let pending: Int?
    let ready: Bool
    let model: String?
    let available: Bool

    init(indexed: Int, total: Int, pending: Int? = nil, ready: Bool, model: String? = nil, available: Bool = true) {
        self.indexed = indexed
        self.total = total
        self.pending = pending
        self.ready = ready
        self.model = model
        self.available = available
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        indexed = try c.decodeIfPresent(Int.self, forKey: .indexed) ?? 0
        total = try c.decodeIfPresent(Int.self, forKey: .total) ?? 0
        pending = try c.decodeIfPresent(Int.self, forKey: .pending)
        ready = try c.decodeIfPresent(Bool.self, forKey: .ready) ?? false
        model = try c.decodeIfPresent(String.self, forKey: .model)
        available = try c.decodeIfPresent(Bool.self, forKey: .available) ?? true
    }

    enum CodingKeys: String, CodingKey {
        case indexed, total, pending, ready, model, available
    }

    /// Dizinlenen oran, 0...100 (aşağı yuvarlanır; bitmeden %100 görünmez).
    var percent: Int {
        guard total > 0 else { return ready ? 100 : 0 }
        return max(0, min(100, indexed * 100 / total))
    }

    /// Dizinleme sürüyor: servis var ama tüm öğeler henüz dizinlenmedi.
    var isIndexing: Bool { available && total > 0 && indexed < total }
}

/// GET /v1/search?q=&from=&to=&favorite=&limit=&offset= yanıtı (sonuçlar uygunluk sırasında).
struct SearchResponse: Codable {
    /// "smart" (anlamsal arama) | "text" (dosya adı/açıklama/kamera metin araması; ML servisi yoksa)
    let mode: String
    let assets: [Asset]
    let nextOffset: Int?
    /// Tüm eşleşmelerin sayısı (sunucu verirse; yoksa yüklenen sayfalardan "60+" gösterilir).
    let total: Int?
    let status: SearchStatus?

    var isSmart: Bool { mode == "smart" }

    enum CodingKeys: String, CodingKey {
        case mode, assets, total, status
        case nextOffset = "next_offset"
    }
}

// MARK: - Kişiler (elle etiketleme + yüz tanıma)

/// GET /v1/people, GET/PATCH /v1/people/{id} kişi nesnesi. `name == nil`: adlandırılmamış yüz kümesi.
struct Person: Codable, Identifiable, Hashable {
    let id: Int
    let name: String?
    let count: Int
    let coverFaceId: Int?
    let coverAssetId: String?
    let hidden: Bool

    init(id: Int, name: String?, count: Int = 0, coverFaceId: Int? = nil, coverAssetId: String? = nil, hidden: Bool = false) {
        self.id = id
        self.name = name
        self.count = count
        self.coverFaceId = coverFaceId
        self.coverAssetId = coverAssetId
        self.hidden = hidden
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = try c.decodeIfPresent(String.self, forKey: .name)
        count = try c.decodeIfPresent(Int.self, forKey: .count) ?? 0
        coverFaceId = try c.decodeIfPresent(Int.self, forKey: .coverFaceId)
        coverAssetId = try c.decodeIfPresent(String.self, forKey: .coverAssetId)
        hidden = try c.decodeIfPresent(Bool.self, forKey: .hidden) ?? false
    }

    enum CodingKeys: String, CodingKey {
        case id, name, count, hidden
        case coverFaceId = "cover_face_id"
        case coverAssetId = "cover_asset_id"
    }

    /// Adı var (boş olmayan).
    var isNamed: Bool { !(name ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }

    /// Ekranda gösterilen ad; adsız kümelerde "Adsız".
    var displayName: String { isNamed ? (name ?? "") : "Adsız" }
}

struct PeopleList: Codable {
    let people: [Person]
}

struct PersonEnvelope: Codable {
    let person: Person
}

/// GET /v1/assets/{id}/people → "people" öğesi: bu fotoğrafta kim var (kaynak: manual | takeout | face).
struct AssetPersonTag: Codable, Identifiable, Hashable {
    let id: Int
    let name: String?
    let source: String?

    var isNamed: Bool { !(name ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
    var displayName: String { isNamed ? (name ?? "") : "Adsız" }
}

/// Fotoğrafta bulunan yüz: kutu 0...1 (yönü uygulanmış görüntüye göre); kişiye atanmamışsa person_id nil.
struct DetectedFace: Codable, Identifiable, Hashable {
    let id: Int
    let x1: Double
    let y1: Double
    let x2: Double
    let y2: Double
    let personId: Int?
    let personName: String?

    enum CodingKeys: String, CodingKey {
        case id, x1, y1, x2, y2
        case personId = "person_id"
        case personName = "person_name"
    }

    /// Yüz adlı bir kişiye atanmış.
    var isNamed: Bool { personId != nil && !(personName ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
}

/// GET/POST /v1/assets/{id}/people, DELETE /v1/assets/{id}/people/{pid}, PATCH /v1/faces/{id} yanıtı.
struct AssetPeople: Codable, Equatable {
    let people: [AssetPersonTag]
    let faces: [DetectedFace]

    init(people: [AssetPersonTag] = [], faces: [DetectedFace] = []) {
        self.people = people
        self.faces = faces
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        people = try c.decodeIfPresent([AssetPersonTag].self, forKey: .people) ?? []
        faces = try c.decodeIfPresent([DetectedFace].self, forKey: .faces) ?? []
    }

    enum CodingKeys: String, CodingKey { case people, faces }

    /// Kişinin bu fotoğraftaki bir yüzü (yuvarlak avatar için).
    func face(of personId: Int) -> DetectedFace? { faces.first { $0.personId == personId } }

    /// Adı olmayan yüzler (atanmamış ya da adsız kümede): "Adlandır" ile gösterilir.
    var unnamedFaces: [DetectedFace] { faces.filter { !$0.isNamed } }

    /// Etiket çipleri: adlı kişiler; adsız kişiler yalnızca bu fotoğrafta yüzleri yoksa (yüzleri ayrıca listelenir).
    var chips: [AssetPersonTag] {
        people.filter { $0.isNamed || face(of: $0.id) == nil }
    }
}

/// POST /v1/assets/people (toplu etiket) yanıtı.
struct BulkPeopleTagResponse: Codable {
    let count: Int
    let person: Person
}

/// GET /v1/people/status: yüz tarama durumu. ML servisi yoksa yalnızca {"available":false}.
struct FaceScanStatus: Codable, Equatable {
    let available: Bool
    let scanned: Int
    let total: Int
    let faces: Int
    let people: Int
    let ready: Bool

    init(available: Bool = true, scanned: Int, total: Int, faces: Int = 0, people: Int = 0, ready: Bool) {
        self.available = available
        self.scanned = scanned
        self.total = total
        self.faces = faces
        self.people = people
        self.ready = ready
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        scanned = try c.decodeIfPresent(Int.self, forKey: .scanned) ?? 0
        total = try c.decodeIfPresent(Int.self, forKey: .total) ?? 0
        faces = try c.decodeIfPresent(Int.self, forKey: .faces) ?? 0
        people = try c.decodeIfPresent(Int.self, forKey: .people) ?? 0
        ready = try c.decodeIfPresent(Bool.self, forKey: .ready) ?? false
        available = try c.decodeIfPresent(Bool.self, forKey: .available) ?? true
    }

    enum CodingKeys: String, CodingKey {
        case available, scanned, total, faces, people, ready
    }

    /// Tarama sürüyor: servis var, öğeler var ama hepsi taranmadı.
    var isScanning: Bool { available && total > 0 && scanned < total }
}

/// PATCH /v1/people/{id} 409 yanıtı: aynı adda başka bir kişi var (istemci birleştirmeyi önerir).
/// {"error":{"code":"name_exists","message":"…","person_id":7,"person":{…}}}
struct PersonNameConflict: Codable {
    struct Body: Codable {
        let code: String
        let message: String?
        let personId: Int?
        let person: Person?

        enum CodingKeys: String, CodingKey {
            case code, message, person
            case personId = "person_id"
        }
    }
    let error: Body

    /// Çakışan kişinin kimliği (gövdedeki kişi nesnesi ya da person_id).
    var otherId: Int? { error.person?.id ?? error.personId }
}

// MARK: - Sunucu yedeği (GET /v1/admin/backup; yalnızca yönetici, diğerlerine 403)

enum BackupLevel: String, Codable, Equatable {
    case ok, warn, error, unconfigured, unknown

    init(from decoder: Decoder) throws {
        self = BackupLevel(rawValue: (try? decoder.singleValueContainer().decode(String.self)) ?? "") ?? .unknown
    }
}

struct ServerBackupEnvelope: Codable {
    let backup: ServerBackupStatus
}

struct ServerBackupStatus: Codable, Equatable {
    struct Health: Codable, Equatable {
        let level: BackupLevel
        let reason: String
    }

    struct Target: Codable, Equatable, Identifiable {
        let id: String
        let type: String?
        let label: String?
        let lastStatus: String?
        let lastSuccessAt: String?
        let repoSize: Int64?
        let snapshotCount: Int?
        let error: String?
        let health: Health

        var title: String { label ?? id }
        var lastSuccessDate: Date? { lastSuccessAt.flatMap { ISO8601DateFormatter().date(from: $0) } }

        enum CodingKeys: String, CodingKey {
            case id, type, label, error, health
            case lastStatus = "last_status"
            case lastSuccessAt = "last_success_at"
            case repoSize = "repo_size"
            case snapshotCount = "snapshot_count"
        }
    }

    struct RestoreTest: Codable, Equatable {
        let at: String?
        let ok: Bool
        let filesChecked: Int?

        enum CodingKeys: String, CodingKey {
            case at, ok
            case filesChecked = "files_checked"
        }
    }

    let configured: Bool
    let level: BackupLevel
    let targets: [Target]
    let nextRunAt: String?
    let running: Bool?
    let restoreTest: RestoreTest?

    var nextRunDate: Date? { nextRunAt.flatMap { ISO8601DateFormatter().date(from: $0) } }

    /// Türkçe özet başlık (web paneliyle aynı ifadeler).
    var headline: String {
        switch level {
        case .ok: return "Yedekler güncel"
        case .warn: return "Dikkat gerekiyor"
        case .error: return "Yedek alınamıyor"
        case .unconfigured: return "Yedekleme kurulmadı"
        case .unknown: return "Bilinmiyor"
        }
    }

    enum CodingKeys: String, CodingKey {
        case configured, level, targets, running
        case nextRunAt = "next_run_at"
        case restoreTest = "restore_test"
    }
}
