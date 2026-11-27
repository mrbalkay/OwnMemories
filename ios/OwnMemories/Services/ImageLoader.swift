import SwiftUI
import UIKit

/// Bearer başlıklı görsel yükleyici + bellek önbelleği (küçük resimler ve önizlemeler).
actor ImageLoader {
    static let shared = ImageLoader()
    private let cache = NSCache<NSURL, UIImage>()
    private var inflight: [URL: Task<UIImage?, Never>] = [:]
    private let session: URLSession = {
        let c = URLSessionConfiguration.default
        c.urlCache = URLCache(memoryCapacity: 32 << 20, diskCapacity: 512 << 20)
        c.requestCachePolicy = .returnCacheDataElseLoad
        return URLSession(configuration: c)
    }()

    init() { cache.countLimit = 600 }

    func image(for url: URL, client: APIClient) async -> UIImage? {
        if let img = cache.object(forKey: url as NSURL) { return img }
        if let t = inflight[url] { return await t.value }
        let req = client.authorized(url)
        let task = Task<UIImage?, Never> {
            guard let (data, resp) = try? await session.data(for: req),
                  let http = resp as? HTTPURLResponse, http.statusCode == 200 else { return nil }
            return UIImage(data: data)
        }
        inflight[url] = task
        let img = await task.value
        inflight[url] = nil
        if let img { cache.setObject(img, forKey: url as NSURL) }
        return img
    }
}

/// Sunucudaki bir asset'in küçük resmini/önizlemesini gösterir.
struct RemoteImage: View {
    let assetId: String
    var variant: String = "thumb"
    var contentMode: ContentMode = .fill
    @EnvironmentObject private var session: Session
    @State private var image: UIImage?

    var body: some View {
        ZStack {
            if let image {
                Image(uiImage: image).resizable().aspectRatio(contentMode: contentMode)
            } else {
                Rectangle().fill(Color(.secondarySystemBackground))
            }
        }
        .task(id: assetId + variant) {
            guard let client = session.client else { return }
            image = await ImageLoader.shared.image(for: client.url("assets/\(assetId)/\(variant)"), client: client)
        }
    }
}
