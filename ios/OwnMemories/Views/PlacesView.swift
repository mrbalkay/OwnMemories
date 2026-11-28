import SwiftUI

// Yerler: Harita sekmesinin "Yerler" görünümü. Şehir kartları ülkelere göre gruplu (GET /v1/places);
// karta dokununca o yerin fotoğrafları (GET /v1/assets?place=<id>). Yer adları sunucuda çevrimdışı bulunur
// (GeoNames); uygulama yalnızca sonuçları gösterir.

@MainActor
final class PlacesModel: ObservableObject {
    enum LoadState: Equatable { case loading, ready, empty, failed }
    @Published private(set) var groups: [PlaceCountryGroup] = []
    @Published private(set) var state: LoadState = .loading
    @Published private(set) var pending = 0
    @Published private(set) var dataReady = true
    private(set) var loadedAt: Date?

    var placeCount: Int { groups.reduce(0) { $0 + $1.places.count } }

    func load(client: APIClient?, force: Bool = false) async {
        guard let client else { state = .failed; return }
        if !force, let loadedAt, Date().timeIntervalSince(loadedAt) < 30, state == .ready { return }
        if groups.isEmpty { state = .loading }
        do {
            let r: PlacesResponse = try await client.send("GET", "places")
            groups = PlaceText.group(r.places)
            pending = r.pending ?? 0
            dataReady = r.ready ?? true
            state = r.places.isEmpty ? .empty : .ready
            loadedAt = Date()
        } catch {
            if groups.isEmpty { state = .failed }
        }
    }
}

/// Yerler ızgarası (Harita sekmesinde). `topInset`: üstteki cam başlığın yüksekliği.
struct PlacesListView: View {
    @ObservedObject var model: PlacesModel
    var topInset: CGFloat
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme

    private var columns: [GridItem] {
        Array(repeating: GridItem(.flexible(), spacing: theme.name == .galeri ? 14 : 12, alignment: .top), count: 2)
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Color.clear.frame(height: topInset)
                switch model.state {
                case .loading:
                    ProgressView().tint(theme.muted).frame(maxWidth: .infinity).padding(.top, 80)
                case .failed:
                    VStack(spacing: 12) {
                        message("Yerler yüklenemedi.", detail: "Sunucuya ulaşılamıyor olabilir.")
                        Button("Tekrar dene") { Task { await model.load(client: session.client, force: true) } }
                            .foregroundStyle(theme.name == .yerel ? theme.accent : theme.ink)
                    }
                    .frame(maxWidth: .infinity)
                case .empty:
                    VStack(spacing: 14) {
                        Image(systemName: "mappin.and.ellipse")
                            .font(.system(size: 34, weight: .light))
                            .foregroundStyle(theme.name == .yerel ? theme.accent : theme.muted)
                        message("Henüz yer yok",
                                detail: model.dataReady
                                    ? "Konum bilgisi olan fotoğraflar burada şehir şehir toplanır. Yer adları sunucunuzda bulunur; konumunuz hiçbir dış servise gönderilmez."
                                    : "Yer adı verisi sunucuda henüz kurulmamış. Sunucuda: sudo bash server/install-geo.sh")
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.top, 60)
                case .ready:
                    if model.pending > 0 { pendingBadge.padding(.horizontal, 16).padding(.bottom, 6) }
                    ForEach(model.groups) { g in
                        section(g)
                    }
                    Text("Yer adları: GeoNames (CC BY 4.0)")
                        .font(.system(size: 11))
                        .foregroundStyle(theme.muted.opacity(0.8))
                        .frame(maxWidth: .infinity)
                        .padding(.top, 30)
                }
            }
            .padding(.bottom, 32)
        }
        .scrollIndicators(.hidden)
        .refreshable { await model.load(client: session.client, force: true) }
        .task { await model.load(client: session.client) }
    }

    private func section(_ g: PlaceCountryGroup) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(alignment: .firstTextBaseline, spacing: 8) {
                Text(g.name)
                    .font(theme.title(theme.isSerif ? 24 : 22))
                    .foregroundStyle(theme.ink)
                Text("\(g.places.count) yer")
                    .font(.system(size: 13))
                    .foregroundStyle(theme.muted)
                    .monospacedDigit()
            }
            .accessibilityElement(children: .combine)
            .accessibilityAddTraits(.isHeader)
            .padding(.horizontal, 16)
            LazyVGrid(columns: columns, spacing: theme.name == .galeri ? 24 : 18) {
                ForEach(g.places) { p in
                    NavigationLink(value: p) { PlaceCard(place: p) }
                        .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 16)
        }
        .padding(.top, 18)
    }

    private var pendingBadge: some View {
        HStack(spacing: 8) {
            ProgressView().controlSize(.mini).tint(theme.muted)
            Text("\(PeopleFormat.number(model.pending)) fotoğrafın yeri bulunuyor…")
                .font(.system(size: 12, weight: .medium))
                .foregroundStyle(theme.muted)
        }
        .padding(.horizontal, 12).padding(.vertical, 7)
        .background(theme.surface.opacity(theme.name == .sinema ? 0.6 : 1), in: Capsule())
    }

    private func message(_ title: String, detail: String) -> some View {
        VStack(spacing: 6) {
            Text(title).font(theme.title(20)).foregroundStyle(theme.ink)
            Text(detail).font(.footnote).foregroundStyle(theme.muted).multilineTextAlignment(.center)
        }
        .padding(.horizontal, 30)
    }
}

