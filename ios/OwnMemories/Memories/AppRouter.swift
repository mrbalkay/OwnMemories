import Foundation

/// Bildirim ve widget bağlantılarından gelen gezinme istekleri.
@MainActor
final class AppRouter: ObservableObject {
    static let shared = AppRouter()

    struct MemoryTarget: Identifiable, Equatable {
        let requestDate: String
        let memoryId: String
        var id: String { memoryId + "@" + requestDate }
    }

    struct AssetTarget: Identifiable, Equatable {
        let id: String
    }

    @Published var memory: MemoryTarget?
    @Published var asset: AssetTarget?

    /// ownmemories://memory?date=…&id=…  ·  ownmemories://asset?id=…
    func handle(_ url: URL) {
        guard url.scheme == "ownmemories", let c = URLComponents(url: url, resolvingAgainstBaseURL: false) else { return }
        let q = Dictionary(uniqueKeysWithValues: (c.queryItems ?? []).map { ($0.name, $0.value ?? "") })
        switch c.host {
        case "memory":
            if let id = q["id"], let date = q["date"] { openMemory(requestDate: Self.requestDate(for: id, fallback: date), id: id) }
        case "asset":
            if let id = q["id"] { asset = AssetTarget(id: id) }
        default:
            break
        }
    }

    func openMemory(requestDate: String, id: String) {
        memory = MemoryTarget(requestDate: requestDate, memoryId: id)
    }

    /// Anı kimliği "tür:tarih" biçiminde. "Yıl önce" anıları, o günün bu yılki tarihiyle istenir
    /// (ör. on_this_day:2025-11-27 → 2026-11-27); favoriler kendi tarihiyle.
    static func requestDate(for memoryId: String, fallback: String) -> String {
        let parts = memoryId.split(separator: ":", maxSplits: 1).map(String.init)
        guard parts.count == 2 else { return fallback }
        if parts[0] == "favorites" { return parts[1] }
        let year = Calendar(identifier: .gregorian).component(.year, from: Date())
        return String(year) + parts[1].dropFirst(4)
    }
}
