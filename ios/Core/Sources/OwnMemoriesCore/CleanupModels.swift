import Foundation

// Temizlik (GET /v1/cleanup, POST /v1/cleanup/resolve|dismiss|reset). Sunucu sözleşmesi: docs/ARCHITECTURE.md §Temizlik.
// Hiçbir şey kalıcı silinmez: "resolve" seçilenleri Çöp kutusuna taşır (30 gün).

/// Temizlik listesindeki bir öğe: asset + kalite ölçümü (+ karanlık/parlak listesinde neden).
struct CleanupItem: Decodable, Identifiable, Hashable {
    struct Quality: Decodable, Hashable {
        let sharpness: Double?
        let brightness: Double?
    }

    let asset: Asset
    let quality: Quality?
    /// Yalnızca karanlık/parlak listesinde: "dark" | "bright".
    let why: String?

    var id: String { asset.id }

    private enum Keys: String, CodingKey { case quality, why }

    init(asset: Asset, quality: Quality? = nil, why: String? = nil) {
        self.asset = asset
        self.quality = quality
        self.why = why
    }

    init(from decoder: Decoder) throws {
        asset = try Asset(from: decoder) // aynı nesnenin asset alanları
        let c = try decoder.container(keyedBy: Keys.self)
        quality = try c.decodeIfPresent(Quality.self, forKey: .quality)
        why = try c.decodeIfPresent(String.self, forKey: .why)
    }

    /// Netlik etiketi (web ile aynı eşikler): < 25 bulanık, < 80 biraz yumuşak.
    var sharpnessLevel: CleanupSharpness? {
        guard let s = quality?.sharpness else { return nil }
        return s < 25 ? .blurry : (s < 80 ? .soft : .sharp)
    }
}

enum CleanupSharpness: String {
    case sharp, soft, blurry
}

/// Benzer/yinelenen fotoğraf grubu. `keepId`: sunucunun önerdiği en iyi kare.
struct CleanupGroup: Decodable, Identifiable, Hashable {
    let id: String
    /// "duplicate" (neredeyse aynı) | "similar" (benzer kareler)
    let reason: String
    let keepId: String
    /// favorite | resolution | sharpest | location | original
    let keepReasons: [String]
    let bytes: Int64
    let takenAt: String
    let assets: [CleanupItem]

    var isDuplicate: Bool { reason == "duplicate" }

    /// Önerilen silinecekler: en iyisi dışındakiler; favoriler asla varsayılan olarak silinmez.
    var suggestedTrash: Set<String> { Set(assets.filter { $0.id != keepId && !$0.asset.isFavorite }.map(\.id)) }

    /// Seçime göre çöpe gidecek baytlar.
    func bytes(trashing ids: Set<String>) -> Int64 {
        assets.filter { ids.contains($0.id) }.reduce(0) { $0 + $1.asset.size }
    }

    private enum CodingKeys: String, CodingKey {
        case id, reason, bytes, assets
        case keepId = "keep_id"
        case keepReasons = "keep_reasons"
        case takenAt = "taken_at"
    }
}

struct CleanupStats: Decodable, Equatable {
    let potentialBytes: Int64
    let groups: Int
    let groupAssets: Int
    let blurry: Int
    let blurryBytes: Int64
    let dark: Int
    let darkBytes: Int64
    let screenshots: Int
    let screenshotsBytes: Int64
    let shortVideos: Int
    let shortVideosBytes: Int64

    private enum CodingKeys: String, CodingKey {
        case groups, blurry, dark, screenshots
        case potentialBytes = "potential_bytes"
        case groupAssets = "group_assets"
        case blurryBytes = "blurry_bytes"
        case darkBytes = "dark_bytes"
        case screenshotsBytes = "screenshots_bytes"
        case shortVideos = "short_videos"
        case shortVideosBytes = "short_videos_bytes"
    }
}

struct CleanupResponse: Decodable {
    struct ML: Decodable, Equatable { let available: Bool }
    struct Analysis: Decodable, Equatable {
        let analyzed: Int
        let total: Int
        var isRunning: Bool { total > 0 && analyzed < total }
    }