/// Şehir kartı: Sinema afiş (yazı fotoğrafın üstünde), Galeri kare baskı + sergi etiketi, Yerel yuvarlatılmış kare + alt yazı.
struct PlaceCard: View {
    let place: PlaceSummary
    @Environment(\.theme) private var theme

    var body: some View {
        Group {
            if theme.name == .sinema { poster } else { tile }
        }
        .contentShape(Rectangle())
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(Text(verbatim: [place.name, place.subtitle, PlaceText.items(place.count)].compactMap { $0 }.joined(separator: ", ")))
        .accessibilityAddTraits(.isButton)
    }

    private var cover: some View {
        theme.placeholder
            .overlay {
                if let id = place.coverAssetId { RemoteImage(assetId: id, variant: "preview") }
            }
            .clipped()
    }

    private var meta: String {
        [place.subtitle, PlaceText.items(place.count)].compactMap { $0 }.joined(separator: " · ")
    }

    /// Sinema: 4:5 afiş, alttan karartma, serif ad.
    private var poster: some View {
        cover
            .aspectRatio(4 / 5, contentMode: .fit)
            .overlay {
                LinearGradient(colors: [.black.opacity(0.85), .black.opacity(0.25), .clear], startPoint: .bottom, endPoint: .center)
            }
            .overlay(alignment: .bottomLeading) {
                VStack(alignment: .leading, spacing: 3) {
                    Text(place.name)
                        .font(.system(size: 21, design: .serif))
                        .foregroundStyle(theme.ink)
                        .lineLimit(2)
                        .shadow(color: .black.opacity(0.5), radius: 6, y: 1)
                    Text(meta)
                        .font(.system(size: 11.5))
                        .foregroundStyle(theme.ink.opacity(0.72))
                        .lineLimit(1)
                }
                .padding(12)
            }
            .overlay(alignment: .topLeading) { regionBadge.padding(10) }
            .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(theme.hairline, lineWidth: 1))
    }

    /// Galeri / Yerel: kare kapak, altında ad ve sayı.
    private var tile: some View {
        VStack(alignment: .leading, spacing: theme.name == .galeri ? 9 : 6) {
            cover
                .aspectRatio(1, contentMode: .fit)
                .overlay(alignment: .topLeading) { regionBadge.padding(theme.name == .galeri ? 0 : 8) }
                .clipShape(RoundedRectangle(cornerRadius: theme.name == .galeri ? 2 : 12, style: .continuous))
            VStack(alignment: .leading, spacing: 2) {
                Text(place.name)
                    .font(theme.name == .galeri ? .system(size: 17, design: .serif) : .system(size: 15, weight: .semibold))
                    .foregroundStyle(theme.ink)
                    .lineLimit(1)
                Text(theme.name == .galeri ? meta.uppercased(with: Locale(identifier: "tr_TR")) : meta)
                    .font(.system(size: theme.name == .galeri ? 10.5 : 13))
                    .tracking(theme.name == .galeri ? 0.7 : 0)
                    .foregroundStyle(theme.muted)
                    .lineLimit(1)
            }
        }
    }

    @ViewBuilder
    private var regionBadge: some View {
        if let r = place.region, PlaceText.fold(r) != PlaceText.fold(place.name) {
            let text = theme.name == .galeri ? r.uppercased(with: Locale(identifier: "tr_TR")) : r
            Text(text)
                .font(.system(size: theme.name == .galeri ? 9.5 : 11, weight: .semibold))
                .tracking(theme.name == .galeri ? 1 : 0)
                .foregroundStyle(theme.name == .galeri ? theme.ink : .white)
                .padding(.horizontal, 8).padding(.vertical, 4)
                .background {
                    if theme.name == .galeri { Rectangle().fill(theme.surface) } else { Capsule().fill(.black.opacity(0.5)) }
                }
        }
    }
}

