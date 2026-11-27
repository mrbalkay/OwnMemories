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
        // Tekrarlanan anahtarlar uygulamayı çökertmesin: ilk değer kullanılır.
        let q = Dictionary((c.queryItems ?? []).map { ($0.name, $0.value ?? "") }, uniquingKeysWith: { first, _ in first })
        switch c.host {
        case "memory":
            guard let id = q["id"], !id.isEmpty else { return }
            let date = q["date"].flatMap { Self.isDay($0) ? $0 : nil } ?? Self.requestDate(for: id, fallback: "")
            guard Self.isDay(date) else { return }
            openMemory(requestDate: date, id: id)
        case "asset":
            if let id = q["id"], id.range(of: "^[0-9a-f-]{36}$", options: .regularExpression) != nil {
                present { self.asset = AssetTarget(id: id) }
            }
        default:
            break
        }
    }

    func openMemory(requestDate: String, id: String) {
        present { self.memory = MemoryTarget(requestDate: requestDate, memoryId: id) }
    }

    /// Açık bir tam ekran varsa önce kapatılır, sonra yenisi açılır.
    private func present(_ assign: @escaping () -> Void) {
        if memory != nil || asset != nil {
            memory = nil
            asset = nil
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.45) { assign() }
        } else {
            assign()
        }
    }

    static func isDay(_ s: String) -> Bool {
        s.range(of: "^\\d{4}-\\d{2}-\\d{2}$", options: .regularExpression) != nil
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
