import Foundation

/// Uygulama ile widget arasında paylaşılan anı önbelleği (App Group kapsayıcısında JSON + küçültülmüş JPEG'ler).
enum AppGroup {
    static let id = "group.net.balkay.ownmemories"
    static var container: URL? { FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: id) }
}

struct WidgetPhoto: Codable, Hashable {
    let assetId: String
    let file: String
    let caption: String
}

struct WidgetMemory: Codable, Hashable {
    let id: String
    let date: String
    let title: String
    let photos: [WidgetPhoto]
}

struct WidgetSnapshot: Codable {
    var updatedAt: Date
    var memories: [WidgetMemory]
    var randoms: [WidgetPhoto]

    static let empty = WidgetSnapshot(updatedAt: .distantPast, memories: [], randoms: [])
}

enum WidgetStore {
    static var directory: URL? {
        guard let c = AppGroup.container else { return nil }
        let d = c.appendingPathComponent("widget", isDirectory: true)
        try? FileManager.default.createDirectory(at: d, withIntermediateDirectories: true)
        return d
    }

    static func load() -> WidgetSnapshot {
        guard let d = directory, let data = try? Data(contentsOf: d.appendingPathComponent("snapshot.json")),
              let s = try? JSONDecoder().decode(WidgetSnapshot.self, from: data) else { return .empty }
        return s
    }

    static func save(_ s: WidgetSnapshot) {
        guard let d = directory, let data = try? JSONEncoder().encode(s) else { return }
        try? data.write(to: d.appendingPathComponent("snapshot.json"), options: .atomic)
        // Artık kullanılmayan görselleri sil.
        let keep = Set(s.memories.flatMap { $0.photos.map(\.file) } + s.randoms.map(\.file) + ["snapshot.json"])
        for f in (try? FileManager.default.contentsOfDirectory(atPath: d.path)) ?? [] where !keep.contains(f) {
            try? FileManager.default.removeItem(at: d.appendingPathComponent(f))
        }
    }

    static func imageURL(_ file: String) -> URL? {
        directory?.appendingPathComponent(file)
    }
}

enum DeepLink {
    static func memory(date: String, id: String) -> URL {
        var c = URLComponents()
        c.scheme = "ownmemories"
        c.host = "memory"
        c.queryItems = [URLQueryItem(name: "date", value: date), URLQueryItem(name: "id", value: id)]
        return c.url!
    }

    static func asset(_ id: String) -> URL {
        URL(string: "ownmemories://asset?id=\(id)")!
    }
}