// MARK: - Yer sayfası

@MainActor
final class PlacePhotosModel: ObservableObject {
    @Published private(set) var assets: [Asset] = []
    @Published private(set) var loading = false
    @Published private(set) var error: String?
    private var next: AssetCursor?
    private(set) var done = false
    private(set) var placeId: Int?

    func reload(placeId: Int, client: APIClient?) async {
        self.placeId = placeId
        next = nil
        done = false
        assets = []
        error = nil
        await loadMore(client: client)
    }

    func loadMore(client: APIClient?) async {
        guard let client, let placeId, !loading, !done else { return }
        loading = true
        var q = ["limit": "200", "place": String(placeId)]
        if let next {
            q["before"] = next.before
            if let id = next.beforeId { q["before_id"] = id }
        }
        do {
            let page: AssetPage = try await client.send("GET", "assets", query: q)
            assets.append(contentsOf: page.assets)
            next = page.next
            done = page.next == nil
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
        loading = false
    }

    func update(_ asset: Asset) {
        if let i = assets.firstIndex(where: { $0.id == asset.id }) { assets[i] = asset }
    }
}

/// Bir yerin fotoğrafları: başlık (bölge, ad, il/ülke, sayı, tarih aralığı) + ızgara + görüntüleyici.
struct PlaceView: View {
    let place: PlaceSummary
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @StateObject private var photos = PlacePhotosModel()
    @State private var detail: PlaceSummary?
    @State private var viewing: Asset?

