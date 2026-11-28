import MapKit
import SwiftUI

// Geziler ve etkinlikler: Harita sekmesinin "Geziler" görünümü. Sunucu fotoğrafların konum ve zamanından gezileri
// ("Bozcaada gezisi", "İtalya: Roma, Floransa ve Venedik") ve etkinlikleri (yoğun çekim, günübirlik çıkış) bulur
// (GET /v1/trips). Karta dokununca gezinin fotoğrafları (GET /v1/assets?trip=<id>) → görüntüleyici.
// Gezi sayfasında: Albüme dönüştür, Yeniden adlandır, Gizle.

@MainActor
final class TripsModel: ObservableObject {
    enum LoadState: Equatable { case loading, ready, empty, failed }
    @Published private(set) var trips: [TripSummary] = []
    @Published private(set) var state: LoadState = .loading
    @Published private(set) var hidden = 0
    private(set) var loadedAt: Date?

    var tripCount: Int { trips.filter { !$0.isEvent }.count }
    var eventCount: Int { trips.filter { $0.isEvent }.count }
    /// Geziler yıllara göre (en yeni yıl önce).
    struct YearGroup: Identifiable {
        let year: String
        let trips: [TripSummary]
        var id: String { year }
    }

    var years: [YearGroup] {
        let list = trips.filter { !$0.isEvent }
        var order: [String] = []
        var by: [String: [TripSummary]] = [:]
        for t in list {
            if by[t.year] == nil { order.append(t.year) }
            by[t.year, default: []].append(t)
        }
        return order.map { YearGroup(year: $0, trips: by[$0] ?? []) }
    }
    var events: [TripSummary] { trips.filter { $0.isEvent } }

    func load(client: APIClient?, force: Bool = false) async {
        guard let client else { state = .failed; return }
        if !force, let loadedAt, Date().timeIntervalSince(loadedAt) < 30, state == .ready { return }
        if trips.isEmpty { state = .loading }
        do {
            let r: TripsResponse = try await client.send("GET", "trips")
            trips = r.trips
            hidden = r.hidden ?? 0
            state = r.trips.isEmpty ? .empty : .ready
            loadedAt = Date()
        } catch {
            if trips.isEmpty { state = .failed }
        }
    }

    func invalidate() { loadedAt = nil }
}

/// Geziler listesi (Harita sekmesinde). `topInset`: üstteki cam başlığın yüksekliği.
struct TripsListView: View {
    @ObservedObject var model: TripsModel
    var topInset: CGFloat
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme

