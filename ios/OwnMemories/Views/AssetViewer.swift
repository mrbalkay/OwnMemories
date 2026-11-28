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
    /// Gösterilen öğenin kimliği: liste arkada yenilenip sıra kayarsa (yeni yüklemeler başa eklenir) aynı öğede kalınır.
    @State private var currentId: String
    @State private var current: [String: Asset] = [:]
    @State private var showInfo = false
    /// Bağlantıyla paylaşım (herkese açık bağlantı + iOS paylaşım sayfası): gösterilen öğe için.
    @State private var shareLinkIds: [String]?
    @State private var chrome = true
    /// Tek bir sürükleme hem sayfa değiştirir hem kapatır (iOS Fotoğraflar gibi): yön ilk harekette
    /// belirlenir. Yatay: önceki/sonraki öğe; dikey: içerik parmağı izler, arka plan solar, eşikte kapanır.
    /// (Sayfa görünümü + içerideki ayrı bir sürükleme hareketi iOS 18'de yatay kaydırmayı engelliyordu.)
    @State private var dragX: CGFloat = 0
    @State private var dragY: CGFloat = 0
    @State private var dragAxis: Axis?
    /// Görünen sayfa yakınlaştırılmış (ya da sıkıştırma sürüyor): sürükleme sayfa yerine fotoğrafı gezdirir.
    @State private var zooming = false
    /// Görünen video öğesinin oynatıcısı (görüntüleyiciye ait; denetimler sayfa hareketlerinin dışında durur).
    @State private var player: AVPlayer?
    @State private var playerAssetId: String?
    /// Sürükleme sırasında yakınlaştırma başladıysa bu sürükleme parmak kalkana dek yok sayılır.
    @State private var dragCancelled = false

    init(assets: [Asset], startId: String, onChange: @escaping (Asset) -> Void) {
        self.assets = assets
        self.startId = startId
        self.onChange = onChange
        let start = assets.firstIndex { $0.id == startId } ?? 0
        _index = State(initialValue: start)
        _currentId = State(initialValue: assets.indices.contains(start) ? assets[start].id : startId)
    }

    private var dragProgress: CGFloat { min(abs(dragY) / 320, 1) }
    private var selectedId: String? { assets.indices.contains(index) ? assets[index].id : nil }
    private func asset(_ id: String) -> Asset? { current[id] ?? assets.first { $0.id == id } }

    var body: some View {
        ZStack(alignment: .top) {
            Color.black.opacity(Double(1 - dragProgress * 0.85)).ignoresSafeArea()
            // Sayfalar tüm ekranı kaplar (kenardan kenara); üst çubuk güvenli alanın içinde kalır.
            GeometryReader { geo in
                pager(size: geo.size)
            }
            .ignoresSafeArea()
            topBar
            if let player, let pid = playerAssetId, pid == selectedId, chrome, dragY == 0 {
                VStack {
                    Spacer()
                    // Her oynatıcı için yeni denetim görünümü: gözlemci daima kendi oynatıcısından kaldırılır.
                    VideoControls(player: player)
                        .id(pid)
                        .padding(.horizontal, 16)
                        .padding(.bottom, 8)
                }
                .transition(.opacity)
            }
        }
        .presentationBackground(.clear)
        .statusBarHidden(!chrome)
        .onChange(of: assets.map(\.id)) { _, ids in
            // Liste değişti: aynı öğeyi bul; yoksa en yakın geçerli konuma sabitle.
            if let i = ids.firstIndex(of: currentId) {
                index = i
            } else if !ids.isEmpty {
                index = min(max(index, 0), ids.count - 1)
                currentId = ids[index]
            }
            syncPlayer()
        }
        .onChange(of: index) { _, i in
            if assets.indices.contains(i) { currentId = assets[i].id }
            // Yeni sayfa yakınlaştırılmamış başlar (erişilebilirlik ile sayfa değişse bile sürükleme kilitli kalmaz).
            zooming = false
            syncPlayer()
        }
        .onAppear { syncPlayer() }
        .onDisappear {
            player?.pause()
            player = nil
            playerAssetId = nil
        }
        .sheet(isPresented: Binding(get: { shareLinkIds != nil }, set: { if !$0 { shareLinkIds = nil } })) {
            if let ids = shareLinkIds {
                ShareLinkSheet(target: .selection(ids: ids))
                    .environmentObject(session)
                    .environment(\.theme, theme)
                    .preferredColorScheme(theme.colorScheme)
            }
        }
        .sheet(isPresented: $showInfo) {
            if let id = selectedId, let a = asset(id) {
                AssetInfoView(asset: a)
                    .environmentObject(session)
                    .environment(\.theme, theme)
                    .preferredColorScheme(theme.colorScheme)
                    .presentationDetents([.medium, .large])
            }
        }
    }

    private struct Slot: Identifiable {
        let index: Int
        let asset: Asset
        var id: String { asset.id }
    }

    private func pager(size: CGSize) -> some View {
        let width = max(size.width, 1)
        // Yalnızca görünen sayfa ve iki komşusu çizilir (bellek); kaydırırken komşu kenardan girer.
        // Kimlik öğe kimliğidir (konum değil): liste kayarsa bir sayfanın durumu (oynatıcı) başka öğeye geçmez.
        let slots = visibleRange.map { Slot(index: $0, asset: assets[$0]) }
        return ZStack {
            ForEach(slots) { slot in
                AssetPageView(asset: slot.asset, isCurrent: slot.index == index, zooming: $zooming,
                              player: slot.index == index && playerAssetId == slot.asset.id ? player : nil) {
                    withAnimation(.easeInOut(duration: 0.2)) { chrome.toggle() }
                }
                    .frame(width: width, height: size.height)
                    .offset(x: CGFloat(slot.index - index) * (width + 12) + dragX)
            }
        }
        .frame(width: width, height: size.height)
        .clipped()
        .offset(y: dragY)
        .scaleEffect(1 - dragProgress * 0.25)
        .contentShape(Rectangle())
        .simultaneousGesture(pagerGesture(width: width))
    }

    /// Görünen öğe videoysa oynatıcıyı hazırlar ve oynatır; değilse eskisini bırakır.
    private func syncPlayer() {
        guard let id = selectedId, let a = asset(id), a.isVideo, let client = session.client else {
            player?.pause()
            player = nil
            playerAssetId = nil
            return
        }
        if playerAssetId == a.id { return }
        player?.pause()
        let url = client.url("assets/\(a.id)/video")
        var headers: [String: String] = [:]
        if let token = client.token { headers["Authorization"] = "Bearer " + token }
        let item = AVPlayerItem(asset: AVURLAsset(url: url, options: ["AVURLAssetHTTPHeaderFieldsKey": headers]))
        let p = AVPlayer(playerItem: item)
        player = p
        playerAssetId = a.id
        p.play()
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
            if let id = selectedId, let a = asset(id), a.access != "view" {
                Button { shareLinkIds = [id] } label: { chromeIcon("link") }
                    .accessibilityLabel("Bağlantıyla paylaş")
            }
            Button { showInfo = true } label: { chromeIcon(theme.usesGlass ? "info" : "info.circle") }
                .accessibilityLabel("Ayrıntılar")
        }
        .foregroundStyle(.white)
        .padding(.horizontal, theme.usesGlass ? 14 : 8)
        .padding(.vertical, theme.usesGlass ? 4 : 0)
        .background(
            // Geçiş gölgesi dokunuşu yutmaz: düğmeler arasındaki boşlukta da kaydırma/dokunma sayfaya gider.
            LinearGradient(colors: [.black.opacity(theme.name == .galeri ? 0.45 : 0.6), .clear], startPoint: .top, endPoint: .bottom)
                .ignoresSafeArea()
                .allowsHitTesting(false)
        )
        .opacity(chrome && dragY == 0 ? 1 : 0)
        .allowsHitTesting(chrome && dragY == 0)
        .accessibilityElement(children: .contain)
        .accessibilityAction(named: "Sonraki") { go(1) }
        .accessibilityAction(named: "Önceki") { go(-1) }
    }

    private func pagerGesture(width: CGFloat) -> some Gesture {
        DragGesture(minimumDistance: 10)
            .onChanged { v in
                if zooming || dragCancelled {
                    // Yakınlaştırma araya girdi: sayfa yerine döner, bu sürükleme bitene dek etkisiz.
                    dragCancelled = true
                    if dragX != 0 || dragY != 0 {
                        withAnimation(.spring(response: 0.3, dampingFraction: 0.9)) { dragX = 0; dragY = 0 }
                    }
                    return
                }
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
                let cancelled = dragCancelled
                dragCancelled = false
                guard !zooming, !cancelled else {
                    withAnimation(.spring(response: 0.3, dampingFraction: 0.9)) { dragX = 0; dragY = 0 }
                    return
                }
                if axis == .horizontal {
                    let dx = v.translation.width
                    let predicted = v.predictedEndTranslation.width
                    var step = 0
                    // Yeterince çekildi ve geri savrulmadı, ya da hızlı savruldu. (İkisi birbirini dışlar.)
                    if (dx < -width * 0.22 && predicted < 0) || predicted < -width * 0.5, index < assets.count - 1 { step = 1 }
                    if (dx > width * 0.22 && predicted > 0) || predicted > width * 0.5, index > 0 { step = -1 }
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

/// Tek sayfa: fotoğraf ya da video yüzeyi; iOS Fotoğraflar gibi yakınlaştırma.
/// - İki parmakla: parmakların arasındaki nokta büyür; bırakınca büyük kalır.
/// - Büyükken tek parmakla gezdirilir (kenarlarda sınırlanır, hafif kayma ile).
/// - Çift dokunma: dokunulan noktaya 2,5× yakınlaşır; büyükken eski boyuta döner.
/// - Tek dokunma: üst çubuğu/denetimleri gizler-gösterir.
private struct AssetPageView: View {
    let asset: Asset
    let isCurrent: Bool
    @Binding var zooming: Bool
    var player: AVPlayer?
    var onSingleTap: () -> Void

    @State private var scale: CGFloat = 1
    @State private var offset: CGSize = .zero
    @State private var pinchStart: (scale: CGFloat, offset: CGSize)?
    @State private var panStart: CGSize?
    /// Hareket iptal edilse de (onEnded gelmeden) sıfırlanır; böylece yarım kalan sıkıştırma/gezdirme toparlanır.
    @GestureState private var pinchActive = false
    @GestureState private var panActive = false

    private let maxScale: CGFloat = 6

    var body: some View {
        GeometryReader { geo in
            let size = geo.size
            let fit = fittedSize(in: size)
            content
                .frame(width: fit.width, height: fit.height)
                .scaleEffect(scale)
                .offset(offset)
                .frame(width: size.width, height: size.height)
                .contentShape(Rectangle())
                .gesture(doubleTap(size: size, fit: fit).exclusively(before: singleTap))
                .simultaneousGesture(magnify(size: size, fit: fit))
                .simultaneousGesture(pan(size: size, fit: fit))
                .onChange(of: pinchActive) { _, active in if !active { finishPinch(size: size, fit: fit) } }
                .onChange(of: panActive) { _, active in if !active { finishPan(size: size, fit: fit, velocity: .zero) } }
        }
        .onChange(of: isCurrent) { _, now in
            // Sayfadan çıkınca yakınlaştırma sıfırlanır.
            if !now { reset(animated: false) }
        }
        .onDisappear { if isCurrent { zooming = false } }
    }

    @ViewBuilder
    private var content: some View {
        if asset.isVideo {
            ZStack {
                // Önizleme altta kalır: ilk kare gelene dek siyah ekran yerine görünür.
                RemoteImage(assetId: asset.id, variant: "preview", contentMode: .fit, showsPlaceholder: false)
                if let player { PlayerSurface(player: player) }
            }
        } else {
            ZStack {
                RemoteImage(assetId: asset.id, variant: "thumb", contentMode: .fit)
                RemoteImage(assetId: asset.id, variant: "preview", contentMode: .fit, showsPlaceholder: false)
            }
        }
    }

    /// İçeriğin ekrana sığdırılmış boyutu (en-boy oranı biliniyorsa); bilinmiyorsa tüm alan.
    private func fittedSize(in size: CGSize) -> CGSize {
        guard let w = asset.width, let h = asset.height, w > 0, h > 0, size.width > 0, size.height > 0 else { return size }
        let aspect = CGFloat(w) / CGFloat(h)
        if aspect > size.width / size.height {
            return CGSize(width: size.width, height: size.width / aspect)
        }
        return CGSize(width: size.height * aspect, height: size.height)
    }

    // MARK: - Hareketler

    private func magnify(size: CGSize, fit: CGSize) -> some Gesture {
        MagnifyGesture()
            .updating($pinchActive) { _, state, _ in state = true }
            .onChanged { v in
                if pinchStart == nil { pinchStart = (scale, offset) }
                guard let start = pinchStart else { return }
                if isCurrent { zooming = true }
                // Biraz altına inmeye izin ver (lastik), bırakınca 1'e döner.
                let raw = start.scale * v.magnification
                let s = raw < 1 ? 1 - (1 - raw) * 0.4 : min(raw, maxScale * 1.15)
                // Parmakların başladığı nokta yerinde kalsın: o noktanın içerikteki karşılığı sabit tutulur.
                let c = CGPoint(x: size.width / 2, y: size.height / 2)
                let p = v.startLocation
                let k = s / start.scale
                offset = CGSize(width: (p.x - c.x) - (p.x - c.x - start.offset.width) * k,
                                height: (p.y - c.y) - (p.y - c.y - start.offset.height) * k)
                scale = s
            }
            .onEnded { _ in finishPinch(size: size, fit: fit) }
    }

    private func finishPinch(size: CGSize, fit: CGSize) {
        guard pinchStart != nil else { return }
        pinchStart = nil
        withAnimation(.spring(response: 0.32, dampingFraction: 0.86)) {
            if scale <= 1.02 {
                scale = 1
                offset = .zero
            } else {
                let s = min(scale, maxScale)
                // Üst sınıra geri dönerken ekran ortası etrafında küçült, sonra kenarlara sınırla.
                let k = s / scale
                scale = s
                offset = clamped(CGSize(width: offset.width * k, height: offset.height * k), scale: s, size: size, fit: fit)
            }
        }
        if isCurrent { zooming = scale > 1.02 }
    }

    private func pan(size: CGSize, fit: CGSize) -> some Gesture {
        DragGesture(minimumDistance: 4)
            .updating($panActive) { _, state, _ in if scale > 1.02 { state = true } }
            .onChanged { v in
                guard scale > 1.02 else { return }
                // Sıkıştırma sürerken gezdirme durur; sıkıştırma bitince o anki konumdan sıçramadan sürer.
                guard pinchStart == nil else { panStart = nil; return }
                if panStart == nil {
                    panStart = CGSize(width: offset.width - v.translation.width, height: offset.height - v.translation.height)
                }
                guard let start = panStart else { return }
                let proposed = CGSize(width: start.width + v.translation.width, height: start.height + v.translation.height)
                // Sınır dışına taşarken direnç.
                let limit = clamped(proposed, scale: scale, size: size, fit: fit)
                offset = CGSize(width: limit.width + (proposed.width - limit.width) * 0.3,
                                height: limit.height + (proposed.height - limit.height) * 0.3)
            }
            .onEnded { v in
                let fling = CGSize(width: (v.predictedEndTranslation.width - v.translation.width) * 0.35,
                                   height: (v.predictedEndTranslation.height - v.translation.height) * 0.35)
                finishPan(size: size, fit: fit, velocity: fling)
            }
    }

    private func finishPan(size: CGSize, fit: CGSize, velocity: CGSize) {
        guard panStart != nil else { return }
        panStart = nil
        let target = CGSize(width: offset.width + velocity.width, height: offset.height + velocity.height)
        withAnimation(.spring(response: 0.38, dampingFraction: 0.9)) {
            offset = clamped(target, scale: scale, size: size, fit: fit)
        }
    }

    private func doubleTap(size: CGSize, fit: CGSize) -> some Gesture {
        SpatialTapGesture(count: 2).onEnded { v in
            withAnimation(.spring(response: 0.34, dampingFraction: 0.86)) {
                if scale > 1.02 {
                    scale = 1
                    offset = .zero
                } else {
                    let s: CGFloat = 2.5
                    let c = CGPoint(x: size.width / 2, y: size.height / 2)
                    let p = v.location
                    scale = s
                    offset = clamped(CGSize(width: (p.x - c.x) * (1 - s), height: (p.y - c.y) * (1 - s)), scale: s, size: size, fit: fit)
                }
            }
            if isCurrent { zooming = scale > 1.02 }
        }
    }

    private var singleTap: some Gesture {
        TapGesture(count: 1).onEnded { onSingleTap() }
    }

    /// Büyütülmüş içerik ekranın kenarından içeri girmesin.
    private func clamped(_ o: CGSize, scale s: CGFloat, size: CGSize, fit: CGSize) -> CGSize {
        let maxX = max(0, (fit.width * s - size.width) / 2)
        let maxY = max(0, (fit.height * s - size.height) / 2)
        return CGSize(width: min(max(o.width, -maxX), maxX), height: min(max(o.height, -maxY), maxY))
    }

    private func reset(animated: Bool) {
        pinchStart = nil
        panStart = nil
        if animated {
            withAnimation(.spring(response: 0.3, dampingFraction: 0.9)) { scale = 1; offset = .zero }
        } else {
            scale = 1
            offset = .zero
        }
    }
}

/// Video yüzeyi (AVPlayerLayer): dokunuşları almaz; böylece yakınlaştırma ve sayfa hareketleri çalışır.
private final class PlayerLayerView: UIView {
    override class var layerClass: AnyClass { AVPlayerLayer.self }
    var playerLayer: AVPlayerLayer { layer as! AVPlayerLayer }
}

private struct PlayerSurface: UIViewRepresentable {
    let player: AVPlayer

    func makeUIView(context: Context) -> PlayerLayerView {
        let v = PlayerLayerView()
        v.backgroundColor = .clear
        v.isUserInteractionEnabled = false
        v.playerLayer.videoGravity = .resizeAspect
        v.playerLayer.player = player
        return v
    }

    func updateUIView(_ v: PlayerLayerView, context: Context) {
        if v.playerLayer.player !== player { v.playerLayer.player = player }
    }
}

/// Video denetimleri: oynat/duraklat, ilerleme çubuğu, süre, ses. Sayfa hareketlerinin dışında durur.
private struct VideoControls: View {
    let player: AVPlayer
    @State private var playing = false
    @State private var progress: Double = 0
    @State private var duration: Double = 0
    @State private var scrubbing = false
    @State private var muted = false
    @State private var ended = false
    /// Gözlemci, eklendiği oynatıcıyla birlikte saklanır: başka bir oynatıcıdan kaldırmak AVPlayer'ı çökertir.
    @State private var observer: (player: AVPlayer, token: Any)?

    var body: some View {
        HStack(spacing: 12) {
            Button { toggle() } label: {
                Image(systemName: playing ? "pause.fill" : "play.fill")
                    .font(.system(size: 17, weight: .semibold))
                    .frame(width: 32, height: 32)
            }
            .accessibilityLabel(playing ? "Duraklat" : "Oynat")
            Text(Self.time(progress * duration))
                .font(.system(size: 12).monospacedDigit())
            Slider(value: $progress, in: 0...1, onEditingChanged: { editing in
                scrubbing = editing
                if editing { ended = false } else { seek() }
            })
            .tint(.white)
            .onChange(of: progress) { _, _ in if scrubbing { seek() } }
            .accessibilityLabel("Video konumu")
            Text(Self.time(duration))
                .font(.system(size: 12).monospacedDigit())
                .foregroundStyle(.white.opacity(0.7))
            Button {
                muted.toggle()
                player.isMuted = muted
            } label: {
                Image(systemName: muted ? "speaker.slash.fill" : "speaker.wave.2.fill")
                    .font(.system(size: 14, weight: .semibold))
                    .frame(width: 28, height: 32)
            }
            .accessibilityLabel(muted ? "Sesi aç" : "Sesi kapat")
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 14)
        .padding(.vertical, 8)
        .background(.ultraThinMaterial, in: Capsule())
        .overlay(Capsule().stroke(Color.white.opacity(0.14), lineWidth: 1))
        .environment(\.colorScheme, .dark)
        .onAppear(perform: attach)
        .onDisappear(perform: detach)
        .onChange(of: ObjectIdentifier(player)) { _, _ in detach(); attach() }
        .onReceive(NotificationCenter.default.publisher(for: .AVPlayerItemDidPlayToEndTime)) { note in
            if let item = note.object as? AVPlayerItem, item === player.currentItem {
                playing = false
                ended = true
            }
        }
    }

    private func attach() {
        guard observer == nil else { return }
        muted = player.isMuted
        playing = player.timeControlStatus != .paused
        let p = player
        let token = p.addPeriodicTimeObserver(forInterval: CMTime(seconds: 0.25, preferredTimescale: 600), queue: .main) { t in
            // Ana kuyrukta çağrılır; görünüm durumu ana aktörde güncellenir.
            MainActor.assumeIsolated {
                let d = p.currentItem?.duration.seconds ?? 0
                if d.isFinite, d > 0 { duration = d }
                if !scrubbing, duration > 0 { progress = min(max(t.seconds / duration, 0), 1) }
                playing = p.timeControlStatus != .paused
            }
        }
        observer = (p, token)
    }

    private func detach() {
        if let observer { observer.player.removeTimeObserver(observer.token) }
        observer = nil
    }

    private func toggle() {
        if player.timeControlStatus == .paused {
            if ended || (player.currentItem.map { $0.duration.isNumeric && CMTimeCompare(player.currentTime(), $0.duration) >= 0 } ?? false) {
                player.seek(to: .zero)
                progress = 0
            }
            ended = false
            player.play()
            playing = true
        } else {
            player.pause()
            playing = false
        }
    }

    private func seek() {
        guard duration > 0 else { return }
        player.seek(to: CMTime(seconds: progress * duration, preferredTimescale: 600), toleranceBefore: .zero, toleranceAfter: .zero)
    }

    static func time(_ s: Double) -> String {
        guard s.isFinite, s >= 0 else { return "0:00" }
        let t = Int(s.rounded())
        return String(format: "%d:%02d", t / 60, t % 60)
    }
}

private struct AssetInfoView: View {
    let asset: Asset
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    /// GET /v1/assets/{id}/people: etiketli kişiler ve bulunan yüzler (nil: yükleniyor).
    @State private var people: AssetPeople?
    @State private var peopleLoadFailed = false
    @State private var picker: PeoplePicker?
    @State private var pendingPick: (target: PeoplePicker, choice: PersonPickerSheet.Choice)?
    @State private var mergeOffer: MergeOffer?
    @State private var peopleError: String?
    @State private var peopleBusy = false
    /// Sunucu bu öğenin kişilerini vermiyor (başkasının öğesi: 404): bölüm gizlenir.
    @State private var peopleUnavailable = false
    /// GET /v1/assets/{id}/text: fotoğraftaki yazı (nil: yükleniyor ya da yok).
    @State private var recognized: AssetText?
    @State private var textExpanded = false
    @State private var textCopied = false

    /// Kişi seçicinin amacı: fotoğrafa kişi ekle ya da bir yüzü adlandır.
    private enum PeoplePicker: Identifiable {
        case add
        case face(DetectedFace)

        var id: String {
            switch self {
            case .add: return "add"
            case .face(let f): return "face-\(f.id)"
            }
        }
    }

    /// Kişiler yalnızca kendi fotoğraflarında düzenlenir (paylaşılan albümden görüntülenenlerde değil).
    private var canEditPeople: Bool { asset.access != "view" }

    var body: some View {
        NavigationStack {
            List {
                if let c = asset.caption, !c.isEmpty { Section { Text(c) } }
                if canEditPeople && !peopleUnavailable { peopleSection }
                if let recognized, recognized.hasText { textSection(recognized) }
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
                        // Yer adı (sunucu çevrimdışı bulur): "Moda, Kadıköy, İstanbul, Türkiye"; koordinatlar küçük, ikincil.
                        if let place = asset.place {
                            VStack(alignment: .leading, spacing: 3) {
                                if let region = place.region, PlaceText.fold(region) != PlaceText.fold(place.name) {
                                    Text(region)
                                        .font(.caption.weight(.semibold))
                                        .foregroundStyle(theme.name == .yerel ? theme.accent : theme.muted)
                                }
                                Text(place.label)
                                    .font(.body.weight(.semibold))
                                    .fixedSize(horizontal: false, vertical: true)
                                Text(String(format: "%.5f, %.5f", lat, lng))
                                    .font(.caption)
                                    .monospacedDigit()
                                    .foregroundStyle(.secondary)
                                    .accessibilityLabel(Text(verbatim: "Koordinatlar \(String(format: "%.5f, %.5f", lat, lng))"))
                            }
                            .padding(.vertical, 2)
                        } else {
                            Text(String(format: "%.5f, %.5f", lat, lng)).monospacedDigit()
                        }
                        if let url = URL(string: "https://maps.apple.com/?ll=\(lat),\(lng)&q=\((asset.place?.name ?? "Fotoğraf").addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? "Foto%C4%9Fraf")") {
                            Link("Haritada aç", destination: url)
                        }
                    }
                }
            }
            .themedList(theme)
            .navigationTitle("Ayrıntılar")
            .navigationBarTitleDisplayMode(.inline)
        }
        .task(id: asset.id) {
            guard canEditPeople else { return }
            await loadPeople()
        }
        .task(id: "text-\(asset.id)") {
            await loadText()
        }
        .sheet(item: $picker, onDismiss: applyPendingPick) { target in
            PersonPickerSheet(title: pickerTitle(target), excluding: pickerExcluding(target)) { choice in
                pendingPick = (target, choice)
            }
            .environmentObject(session)
            .environment(\.theme, theme)
        }
        .mergeOfferAlert($mergeOffer) { o in Task { await mergeCluster(o) } }
        .peopleErrorAlert($peopleError)
    }

    // MARK: - Fotoğraftaki yazı (OCR)

    /// Kısa yazı tümüyle, uzun yazı ilk 4 satır (Tümünü göster); aramadan gelindiyse sorgu sözcükleri vurgulu.
    private func textSection(_ t: AssetText) -> some View {
        let tokens = OCRText.tokens(asset.textMatch?.term ?? "")
        let long = t.lines.count > 4
        let shown = (long && !textExpanded) ? Array(t.lines.prefix(4)) : t.lines
        return Section {
            VStack(alignment: .leading, spacing: 3) {
                ForEach(Array(shown.enumerated()), id: \.offset) { _, line in
                    Text(highlighted(line, tokens))
                        .font(.system(size: 15, design: theme.name == .galeri ? .serif : .default))
                        .foregroundStyle(theme.ink)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
            .textSelection(.enabled)
            .padding(.vertical, 4)
            .accessibilityElement(children: .combine)
            if long {
                Button(textExpanded ? "Daha az göster" : "Tümünü göster (\(t.lines.count) satır)") {
                    withAnimation(.easeInOut(duration: 0.2)) { textExpanded.toggle() }
                }
                .buttonStyle(.borderless)
                .foregroundStyle(theme.name == .yerel ? theme.accent : theme.ink)
            }
            Button {
                UIPasteboard.general.string = t.lines.joined(separator: "\n")
                textCopied = true
                Task { try? await Task.sleep(nanoseconds: 1_600_000_000); textCopied = false }
            } label: {
                Label(textCopied ? "Kopyalandı" : "Yazıyı kopyala", systemImage: textCopied ? "checkmark" : "doc.on.doc")
                    .foregroundStyle(textCopied ? theme.success : (theme.name == .yerel ? theme.accent : theme.ink))
            }
            .buttonStyle(.borderless)
            .sensoryFeedback(.success, trigger: textCopied) { _, new in new }
        } header: {
            HStack(spacing: 6) {
                Image(systemName: "text.viewfinder")
                Text("Fotoğraftaki yazı")
                Spacer()
                Text("\(t.words) sözcük").monospacedDigit().foregroundStyle(theme.muted)
            }
        }
    }

    private func highlighted(_ line: String, _ tokens: [String]) -> AttributedString {
        let ranges = OCRText.hitRanges(in: line, tokens: tokens)
        guard !ranges.isEmpty else { return AttributedString(line) }
        var out = AttributedString()
        var prev = line.startIndex
        for r in ranges {
            out += AttributedString(String(line[prev..<r.lowerBound]))
            var hit = AttributedString(String(line[r]))
            hit.backgroundColor = theme.name == .galeri ? theme.accent.opacity(0.16) : Color.yellow.opacity(0.42)
            hit.inlinePresentationIntent = .stronglyEmphasized
            out += hit
            prev = r.upperBound
        }
        out += AttributedString(String(line[prev...]))
        return out
    }

    private func loadText() async {
        guard let client = session.client, !asset.isVideo else { return }
        textExpanded = asset.textMatch != nil
        do {
            recognized = try await client.send("GET", "assets/\(asset.id)/text", as: AssetText.self)
        } catch {
            recognized = nil  // eski sunucu (404) ya da ağ hatası: bölüm gösterilmez
        }
    }

    // MARK: - Kişiler

    @ViewBuilder
    private var peopleSection: some View {
        Section("Kişiler") {
            if let people {
                if !people.chips.isEmpty {
                    PeopleChipFlow(spacing: 8) {
                        ForEach(people.chips) { p in personChip(p, faceId: people.face(of: p.id)?.id) }
                    }
                    .padding(.vertical, 4)
                }
                if !people.unnamedFaces.isEmpty {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(alignment: .top, spacing: 14) {
                            ForEach(people.unnamedFaces) { f in unnamedFace(f) }
                        }
                        .padding(.vertical, 4)
                    }
                }
                Button { picker = .add } label: {
                    Label("Kişi ekle", systemImage: "person.badge.plus")
                        .foregroundStyle(theme.name == .yerel ? theme.accent : theme.ink)
                }
                .buttonStyle(.borderless)
                .disabled(peopleBusy)
            } else if peopleLoadFailed {
                Button("Kişiler yüklenemedi. Tekrar dene") { Task { await loadPeople() } }
                    .buttonStyle(.borderless)
                    .foregroundStyle(theme.muted)
            } else {
                ProgressView().tint(theme.muted)
            }
        }
    }

    private func personChip(_ p: AssetPersonTag, faceId: Int?) -> some View {
        HStack(spacing: 6) {
            FaceAvatar(faceId: faceId, name: p.name, size: 26)
            Text(p.displayName)
                .font(.system(size: 14, weight: .medium))
                .foregroundStyle(theme.ink)
                .lineLimit(1)
            Button { Task { await removePerson(p) } } label: {
                Image(systemName: "xmark")
                    .font(.system(size: 10, weight: .bold))
                    .foregroundStyle(theme.muted)
                    .frame(width: 22, height: 22)
                    .contentShape(Rectangle())
            }
            .buttonStyle(.borderless)
            .disabled(peopleBusy)
            .accessibilityLabel(Text(verbatim: "\(p.displayName) kişisini bu fotoğraftan çıkar"))
        }
        .padding(.leading, 3)
        .padding(.trailing, 4)
        .frame(height: 32)
        .background(theme.name == .galeri ? AnyShapeStyle(Color.clear) : AnyShapeStyle(Color.primary.opacity(0.08)),
                    in: RoundedRectangle(cornerRadius: theme.name == .galeri ? 2 : 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: theme.name == .galeri ? 2 : 16, style: .continuous)
            .stroke(theme.hairline, lineWidth: theme.name == .yerel ? 0 : 1))
    }

    private func unnamedFace(_ f: DetectedFace) -> some View {
        Button { picker = .face(f) } label: {
            VStack(spacing: 5) {
                FaceAvatar(faceId: f.id, size: 52)
                Text("Adlandır")
                    .font(.system(size: 12, weight: .semibold))
                    .foregroundStyle(theme.name == .yerel ? theme.accent : theme.ink)
            }
            .contentShape(Rectangle())
        }
        .buttonStyle(.borderless)
        .disabled(peopleBusy)
        .accessibilityLabel("Bu yüzü adlandır")
    }

    private func pickerTitle(_ t: PeoplePicker) -> String {
        switch t {
        case .add: return "Kişi ekle"
        case .face: return "Bu kim?"
        }
    }

    private func pickerExcluding(_ t: PeoplePicker) -> Set<Int> {
        switch t {
        case .add:
            return Set((people?.people ?? []).filter(\.isNamed).map(\.id))
        case .face(let f):
            if let pid = f.personId { return [pid] }
            return []
        }
    }

    private func loadPeople() async {
        guard let client = session.client else { return }
        do {
            people = try await client.send("GET", "assets/\(asset.id)/people", as: AssetPeople.self)
            peopleLoadFailed = false
        } catch let APIError.server(status, _, _, _) where status == 404 {
            peopleUnavailable = true
        } catch {
            if people == nil { peopleLoadFailed = true }
        }
    }

    /// Seçici kapandıktan sonra uygulanır (birleştirme sorusu sayfa kapanırken kaybolmasın diye).
    private func applyPendingPick() {
        guard let pending = pendingPick else { return }
        pendingPick = nil
        Task { await apply(pending.target, pending.choice) }
    }

    private func apply(_ target: PeoplePicker, _ choice: PersonPickerSheet.Choice) async {
        guard let client = session.client else { return }
        peopleBusy = true
        defer { peopleBusy = false }
        do {
            switch target {
            case .add:
                people = try await client.send("POST", "assets/\(asset.id)/people", body: PeopleAPI.body(for: choice), as: AssetPeople.self)
            case .face(let f):
                if let pid = f.personId, case .new(let name) = choice {
                    // Adsız kümedeki yüze yeni ad: kümenin tamamı adlandırılır (ad başkasındaysa birleştirme önerilir).
                    // Var olan bir kişi seçilirse yalnızca bu yüz atanır (web ile aynı; küme sessizce birleştirilmez).
                    switch try await PeopleAPI.rename(client, pid, name: name) {
                    case .done:
                        await loadPeople()
                    case let .conflict(otherId, otherName):
                        mergeOffer = MergeOffer(sourceId: pid, targetId: otherId, targetName: otherName)
                    }
                } else {
                    people = try await client.send("PATCH", "faces/\(f.id)", body: PeopleAPI.body(for: choice), as: AssetPeople.self)
                }
            }
        } catch {
            peopleError = error.localizedDescription
        }
    }

    private func mergeCluster(_ o: MergeOffer) async {
        guard let client = session.client else { return }
        peopleBusy = true
        defer { peopleBusy = false }
        do {
            _ = try await PeopleAPI.merge(client, o.sourceId, into: o.targetId)
        } catch {
            peopleError = error.localizedDescription
        }
        await loadPeople()
    }

    private func removePerson(_ p: AssetPersonTag) async {
        guard let client = session.client else { return }
        peopleBusy = true
        defer { peopleBusy = false }
        do {
            people = try await client.send("DELETE", "assets/\(asset.id)/people/\(p.id)", as: AssetPeople.self)
        } catch {
            peopleError = error.localizedDescription
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