    private var columns: [GridItem] {
        Array(repeating: GridItem(.flexible(), spacing: theme.gridSpacing), count: theme.gridColumns)
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 0) {
                header
                    .padding(.horizontal, 20)
                    .padding(.top, 10)
                    .padding(.bottom, 20)
                LazyVGrid(columns: columns, spacing: theme.gridSpacing) {
                    ForEach(photos.assets) { asset in
                        Button { viewing = asset } label: { PlacePhotoTile(asset: asset) }
                            .buttonStyle(.plain)
                            .onAppear {
                                if asset.id == photos.assets.last?.id { Task { await photos.loadMore(client: session.client) } }
                            }
                    }
                }
                if photos.loading {
                    ProgressView().tint(theme.muted).frame(maxWidth: .infinity).padding()
                } else if let err = photos.error {
                    VStack(spacing: 10) {
                        Text(err).multilineTextAlignment(.center).foregroundStyle(theme.muted)
                        Button("Tekrar dene") { Task { await photos.loadMore(client: session.client) } }
                            .foregroundStyle(theme.name == .yerel ? theme.accent : theme.ink)
                    }
                    .frame(maxWidth: .infinity).padding(30)
                }
            }
            .padding(.bottom, 28)
        }
        .foregroundStyle(theme.ink)
        .background(theme.canvas.ignoresSafeArea())
        .navigationTitle(place.name)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar(.visible, for: .navigationBar)
        .refreshable { await photos.reload(placeId: place.id, client: session.client) }
        .task(id: place.id) {
            if photos.placeId != place.id { await photos.reload(placeId: place.id, client: session.client) }
            if detail == nil, let client = session.client {
                detail = (try? await client.send("GET", "places/\(place.id)", as: PlaceEnvelope.self))?.place
            }
        }
        .fullScreenCover(item: $viewing) { asset in
            AssetViewer(assets: photos.assets, startId: asset.id, onChange: { a in photos.update(a) })
                .environmentObject(session)
                .environment(\.theme, theme)
        }
    }

    private var header: some View {
        let p = detail ?? place
        let whereText = [p.subtitle, p.country].compactMap { $0 }.joined(separator: ", ")
        return VStack(spacing: 6) {
            if let r = p.region, PlaceText.fold(r) != PlaceText.fold(p.name) {
                Text(theme.name == .yerel ? r : r.uppercased(with: Locale(identifier: "tr_TR")))
                    .font(.system(size: 12, weight: .semibold))
                    .tracking(theme.name == .yerel ? 0 : 1.2)
                    .foregroundStyle(theme.name == .sinema ? theme.success : theme.accent)
            }
            Text(p.name)
                .font(theme.title(34))
                .foregroundStyle(theme.ink)
                .multilineTextAlignment(.center)
                .lineLimit(2)
            HStack(spacing: 6) {
                if !whereText.isEmpty {
                    Label(whereText, systemImage: "mappin.and.ellipse")
                        .labelStyle(.titleAndIcon)
                }
                Text("·")
                Text(PlaceText.items(p.count)).monospacedDigit()
            }
            .font(.system(size: 13))
            .foregroundStyle(theme.muted)
            if let range = dateRange(p) {
                Text(range).font(.system(size: 13)).foregroundStyle(theme.muted)
            }
        }
        .frame(maxWidth: .infinity)
        .accessibilityElement(children: .combine)
    }

    /// "25 Kasım 2023 – 30 Kasım 2023" (tek günse tek tarih).
    private func dateRange(_ p: PlaceSummary) -> String? {
        guard let a = p.firstAt, let b = p.lastAt else { return nil }
        let iso = ISO8601DateFormatter()
        guard let da = iso.date(from: a), let db = iso.date(from: b) else { return nil }
        let f = DateFormatter()
        f.locale = Locale(identifier: "tr_TR")
        f.setLocalizedDateFormatFromTemplate("dMMMMyyyy")
        let (sa, sb) = (f.string(from: da), f.string(from: db))
        return sa == sb ? sa : "\(sa) – \(sb)"
    }
}

/// Yer sayfası ızgara hücresi (kitaplık ızgarasıyla aynı görünüm).
private struct PlacePhotoTile: View {
    let asset: Asset
    @Environment(\.theme) private var theme

    var body: some View {
        theme.placeholder
            .aspectRatio(1, contentMode: .fit)
            .overlay(RemoteImage(assetId: asset.id))
            .clipShape(RoundedRectangle(cornerRadius: theme.tileRadius, style: .continuous))
            .overlay(alignment: .topTrailing) {
                HStack(spacing: 4) {
                    if asset.isVideo { Image(systemName: "play.fill") }
                    if asset.isFavorite { Image(systemName: "heart.fill") }
                }
                .font(.system(size: 10, weight: .semibold))
                .foregroundStyle(.white)
                .shadow(color: .black.opacity(0.6), radius: 2, y: 1)
                .padding(5)
            }
            .contentShape(Rectangle())
            .accessibilityLabel(Text(asset.isVideo ? "Video" : "Fotoğraf"))
            .accessibilityAddTraits(.isButton)
    }
}