    private var eventColumns: [GridItem] {
        Array(repeating: GridItem(.flexible(), spacing: 12, alignment: .top), count: 2)
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
                        message("Geziler yüklenemedi.", detail: "Sunucuya ulaşılamıyor olabilir.")
                        Button("Tekrar dene") { Task { await model.load(client: session.client, force: true) } }
                            .foregroundStyle(theme.name == .yerel ? theme.accent : theme.ink)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.top, 40)
                case .empty:
                    VStack(spacing: 14) {
                        Image(systemName: "suitcase")
                            .font(.system(size: 34, weight: .light))
                            .foregroundStyle(theme.name == .yerel ? theme.accent : theme.muted)
                        message("Henüz gezi yok",
                                detail: "Konumlu fotoğraflarınızdan evden uzakta geçen günler ve kalabalık anlar burada kendiliğinden albüm olur.")
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.top, 60)
                case .ready:
                    ForEach(model.years) { group in
                        VStack(alignment: .leading, spacing: 12) {
                            Text(group.year)
                                .font(theme.name == .yerel ? .system(size: 20, weight: .bold) : .system(size: 13, weight: .semibold))
                                .tracking(theme.name == .yerel ? 0 : 1.6)
                                .foregroundStyle(theme.name == .yerel ? theme.ink : theme.muted)
                                .monospacedDigit()
                                .accessibilityAddTraits(.isHeader)
                            ForEach(group.trips) { t in
                                NavigationLink(value: t) { TripCard(trip: t, large: true) }
                                    .buttonStyle(.plain)
                            }
                        }
                        .padding(.horizontal, 16)
                        .padding(.top, 18)
                    }
                    if !model.events.isEmpty {
                        VStack(alignment: .leading, spacing: 12) {
                            Text("Etkinlikler")
                                .font(theme.title(theme.isSerif ? 24 : 22))
                                .foregroundStyle(theme.ink)
                                .accessibilityAddTraits(.isHeader)
                            Text("Kısa sürede çok fotoğraf çektiğiniz günler: doğum günleri, kutlamalar, günübirlik kaçamaklar.")
                                .font(.footnote)
                                .foregroundStyle(theme.muted)
                            LazyVGrid(columns: eventColumns, spacing: 14) {
                                ForEach(model.events) { t in
                                    NavigationLink(value: t) { TripCard(trip: t, large: false) }
                                        .buttonStyle(.plain)
                                }
                            }
                        }
                        .padding(.horizontal, 16)
                        .padding(.top, 30)
                    }
                    if model.hidden > 0 {
                        Text("\(model.hidden) öneri gizlendi. Web'de Geziler sayfasındaki \"Gizlenenler\" bölümünden geri getirebilirsiniz.")
                            .font(.footnote)
                            .foregroundStyle(theme.muted)
                            .multilineTextAlignment(.center)
                            .frame(maxWidth: .infinity)
                            .padding(.horizontal, 30)
                            .padding(.top, 28)
                    }
                }
            }
            .padding(.bottom, 32)
        }
        .scrollIndicators(.hidden)
        .refreshable { await model.load(client: session.client, force: true) }
        .task { await model.load(client: session.client) }
    }

    private func message(_ title: String, detail: String) -> some View {
        VStack(spacing: 6) {
            Text(title).font(theme.title(20)).foregroundStyle(theme.ink)
            Text(detail).font(.footnote).foregroundStyle(theme.muted).multilineTextAlignment(.center)
        }
        .padding(.horizontal, 30)
    }
}

/// Gezi kartı: kapak üstünde karartma, tür · tarih, ad, yerler ve sayı. Galeri'de yazı kapağın altında (sergi etiketi).
struct TripCard: View {
    let trip: TripSummary
    var large: Bool
    @Environment(\.theme) private var theme

    private var radius: CGFloat { theme.name == .galeri ? 2 : (large ? 18 : 14) }
    private var places: String {
        let names = trip.places.prefix(large ? 3 : 2).map(\.name)
        let more = trip.places.count - names.count
        return names.joined(separator: " · ") + (more > 0 ? " +\(more)" : "")
    }
    private var eyebrow: String {
        let k = trip.kindText
        return theme.name == .yerel ? "\(k) · \(trip.rangeText)" : "\(k) · \(trip.rangeText)".uppercased(with: Locale(identifier: "tr_TR"))
    }

    var body: some View {
        Group {
            if theme.name == .galeri { label } else { poster }
        }
        .contentShape(Rectangle())
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(Text(verbatim: "\(trip.displayTitle), \(trip.rangeText), \(trip.count) öğe"))
        .accessibilityAddTraits(.isButton)
    }

    private var cover: some View {
        theme.placeholder
            .overlay {
                if let id = trip.coverAssetId { RemoteImage(assetId: id, variant: large ? "preview" : "thumb") }
            }
            .clipped()
    }

    private var poster: some View {
        cover
            .aspectRatio(large ? 4 / 3 : 3 / 4, contentMode: .fit)
            .overlay {
                LinearGradient(colors: [.black.opacity(0.82), .black.opacity(0.25), .clear], startPoint: .bottom, endPoint: .center)
            }
            .overlay(alignment: .bottomLeading) {
                VStack(alignment: .leading, spacing: large ? 5 : 3) {
                    Text(eyebrow)
                        .font(.system(size: large ? 11.5 : 10, weight: .semibold))
                        .tracking(theme.name == .yerel ? 0 : 0.8)
                        .foregroundStyle(theme.name == .sinema ? theme.success : .white.opacity(0.9))
                        .lineLimit(1)
                    Text(trip.displayTitle)
                        .font(theme.name == .yerel ? .system(size: large ? 26 : 17, weight: .heavy) : .system(size: large ? 28 : 18, design: .serif))
                        .foregroundStyle(.white)
                        .lineLimit(2)
                        .shadow(color: .black.opacity(0.45), radius: 6, y: 1)
                    HStack(spacing: 6) {
                        if !places.isEmpty {
                            Text(places).lineLimit(1)
                        }
                        Spacer(minLength: 4)
                        Text("\(trip.count) öğe").monospacedDigit()
                    }
                    .font(.system(size: large ? 12.5 : 11, weight: .medium))
                    .foregroundStyle(.white.opacity(0.85))
                }
                .padding(large ? 16 : 10)
            }
            .overlay(alignment: .topTrailing) { albumBadge.padding(10) }
            .clipShape(RoundedRectangle(cornerRadius: radius, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: radius, style: .continuous).stroke(theme.hairline, lineWidth: theme.name == .sinema ? 1 : 0))
    }

