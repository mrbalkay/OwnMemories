import AVKit
import SwiftUI

struct AssetViewer: View {
    let assets: [Asset]
    let startId: String
    var onChange: (Asset) -> Void
    @EnvironmentObject private var session: Session
    @Environment(\.dismiss) private var dismiss
    @Environment(\.theme) private var theme
    @State private var index: Int = 0
    @State private var current: [String: Asset] = [:]
    @State private var showInfo = false
    @State private var chrome = true
    /// Tek bir sürükleme hem sayfa değiştirir hem kapatır (iOS Fotoğraflar gibi): yön ilk harekette
    /// belirlenir. Yatay: önceki/sonraki öğe; dikey: içerik parmağı izler, arka plan solar, eşikte kapanır.
    /// (Sayfa görünümü + içerideki ayrı bir sürükleme hareketi iOS 18'de yatay kaydırmayı engelliyordu.)
    @State private var dragX: CGFloat = 0
    @State private var dragY: CGFloat = 0
    @State private var dragAxis: Axis?
    /// İki parmakla yakınlaştırma sürerken sürükleme sayfayı oynatmaz.
    @State private var zooming = false

    init(assets: [Asset], startId: String, onChange: @escaping (Asset) -> Void) {
        self.assets = assets
        self.startId = startId
        self.onChange = onChange
        _index = State(initialValue: assets.firstIndex { $0.id == startId } ?? 0)
    }

    private var dragProgress: CGFloat { min(abs(dragY) / 320, 1) }
    private var selectedId: String? { assets.indices.contains(index) ? assets[index].id : nil }
    private func asset(_ id: String) -> Asset? { current[id] ?? assets.first { $0.id == id } }

    var body: some View {
        GeometryReader { geo in
            let width = max(geo.size.width, 1)
            ZStack(alignment: .top) {
                Color.black.opacity(Double(1 - dragProgress * 0.85)).ignoresSafeArea()
                ZStack {
                    // Yalnızca görünen sayfa ve iki komşusu çizilir (bellek); kaydırırken komşu kenardan girer.
                    ForEach(visibleRange, id: \.self) { i in
                        AssetPageView(asset: assets[i], isCurrent: i == index, zooming: $zooming)
                            .frame(width: width, height: geo.size.height)
                            .offset(x: CGFloat(i - index) * (width + 12) + dragX)
                    }
                }
                .frame(width: width, height: geo.size.height)
                .clipped()
                .offset(y: dragY)
                .scaleEffect(1 - dragProgress * 0.25)
                .contentShape(Rectangle())
                .simultaneousGesture(pagerGesture(width: width))
                .onTapGesture { withAnimation(.easeInOut(duration: 0.2)) { chrome.toggle() } }
                .ignoresSafeArea()

                topBar
            }
        }
        .ignoresSafeArea(edges: .bottom)
        .presentationBackground(.clear)
        .statusBarHidden(!chrome)
        .sheet(isPresented: $showInfo) {
            if let id = selectedId, let a = asset(id) {
                AssetInfoView(asset: a)
                    .environment(\.theme, theme)
                    .preferredColorScheme(theme.colorScheme)
                    .presentationDetents([.medium, .large])
            }
        }
    }

    private var visibleRange: [Int] {
        guard !assets.isEmpty else { return [] }
        let i = min(max(index, 0), assets.count - 1)
        return Array(max(0, i - 1)...min(assets.count - 1, i + 1))
    }

    private var topBar: some View {
        HStack(spacing: theme.usesGlass ? 8 : 0) {
            Button { dismiss() } label: { chromeIcon("xmark") }
                .accessibilityLabel("Kapat")
            Spacer()
            if let id = selectedId, let a = asset(id), a.access != "view" {
                Button { Task { await toggleFavorite(a) } } label: {
                    chromeIcon(a.isFavorite ? "heart.fill" : "heart")
                }
                .accessibilityLabel(a.isFavorite ? "Favorilerden çıkar" : "Favorilere ekle")
            }
            Button { showInfo = true } label: { chromeIcon(theme.usesGlass ? "info" : "info.circle") }
                .accessibilityLabel("Ayrıntılar")
        }
        .foregroundStyle(.white)
        .padding(.horizontal, theme.usesGlass ? 14 : 8)
        .padding(.vertical, theme.usesGlass ? 4 : 0)
        .background(LinearGradient(colors: [.black.opacity(theme.name == .galeri ? 0.45 : 0.6), .clear], startPoint: .top, endPoint: .bottom).ignoresSafeArea())
        .opacity(chrome && dragY == 0 ? 1 : 0)
        .accessibilityElement(children: .contain)
        .accessibilityAction(named: "Sonraki") { go(1) }
        .accessibilityAction(named: "Önceki") { go(-1) }
    }

