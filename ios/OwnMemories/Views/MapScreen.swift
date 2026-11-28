import MapKit
import SwiftUI

/// GET /v1/assets/map → konumlu öğeler.
struct MapPointsResponse: Decodable {
    struct Point: Decodable { let id: String; let lat: Double; let lng: Double }
    let points: [Point]
}

/// POST /v1/assets/lookup → kimliklerle öğeler (istenen sırada).
private struct LookupResponse: Decodable { let assets: [Asset] }

/// Harita sekmesi: fotoğraflar Apple Haritalar üzerinde, Apple Fotoğraflar gibi küçük resimli kümeler.
/// Kümeye dokununca yakınlaşır; yeterince yakındaysa kümedeki fotoğraflar görüntüleyicide açılır.
struct MapScreen: View {
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @State private var points: [MapPointsResponse.Point] = []
    @State private var state: LoadState = .loading
    @State private var viewing: ViewerTarget?
    @State private var opening = false

    enum LoadState { case loading, ready, empty, failed }

    struct ViewerTarget: Identifiable {
        let assets: [Asset]
        let startId: String
        var id: String { startId }
    }

    var body: some View {
        NavigationStack {
            ZStack(alignment: .top) {
                theme.canvas.ignoresSafeArea()
                switch state {
                case .ready:
                    PhotoMap(points: points, theme: theme) { ids in open(ids) }
                        .ignoresSafeArea(edges: [.horizontal, .bottom])
                case .loading:
                    ProgressView().tint(theme.muted).frame(maxWidth: .infinity, maxHeight: .infinity)
                case .empty:
                    message("Konum bilgisi olan fotoğraf yok.", detail: "Konumu kayıtlı fotoğraflar yedeklendikçe burada görünür.")
                case .failed:
                    VStack(spacing: 12) {
                        message("Harita yüklenemedi.", detail: "Sunucuya ulaşılamıyor olabilir.")
                        Button("Tekrar dene") { Task { await load() } }
                    }
                }
                header
                if opening {
                    ProgressView()
                        .padding(14)
                        .background(.ultraThinMaterial, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                }
            }
            .toolbar(.hidden, for: .navigationBar)
            .task { if points.isEmpty { await load() } }
            .fullScreenCover(item: $viewing) { t in
                AssetViewer(assets: t.assets, startId: t.startId, onChange: { _ in })
                    .environmentObject(session)
                    .environment(\.theme, theme)
            }
        }
    }

    /// Haritanın üstünde temaya uygun başlık şeridi (cam).
    private var header: some View {
        HStack {
            Text("Harita")
                .font(theme.largeTitle)
                .foregroundStyle(theme.ink)
            Spacer()
            if state == .ready {
                Text("\(points.count) fotoğraf")
                    .font(.system(size: 13, weight: .medium))
                    .foregroundStyle(theme.muted)
                    .monospacedDigit()
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 6)
        .padding(.bottom, 12)
        .background(alignment: .top) {
            Rectangle()
                .fill(.ultraThinMaterial)
                .mask(LinearGradient(colors: [.black, .black, .clear], startPoint: .top, endPoint: .bottom))
                .ignoresSafeArea(edges: .top)
        }
    }

    private func message(_ title: String, detail: String) -> some View {
        VStack(spacing: 6) {
            Text(title).font(theme.title(20)).foregroundStyle(theme.ink)
            Text(detail).font(.footnote).foregroundStyle(theme.muted).multilineTextAlignment(.center)
        }
        .padding(30)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private func load() async {
        guard let client = session.client else { state = .failed; return }
        state = .loading
        do {
            let r: MapPointsResponse = try await client.send("GET", "assets/map")
            points = r.points
            state = r.points.isEmpty ? .empty : .ready
        } catch {
            state = .failed
        }
    }

    /// Kimliklerdeki öğeleri getirip görüntüleyiciyi açar (en fazla 500).
    private func open(_ ids: [String]) {
        guard !ids.isEmpty, !opening, let client = session.client else { return }
        opening = true
        Task {
            struct Body: Encodable { let ids: [String] }
            let r: LookupResponse? = try? await client.send("POST", "assets/lookup", body: Body(ids: Array(ids.prefix(500))))
            opening = false
            if let assets = r?.assets, let first = assets.first {
                viewing = ViewerTarget(assets: assets, startId: first.id)
            }
        }
    }
}

// MARK: - MapKit

private final class PhotoAnnotation: NSObject, MKAnnotation {
    let assetId: String
    let coordinate: CLLocationCoordinate2D
    init(assetId: String, coordinate: CLLocationCoordinate2D) {
        self.assetId = assetId
        self.coordinate = coordinate
    }
}

/// Fotoğraf ya da küme işaretçisi: yuvarlatılmış küçük resim, beyaz çerçeve, kümede sayı rozeti.
private final class PhotoMarkerView: MKAnnotationView {
    static let reuseId = "photo"
    static let clusterReuseId = "photo-cluster"

    private let imageView = UIImageView()
    private let badge = UILabel()
    private var loadingId: String?
    var accent: UIColor = .systemBlue { didSet { badge.backgroundColor = accent; badge.textColor = accent.isLight ? .black : .white } }
    var corner: CGFloat = 12 { didSet { imageView.layer.cornerRadius = corner } }

    override init(annotation: MKAnnotation?, reuseIdentifier: String?) {
        super.init(annotation: annotation, reuseIdentifier: reuseIdentifier)
        let side: CGFloat = 54
        frame = CGRect(x: 0, y: 0, width: side, height: side)
        centerOffset = .zero
        imageView.frame = bounds
        imageView.contentMode = .scaleAspectFill
        imageView.clipsToBounds = true
        imageView.layer.cornerRadius = corner
        imageView.layer.borderWidth = 2
        imageView.layer.borderColor = UIColor.white.cgColor
        imageView.backgroundColor = UIColor(white: 0.8, alpha: 1)
        layer.shadowColor = UIColor.black.cgColor
        layer.shadowOpacity = 0.3
        layer.shadowRadius = 6
        layer.shadowOffset = CGSize(width: 0, height: 3)
        addSubview(imageView)
        badge.font = .systemFont(ofSize: 12, weight: .bold)
        badge.textAlignment = .center
        badge.layer.cornerRadius = 11
        badge.layer.masksToBounds = true
        badge.backgroundColor = accent
        badge.textColor = .white
        badge.isHidden = true
        addSubview(badge)
        collisionMode = .rectangle
    }

    required init?(coder: NSCoder) { fatalError("init(coder:) kullanılmaz") }

    override func prepareForReuse() {
        super.prepareForReuse()
        imageView.image = nil
        loadingId = nil
        badge.isHidden = true
    }

    func configure(assetId: String, count: Int, client: APIClient?) {
        if count > 1 {
            badge.text = count >= 1000 ? "\(count / 1000)B+" : "\(count)"
            let w = max(22, badge.intrinsicContentSize.width + 12)
            badge.frame = CGRect(x: bounds.width - w + 8, y: -8, width: w, height: 22)
            badge.isHidden = false
        } else {
            badge.isHidden = true
        }
        accessibilityLabel = count > 1 ? "\(count) fotoğraflık küme" : "Fotoğraf"
        isAccessibilityElement = true
        guard loadingId != assetId, let client else { return }
        loadingId = assetId
        let url = client.url("assets/\(assetId)/thumb")
        Task { @MainActor [weak self] in
            let img = await ImageLoader.shared.image(for: url, client: client)
            guard let self, self.loadingId == assetId else { return }
            self.imageView.image = img
        }
    }
}

private extension UIColor {
    var isLight: Bool {
        var w: CGFloat = 0
        _ = getWhite(&w, alpha: nil)
        return w > 0.7
    }
}

private struct PhotoMap: UIViewRepresentable {
    let points: [MapPointsResponse.Point]
    let theme: AppTheme
    var onOpen: ([String]) -> Void
    @EnvironmentObject private var session: Session

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    func makeUIView(context: Context) -> MKMapView {
        let map = MKMapView()
        map.delegate = context.coordinator
        map.register(PhotoMarkerView.self, forAnnotationViewWithReuseIdentifier: PhotoMarkerView.reuseId)
        map.register(PhotoMarkerView.self, forAnnotationViewWithReuseIdentifier: PhotoMarkerView.clusterReuseId)
        map.pointOfInterestFilter = .excludingAll
        map.showsCompass = false
        map.isRotateEnabled = false
        map.isPitchEnabled = false
        let config = MKStandardMapConfiguration(elevationStyle: .flat, emphasisStyle: theme.name == .galeri ? .muted : .default)
        config.pointOfInterestFilter = .excludingAll
        map.preferredConfiguration = config
        context.coordinator.client = session.client
        context.coordinator.load(points, into: map)
        return map
    }

    func updateUIView(_ map: MKMapView, context: Context) {
        context.coordinator.parent = self
        context.coordinator.client = session.client
        // Sinema koyu, Galeri açık; Yerel sistem görünümünü izler.
        switch theme.name {
        case .sinema: map.overrideUserInterfaceStyle = .dark
        case .galeri: map.overrideUserInterfaceStyle = .light
        case .yerel: map.overrideUserInterfaceStyle = .unspecified
        }
        if context.coordinator.loadedCount != points.count { context.coordinator.load(points, into: map) }
        for ann in map.annotations {
            if let v = map.view(for: ann) as? PhotoMarkerView { context.coordinator.style(v) }
        }
    }

    @MainActor
    final class Coordinator: NSObject, MKMapViewDelegate {
        var parent: PhotoMap
        var client: APIClient?
        var loadedCount = -1

        init(_ parent: PhotoMap) { self.parent = parent }

        func load(_ points: [MapPointsResponse.Point], into map: MKMapView) {
            map.removeAnnotations(map.annotations)
            let anns = points.map { PhotoAnnotation(assetId: $0.id, coordinate: CLLocationCoordinate2D(latitude: $0.lat, longitude: $0.lng)) }
            map.addAnnotations(anns)
            loadedCount = points.count
            if !anns.isEmpty {
                var rect = MKMapRect.null
                for a in anns {
                    let p = MKMapPoint(a.coordinate)
                    rect = rect.union(MKMapRect(x: p.x, y: p.y, width: 0.1, height: 0.1))
                }
                map.setVisibleMapRect(rect, edgePadding: UIEdgeInsets(top: 110, left: 50, bottom: 60, right: 50), animated: false)
            }
        }

        func style(_ v: PhotoMarkerView) {
            let t = parent.theme
            v.accent = UIColor(t.accent)
            v.corner = t.name == .galeri ? 3 : 12
        }

        func mapView(_ mapView: MKMapView, viewFor annotation: MKAnnotation) -> MKAnnotationView? {
            if let cluster = annotation as? MKClusterAnnotation {
                let v = mapView.dequeueReusableAnnotationView(withIdentifier: PhotoMarkerView.clusterReuseId, for: annotation) as! PhotoMarkerView
                style(v)
                let first = cluster.memberAnnotations.compactMap { $0 as? PhotoAnnotation }.first
                v.configure(assetId: first?.assetId ?? "", count: cluster.memberAnnotations.count, client: client)
                v.displayPriority = .required
                return v
            }
            guard let photo = annotation as? PhotoAnnotation else { return nil }
            let v = mapView.dequeueReusableAnnotationView(withIdentifier: PhotoMarkerView.reuseId, for: annotation) as! PhotoMarkerView
            v.clusteringIdentifier = "photos"
            v.displayPriority = .defaultHigh
            style(v)
            v.configure(assetId: photo.assetId, count: 1, client: client)
            return v
        }

        func mapView(_ mapView: MKMapView, didSelect annotation: MKAnnotation) {
            mapView.deselectAnnotation(annotation, animated: false)
            if let cluster = annotation as? MKClusterAnnotation {
                let members = cluster.memberAnnotations.compactMap { $0 as? PhotoAnnotation }
                // Üyeler farklı yerlerdeyse ve henüz çok yakın değilsek: kümeyi kapsayacak şekilde yakınlaş.
                var rect = MKMapRect.null
                for m in members {
                    let p = MKMapPoint(m.coordinate)
                    rect = rect.union(MKMapRect(x: p.x, y: p.y, width: 0.1, height: 0.1))
                }
                let spreadMeters = MKMapPoint(x: rect.minX, y: rect.minY).distance(to: MKMapPoint(x: rect.maxX, y: rect.maxY))
                let canZoom = spreadMeters > 30 && mapView.region.span.latitudeDelta > 0.002
                if canZoom {
                    mapView.setVisibleMapRect(rect, edgePadding: UIEdgeInsets(top: 140, left: 70, bottom: 90, right: 70), animated: true)
                } else {
                    parent.onOpen(members.map(\.assetId))
                }
                return
            }
            if let photo = annotation as? PhotoAnnotation {
                parent.onOpen([photo.assetId])
            }
        }
    }
}