    private var label: some View {
        VStack(alignment: .leading, spacing: 8) {
            cover
                .aspectRatio(large ? 3 / 2 : 1, contentMode: .fit)
                .overlay(alignment: .topTrailing) { albumBadge.padding(8) }
                .clipShape(RoundedRectangle(cornerRadius: radius, style: .continuous))
            Text(eyebrow)
                .font(.system(size: 10.5, weight: .medium))
                .tracking(1)
                .foregroundStyle(theme.muted)
                .lineLimit(1)
            Text(trip.displayTitle)
                .font(.system(size: large ? 24 : 17, design: .serif))
                .foregroundStyle(theme.ink)
                .lineLimit(2)
            HStack {
                Text(places).lineLimit(1)
                Spacer(minLength: 4)
                Text("\(trip.count) öğe").monospacedDigit()
            }
            .font(.system(size: 12))
            .foregroundStyle(theme.muted)
        }
    }

    @ViewBuilder
    private var albumBadge: some View {
        if trip.albumId != nil {
            Label("Albüm", systemImage: "rectangle.stack")
                .font(.system(size: 11, weight: .semibold))
                .foregroundStyle(theme.name == .galeri ? theme.ink : .white)
                .padding(.horizontal, 8).padding(.vertical, 4)
                .background {
                    if theme.name == .galeri { Rectangle().fill(theme.surface) } else { Capsule().fill(.black.opacity(0.5)) }
                }
        }
    }
}

// MARK: - Gezi sayfası

@MainActor
final class TripPhotosModel: ObservableObject {
    @Published private(set) var assets: [Asset] = []
    @Published private(set) var loading = false
    @Published private(set) var error: String?
    private var next: AssetCursor?
    private(set) var done = false
    private(set) var tripId: Int?

    func reload(tripId: Int, client: APIClient?) async {
        self.tripId = tripId
        next = nil
        done = false
        assets = []
        error = nil
        await loadMore(client: client)
    }