    private func pagerGesture(width: CGFloat) -> some Gesture {
        DragGesture(minimumDistance: 10)
            .onChanged { v in
                guard !zooming else { return }
                if dragAxis == nil {
                    dragAxis = abs(v.translation.width) > abs(v.translation.height) ? .horizontal : .vertical
                }
                switch dragAxis {
                case .horizontal:
                    let atStart = index == 0 && v.translation.width > 0
                    let atEnd = index == assets.count - 1 && v.translation.width < 0
                    // Uçlarda lastik etkisi.
                    dragX = (atStart || atEnd) ? v.translation.width / 3 : v.translation.width
                case .vertical:
                    dragY = v.translation.height
                case .none:
                    break
                }
            }
            .onEnded { v in
                let axis = dragAxis
                dragAxis = nil
                guard !zooming else {
                    withAnimation(.spring(response: 0.3, dampingFraction: 0.9)) { dragX = 0; dragY = 0 }
                    return
                }
                if axis == .horizontal {
                    let dx = v.translation.width
                    let predicted = v.predictedEndTranslation.width
                    var step = 0
                    if (dx < -width * 0.22 || predicted < -width * 0.5), index < assets.count - 1 { step = 1 }
                    if (dx > width * 0.22 || predicted > width * 0.5), index > 0 { step = -1 }
                    withAnimation(.spring(response: 0.34, dampingFraction: 0.9)) {
                        index += step
                        dragX = 0
                    }
                } else if axis == .vertical {
                    if abs(v.translation.height) > 110 || abs(v.predictedEndTranslation.height) > 320 {
                        dismissBySwipe()
                    } else {
                        withAnimation(.spring(response: 0.32, dampingFraction: 0.86)) { dragY = 0 }
                    }
                }
            }
    }

    private func go(_ step: Int) {
        let target = index + step
        guard assets.indices.contains(target) else { return }
        withAnimation(.spring(response: 0.34, dampingFraction: 0.9)) { index = target }
    }

    /// Üst çubuk düğmesi: Sinema/Yerel'de koyu cam daire, Galeri'de çerçevesiz ince simge.
    @ViewBuilder
    private func chromeIcon(_ name: String) -> some View {
        if theme.usesGlass {
            Image(systemName: name)
                .font(.system(size: 15, weight: .semibold))
                .frame(width: 36, height: 36)
                .background(.ultraThinMaterial, in: Circle())
                .overlay(Circle().stroke(Color.white.opacity(0.14), lineWidth: 1))
                .environment(\.colorScheme, .dark)
        } else {
            Image(systemName: name)
                .font(.system(size: 19, weight: .light))
                .padding(10)
        }
    }

    /// Çekme eşiği aşıldı: fotoğraf çekildiği yönde ekrandan çıkar, sonra görünüm animasyonsuz kapanır.
    private func dismissBySwipe() {
        let target: CGFloat = (dragY >= 0 ? 1 : -1) * 1200
        withAnimation(.easeOut(duration: 0.2)) { dragY = target }
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.2) {
            var t = Transaction()
            t.disablesAnimations = true
            withTransaction(t) { dismiss() }
        }
    }

    private func toggleFavorite(_ a: Asset) async {
        guard let client = session.client else { return }
        struct Body: Encodable { let is_favorite: Bool }
        if let r: AssetEnvelope = try? await client.send("PATCH", "assets/\(a.id)", body: Body(is_favorite: !a.isFavorite)) {
            current[a.id] = r.asset
            onChange(r.asset)
        }
    }
}

