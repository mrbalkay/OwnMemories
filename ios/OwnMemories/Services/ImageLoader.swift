import SwiftUI
import UIKit

/// Bir görsel isteğinin sonucu. `pending`: sunucu küçük resmi henüz üretmedi (HTTP 202, yer tutucu SVG).
enum ImageResult: @unchecked Sendable {
    case image(UIImage)
    case pending
    case failed
}

/// Bearer başlıklı görsel yükleyici + bellek önbelleği (küçük resimler ve önizlemeler).
actor ImageLoader {
    static let shared = ImageLoader()
    private let cache = NSCache<NSURL, UIImage>()
    private var inflight: [URL: Task<ImageResult, Never>] = [:]
    private let session: URLSession = {
        let c = URLSessionConfiguration.default
        c.urlCache = URLCache(memoryCapacity: 32 << 20, diskCapacity: 512 << 20)
        c.requestCachePolicy = .returnCacheDataElseLoad
        return URLSession(configuration: c)
    }()

    init() {
        cache.countLimit = 600
        // Çözülmüş bit eşlem boyutuyla sınırlanır (önizlemeler tek başına onlarca MB olabilir).
        cache.totalCostLimit = 120 << 20
    }

    /// Bellek önbelleğini boşaltır (arka plana geçişte ve bellek uyarısında; arka planda büyük bellek
    /// kullanan uygulamayı iOS ilk sonlandırır).
    func purge() {
        cache.removeAllObjects()
    }

    private static func cost(of image: UIImage) -> Int {
        let w = image.size.width * image.scale, h = image.size.height * image.scale
        return max(1, Int(w * h * 4))
    }

    func image(for url: URL, client: APIClient) async -> UIImage? {
        if case .image(let img) = await load(url, client: client) { return img }
        return nil
    }

    /// Görseli getirir; küçük resim sunucuda henüz hazır değilse `.pending` döner (önbelleğe alınmaz).
    func load(_ url: URL, client: APIClient) async -> ImageResult {
        if let img = cache.object(forKey: url as NSURL) { return .image(img) }
        if let t = inflight[url] { return await t.value }
        let req = client.authorized(url)
        let task = Task<ImageResult, Never> {
            guard let (data, resp) = try? await session.data(for: req),
                  let http = resp as? HTTPURLResponse else { return .failed }
            if http.statusCode == 202 { return .pending }
            guard http.statusCode == 200, let img = UIImage(data: data) else { return .failed }
            return .image(img)
        }
        inflight[url] = task
        let result = await task.value
        inflight[url] = nil
        if case .image(let img) = result { cache.setObject(img, forKey: url as NSURL, cost: Self.cost(of: img)) }
        return result
    }
}

/// Sunucudaki bir asset'in küçük resmini/önizlemesini gösterir.
///
/// Yeni yüklenen fotoğrafların küçük resmi sunucuda birkaç saniye sonra üretilir; o sırada gelen 202
/// yanıtında artan aralıklarla yeniden dener, böylece liste yenilenmeden de resim kendiliğinden belirir.
struct RemoteImage: View {
    let assetId: String
    var variant: String = "thumb"
    var contentMode: ContentMode = .fill
    /// false: yüklenene kadar saydam kalır (alttaki küçük resmi örtmemesi için).
    var showsPlaceholder: Bool = true
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @State private var image: UIImage?

    var body: some View {
        ZStack {
            if let image {
                Image(uiImage: image).resizable().aspectRatio(contentMode: contentMode)
            } else if showsPlaceholder {
                Rectangle().fill(theme.placeholder)
            } else {
                Color.clear
            }
        }
        .task(id: assetId + variant) {
            guard let client = session.client else { return }
            let url = client.url("assets/\(assetId)/\(variant)")
            var delay: Double = 2
            var failures = 0
            for _ in 0..<14 {
                switch await ImageLoader.shared.load(url, client: client) {
                case .image(let img):
                    image = img
                    return
                case .pending:
                    break
                case .failed:
                    failures += 1
                    if failures > 2 { return }
                }
                try? await Task.sleep(for: .seconds(delay))
                if Task.isCancelled { return }
                delay = min(delay * 1.5, 15)
            }
        }
    }
}
