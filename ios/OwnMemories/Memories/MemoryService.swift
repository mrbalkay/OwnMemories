import Foundation
import UIKit
import WidgetKit

/// Bugünün anılarını sunucudan alır, kitaplıktaki şerit için yayınlar ve widget önbelleğini günceller.
@MainActor
final class MemoryService: ObservableObject {
    static let shared = MemoryService()

    @Published private(set) var memories: [Memory] = []
    private var lastRefresh: Date?

    func refresh(force: Bool = false) async {
        if !force, let last = lastRefresh, Date().timeIntervalSince(last) < 15 * 60 { return }
        guard let client = Session.storedClient() else { return }
        guard let r: MemoriesResponse = try? await client.send("GET", "memories") else { return }
        memories = r.memories
        lastRefresh = Date()
        let randoms: AssetList? = try? await client.send("GET", "assets/random", query: ["count": "12"])
        await updateWidgets(memories: r.memories, randoms: randoms?.assets ?? [], client: client)
    }

    private func updateWidgets(memories: [Memory], randoms: [Asset], client: APIClient) async {
        guard WidgetStore.directory != nil else { return }
        var snapshot = WidgetSnapshot(updatedAt: Date(), memories: [], randoms: [])
        for m in memories.prefix(3) {
            var photos: [WidgetPhoto] = []
            let picks = [m.assets.first { $0.id == m.coverAssetId }].compactMap { $0 } + m.assets.filter { !$0.isVideo && $0.id != m.coverAssetId }
            for a in picks.prefix(6) {
                if let file = await cacheImage(a, client: client) { photos.append(WidgetPhoto(assetId: a.id, file: file, caption: Self.caption(a))) }
            }
            if !photos.isEmpty { snapshot.memories.append(WidgetMemory(id: m.id, date: m.date, title: m.title, photos: photos)) }
        }
        for a in randoms.prefix(12) {
            if let file = await cacheImage(a, client: client) { snapshot.randoms.append(WidgetPhoto(assetId: a.id, file: file, caption: Self.caption(a))) }
        }
        WidgetStore.save(snapshot)
        WidgetCenter.shared.reloadAllTimelines()
    }

    /// Önizlemeyi indirip widget için ~800 px'e küçültür; dosya adı döner.
    private func cacheImage(_ a: Asset, client: APIClient) async -> String? {
        let name = a.id + ".jpg"
        guard let dir = WidgetStore.directory else { return nil }
        let url = dir.appendingPathComponent(name)
        if FileManager.default.fileExists(atPath: url.path) { return name }
        guard let img = await ImageLoader.shared.image(for: client.url("assets/\(a.id)/preview"), client: client) else { return nil }
        let maxEdge: CGFloat = 800
        let scale = min(1, maxEdge / max(img.size.width, img.size.height))
        let size = CGSize(width: img.size.width * scale, height: img.size.height * scale)
        let resized = UIGraphicsImageRenderer(size: size).image { _ in img.draw(in: CGRect(origin: .zero, size: size)) }
        guard let data = resized.jpegData(compressionQuality: 0.8) else { return nil }
        try? data.write(to: url, options: .atomic)
        return name
    }

    static func caption(_ a: Asset) -> String {
        guard let local = a.takenLocal, let c = ServerDate.localComponents(local) else { return "" }
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = TimeZone(identifier: "UTC")!
        guard let d = cal.date(from: c) else { return "" }
        let f = DateFormatter()
        f.locale = Locale(identifier: "tr_TR")
        f.timeZone = cal.timeZone
        f.setLocalizedDateFormatFromTemplate("dMMMMyyyy")
        return f.string(from: d)
    }
}