private struct AssetPageView: View {
    let asset: Asset
    let isCurrent: Bool
    @Binding var zooming: Bool
    @EnvironmentObject private var session: Session
    @State private var player: AVPlayer?
    @State private var scale: CGFloat = 1

    var body: some View {
        Group {
            if asset.isVideo {
                if let player {
                    VideoPlayer(player: player)
                } else {
                    ProgressView().tint(.white)
                }
            } else {
                ZStack {
                    RemoteImage(assetId: asset.id, variant: "thumb", contentMode: .fit)
                    RemoteImage(assetId: asset.id, variant: "preview", contentMode: .fit, showsPlaceholder: false)
                }
                .scaleEffect(scale)
                .gesture(
                    MagnificationGesture()
                        .onChanged { scale = max(1, $0); zooming = true }
                        .onEnded { _ in
                            withAnimation { scale = 1 }
                            zooming = false
                        }
                )
            }
        }
        .onAppear { if isCurrent { preparePlayer() } }
        .onChange(of: isCurrent) { _, now in
            if now {
                preparePlayer()
            } else {
                // Görünmeyen sayfanın videosu durur.
                player?.pause()
            }
        }
        .onDisappear {
            // Çizimden çıkan sayfanın oynatıcısı bırakılır (bellek); sayfaya dönülünce yeniden oluşturulur.
            player?.pause()
            player = nil
        }
    }

    private func preparePlayer() {
        guard asset.isVideo, player == nil, let client = session.client else { return }
        let url = client.url("assets/\(asset.id)/video")
        var headers: [String: String] = [:]
        if let token = client.token { headers["Authorization"] = "Bearer " + token }
        let item = AVPlayerItem(asset: AVURLAsset(url: url, options: ["AVURLAssetHTTPHeaderFieldsKey": headers]))
        player = AVPlayer(playerItem: item)
    }
}

private struct AssetInfoView: View {
    let asset: Asset
    @Environment(\.theme) private var theme

    var body: some View {
        NavigationStack {
            List {
                if let c = asset.caption, !c.isEmpty { Section { Text(c) } }
                Section("Çekim") {
                    Text(dateText)
                    if let src = asset.dateSource { Text(sourceText(src)).font(.footnote).foregroundStyle(.secondary) }
                }
                Section("Dosya") {
                    Text(asset.filename)
                    Text([asset.width.flatMap { w in asset.height.map { "\(w) × \($0)" } }, ByteCountFormatter.string(fromByteCount: asset.size, countStyle: .file)]
                        .compactMap { $0 }.joined(separator: "  ·  ")).foregroundStyle(.secondary)
                }
                if asset.cameraMake != nil || asset.cameraModel != nil {
                    Section("Kamera") { Text([asset.cameraMake, asset.cameraModel].compactMap { $0 }.joined(separator: " ")) }
                }
                if let lat = asset.latitude, let lng = asset.longitude {
                    Section("Konum") {
                        Text(String(format: "%.5f, %.5f", lat, lng))
                        if let url = URL(string: "https://maps.apple.com/?ll=\(lat),\(lng)&q=Foto%C4%9Fraf") {
                            Link("Haritada aç", destination: url)
                        }
                    }
                }
            }
            .themedList(theme)
            .navigationTitle("Ayrıntılar")
            .navigationBarTitleDisplayMode(.inline)
        }
    }

    private var dateText: String {
        guard let local = asset.takenLocal, let comps = ServerDate.localComponents(local) else { return asset.takenAt }
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = TimeZone(identifier: "UTC")!
        guard let d = cal.date(from: comps) else { return local }
        let f = DateFormatter()
        f.locale = Locale(identifier: "tr_TR")
        f.timeZone = cal.timeZone
        f.dateStyle = .full
        f.timeStyle = .short
        return f.string(from: d)
    }

    private func sourceText(_ s: String) -> String {
        switch s {
        case "takeout": return "Google Fotoğraflar bilgisi"
        case "exif": return "Fotoğraf bilgisi"
        case "filename": return "Dosya adından"
        case "user": return "Elle düzeltildi"
        default: return "Dosya tarihinden (kesin değil)"
        }
    }
}