    let ml: ML
    let analysis: Analysis
    let groups: [CleanupGroup]
    let blurry: [CleanupItem]
    let dark: [CleanupItem]
    let screenshots: [CleanupItem]
    let shortVideos: [CleanupItem]
    let stats: CleanupStats

    private enum CodingKeys: String, CodingKey {
        case ml, analysis, groups, blurry, dark, screenshots, stats
        case shortVideos = "short_videos"
    }

    /// Önerilecek bir şey var mı?
    var isClean: Bool {
        groups.isEmpty && blurry.isEmpty && dark.isEmpty && screenshots.isEmpty && shortVideos.isEmpty
    }
}

/// Tek bir liste türü (bulanık, karanlık/parlak, ekran görüntüsü, kısa video); "Bu iyi" ile gizlenirken
/// sunucuya giden tür adı `dismissKind`.
enum CleanupCategory: String, CaseIterable, Identifiable {
    case blurry, dark, screenshots, videos

    var id: String { rawValue }

    var dismissKind: String {
        switch self {
        case .blurry: return "blurry"
        case .dark: return "dark"
        case .screenshots: return "screenshot"
        case .videos: return "video"
        }
    }

    func items(in r: CleanupResponse) -> [CleanupItem] {
        switch self {
        case .blurry: return r.blurry
        case .dark: return r.dark
        case .screenshots: return r.screenshots
        case .videos: return r.shortVideos
        }
    }

    func count(in s: CleanupStats) -> Int {
        switch self {
        case .blurry: return s.blurry
        case .dark: return s.dark
        case .screenshots: return s.screenshots
        case .videos: return s.shortVideos
        }
    }

    func bytes(in s: CleanupStats) -> Int64 {
        switch self {
        case .blurry: return s.blurryBytes
        case .dark: return s.darkBytes
        case .screenshots: return s.screenshotsBytes
        case .videos: return s.shortVideosBytes
        }
    }

    /// Liste açılınca hepsi seçili mi gelsin? (Ekran görüntüleri önemli olabilir: hayır.)
    var preselects: Bool { self != .screenshots }
}

/// POST /v1/cleanup/resolve gövdesi: grup başına tutulanlar ve çöpe gidenler.
struct CleanupResolveRequest: Encodable, Equatable {
    struct Part: Encodable, Equatable {
        let keep: [String]
        let trash: [String]
    }

    let groups: [Part]

    /// Parçaları istek başına en çok `limit` kimlik olacak şekilde böler (sunucu sınırı 1000).
    static func batches(_ parts: [Part], limit: Int = 900) -> [CleanupResolveRequest] {
        var out: [CleanupResolveRequest] = []
        var cur: [Part] = []
        var size = 0
        for p in parts where !p.trash.isEmpty || p.keep.count >= 2 {
            let n = p.keep.count + p.trash.count
            if size + n > limit, !cur.isEmpty {
                out.append(CleanupResolveRequest(groups: cur))
                cur = []
                size = 0
            }
            if n > limit {
                // Tek başına sınırı aşan parça (ör. 2000 ekran görüntüsü): çöpe gidenleri dilimle.
                var rest = p.trash[...]
                while !rest.isEmpty {
                    let chunk = Array(rest.prefix(limit))
                    rest = rest.dropFirst(chunk.count)
                    out.append(CleanupResolveRequest(groups: [Part(keep: [], trash: chunk)]))
                }
                continue
            }
            cur.append(p)
            size += n
        }
        if !cur.isEmpty { out.append(CleanupResolveRequest(groups: cur)) }
        return out
    }

    /// Grup seçiminden parça: tutulanlar = grupta olup çöpe gitmeyenler.
    static func part(for group: CleanupGroup, trash: Set<String>) -> Part {
        let ids = group.assets.map(\.id)
        return Part(keep: ids.filter { !trash.contains($0) }, trash: ids.filter { trash.contains($0) })
    }
}

struct CleanupResolveResponse: Decodable, Equatable {
    let trashed: Int
    let freedBytes: Int64

    private enum CodingKeys: String, CodingKey {
        case trashed
        case freedBytes = "freed_bytes"
    }
}

struct CleanupDismissRequest: Encodable, Equatable {
    let kind: String
    let ids: [String]
}

struct CleanupDismissResponse: Decodable { let dismissed: Int }
struct CleanupResetResponse: Decodable { let restored: Int }