    func loadMore(client: APIClient?) async {
        guard let client, let tripId, !loading, !done else { return }
        loading = true
        var q = ["limit": "200", "trip": String(tripId)]
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

/// Bir gezinin fotoğrafları: başlık (tür, ad, tarih, yerler, rota haritası) + eylemler + ızgara + görüntüleyici.
struct TripView: View {
    let trip: TripSummary
    var onChanged: () -> Void = {}
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @Environment(\.dismiss) private var dismiss
    @StateObject private var photos = TripPhotosModel()
    @State private var detail: TripSummary?
    @State private var viewing: Asset?
    @State private var renaming = false
    @State private var newTitle = ""
    @State private var confirmHide = false
    @State private var busy = false
    @State private var notice: String?

    private var current: TripSummary { detail ?? trip }
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
                        Button { viewing = asset } label: { TripPhotoTile(asset: asset) }
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
        .navigationTitle(current.displayTitle)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar(.visible, for: .navigationBar)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Menu {
                    Button { newTitle = current.titleAuto ? current.displayTitle : current.title; renaming = true } label: {
                        Label("Yeniden adlandır", systemImage: "pencil")
                    }
                    Button(role: .destructive) { confirmHide = true } label: {
                        Label("Gizle", systemImage: "eye.slash")
                    }
                } label: {
                    Image(systemName: "ellipsis.circle")
                }
                .accessibilityLabel("Gezi seçenekleri")
            }
        }
        .alert("Yeniden adlandır", isPresented: $renaming) {
            TextField("Ad", text: $newTitle)
            Button("Vazgeç", role: .cancel) {}
            Button("Kaydet") { Task { await rename() } }
        } message: {
            Text("Boş bırakırsanız otomatik ada döner.")
        }
        .confirmationDialog("Bu öneri gizlensin mi?", isPresented: $confirmHide, titleVisibility: .visible) {
            Button("Gizle", role: .destructive) { Task { await hide() } }
            Button("Vazgeç", role: .cancel) {}
        } message: {
            Text("Fotoğraflarınız silinmez; yalnızca bu öneri listeden kalkar ve yeniden önerilmez.")
        }
        .overlay(alignment: .bottom) {
            if let notice {
                Text(notice)
                    .font(.footnote.weight(.medium))
                    .padding(.horizontal, 14).padding(.vertical, 10)
                    .background(.ultraThinMaterial, in: Capsule())
                    .padding(.bottom, 20)
                    .transition(.opacity)
            }
        }
        .refreshable { await reloadAll() }
        .task(id: trip.id) {
            if photos.tripId != trip.id { await photos.reload(tripId: trip.id, client: session.client) }
            if detail == nil { await loadDetail() }
        }
        .fullScreenCover(item: $viewing) { asset in
            AssetViewer(assets: photos.assets, startId: asset.id, onChange: { a in photos.update(a) })
                .environmentObject(session)
                .environment(\.theme, theme)
        }
    }

    private var header: some View {
        let t = current
        return VStack(alignment: .leading, spacing: 8) {
            Text(theme.name == .yerel ? t.kindText : t.kindText.uppercased(with: Locale(identifier: "tr_TR")))
                .font(.system(size: 12, weight: .semibold))
                .tracking(theme.name == .yerel ? 0 : 1.2)
                .foregroundStyle(theme.name == .sinema ? theme.success : theme.accent)
            Text(t.displayTitle)
                .font(theme.title(32))
                .foregroundStyle(theme.ink)
                .lineLimit(3)
            HStack(spacing: 6) {
                Label(t.rangeText, systemImage: "calendar").labelStyle(.titleAndIcon)
                Text("·")
                Text(t.daysText)
                Text("·")
                Text("\(t.count) öğe").monospacedDigit()
            }
            .font(.system(size: 13))
            .foregroundStyle(theme.muted)
            if !t.places.isEmpty {
                ScrollView(.horizontal) {
                    HStack(spacing: 6) {
                        ForEach(t.places, id: \.self) { p in
                            Text(p.name)
                                .font(.system(size: 12.5, weight: .medium))
                                .padding(.horizontal, 10).padding(.vertical, 5)
                                .background(theme.surface, in: RoundedRectangle(cornerRadius: theme.name == .galeri ? 2 : 8, style: .continuous))
                                .overlay(RoundedRectangle(cornerRadius: theme.name == .galeri ? 2 : 8, style: .continuous).stroke(theme.hairline, lineWidth: 1))
                        }
                    }
                }
                .scrollIndicators(.hidden)
            }
            if let route = t.route, !route.isEmpty {
                TripRouteMap(route: route, places: t.places, tint: theme.name == .sinema ? .white : theme.accent)
                    .frame(height: 170)
                    .clipShape(RoundedRectangle(cornerRadius: theme.name == .galeri ? 2 : 14, style: .continuous))
                    .padding(.top, 6)
                    .accessibilityLabel(Text("Rota haritası: \(t.places.map(\.name).joined(separator: ", "))"))
            }
            albumButton(t).padding(.top, 6)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    @ViewBuilder
    private func albumButton(_ t: TripSummary) -> some View {
        if t.albumId != nil {
            Label("Albüm oluşturuldu", systemImage: "checkmark.circle")
                .font(.system(size: 14, weight: .semibold))
                .foregroundStyle(theme.muted)
        } else {
            Button { Task { await convert() } } label: {
                Label("Albüme dönüştür", systemImage: "rectangle.stack.badge.plus")
                    .font(.system(size: 15, weight: .semibold))
                    .padding(.horizontal, 16).padding(.vertical, 10)
                    .foregroundStyle(theme.name == .sinema ? Color.black : .white)
                    .background(theme.name == .sinema ? theme.ink : theme.accent, in: Capsule())
            }
            .disabled(busy)
        }
    }

    private func loadDetail() async {
        guard let client = session.client else { return }
        detail = (try? await client.send("GET", "trips/\(trip.id)", as: TripEnvelope.self))?.trip
    }

    private func reloadAll() async {
        await photos.reload(tripId: trip.id, client: session.client)
        await loadDetail()
    }

    private func flash(_ s: String) {
        withAnimation { notice = s }
        Task {
            try? await Task.sleep(nanoseconds: 2_600_000_000)
            withAnimation { notice = nil }
        }
    }

    private func convert() async {
        guard let client = session.client else { return }
        busy = true
        defer { busy = false }
        do {
            let r: TripAlbumResponse = try await client.send("POST", "trips/\(trip.id)/album", body: EmptyBody())
            await loadDetail()
            onChanged()
            flash("\"\(r.album.title)\" albümü oluşturuldu")
        } catch {
            flash("Albüm oluşturulamadı")
        }
    }

    private func rename() async {
        guard let client = session.client else { return }
        do {
            let r: TripEnvelope = try await client.send("PATCH", "trips/\(trip.id)", body: TripPatch(title: newTitle.trimmingCharacters(in: .whitespacesAndNewlines)))
            detail = r.trip
            onChanged()
            flash("Kaydedildi")
        } catch {
            flash("Kaydedilemedi")
        }
    }

    private func hide() async {
        guard let client = session.client else { return }
        do {
            let _: OkResponse = try await client.send("DELETE", "trips/\(trip.id)")
            onChanged()
            dismiss()
        } catch {
            flash("Gizlenemedi")
        }
    }
}

/// Rota: Apple Haritalar üzerinde çizgi + yer noktaları (etkileşimsiz).
struct TripRouteMap: View {
    let route: [TripPoint]
    let places: [TripPlace]
    let tint: Color

    var body: some View {
        // MapContentBuilder'da koşul kullanılmaz (iOS 17 SDK'larında buildIf desteği belirsiz): noktalar önceden süzülür;
        // tek noktalı çizgi hiçbir şey çizmez.
        Map(initialPosition: .rect(rect), interactionModes: []) {
            MapPolyline(coordinates: route.map { CLLocationCoordinate2D(latitude: $0.lat, longitude: $0.lng) })
                .stroke(tint, style: StrokeStyle(lineWidth: 3, lineCap: .round, lineJoin: .round, dash: [6, 5]))
            ForEach(pins) { pin in
                Annotation(pin.name, coordinate: pin.coordinate) {
                    Circle().fill(tint).frame(width: 11, height: 11).overlay(Circle().stroke(.white, lineWidth: 2))
                }
            }
        }
        .mapStyle(.standard(elevation: .flat, pointsOfInterest: .excludingAll))
        .allowsHitTesting(false)
    }

    private struct Pin: Identifiable {
        let id: Int
        let name: String
        let coordinate: CLLocationCoordinate2D
    }

    private var pins: [Pin] {
        places.enumerated().compactMap { (i, p) -> Pin? in
            guard let lat = p.lat, let lng = p.lng else { return nil }
            return Pin(id: i, name: p.name, coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lng))
        }
    }

    /// Rotanın sınır kutusu (biraz pay ile; tek noktada ~20 km).
    private var rect: MKMapRect {
        var r = MKMapRect.null
        for p in route {
            let pt = MKMapPoint(CLLocationCoordinate2D(latitude: p.lat, longitude: p.lng))
            r = r.union(MKMapRect(x: pt.x, y: pt.y, width: 0, height: 0))
        }
        let minSide = MKMapPointsPerMeterAtLatitude(route.first?.lat ?? 0) * 20_000
        let w = max(r.size.width, minSide), h = max(r.size.height, minSide)
        return MKMapRect(x: r.midX - w * 0.65, y: r.midY - h * 0.65, width: w * 1.3, height: h * 1.3)
    }
}

/// Gezi sayfası ızgara hücresi (kitaplık ızgarasıyla aynı görünüm).
private struct TripPhotoTile: View {
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
