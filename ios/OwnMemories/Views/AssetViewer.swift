import AVKit
import SwiftUI

struct AssetViewer: View {
    let assets: [Asset]
    let startId: String
    var onChange: (Asset) -> Void
    @EnvironmentObject private var session: Session
    @Environment(\.dismiss) private var dismiss
    @State private var selection: String = ""
    @State private var current: [String: Asset] = [:]
    @State private var showInfo = false
    @State private var chrome = true
    /// Aşağı/yukarı çekerek kapatma (iOS Fotoğraflar gibi): içerik parmağı izler, arka plan solar.
    @State private var dragY: CGFloat = 0

    private var dragProgress: CGFloat { min(abs(dragY) / 320, 1) }

    private func asset(_ id: String) -> Asset? { current[id] ?? assets.first { $0.id == id } }

    var body: some View {
        ZStack(alignment: .top) {
            Color.black.opacity(Double(1 - dragProgress * 0.85)).ignoresSafeArea()
            TabView(selection: $selection) {
                ForEach(assets) { a in
                    AssetPageView(asset: a, dragY: $dragY, onDismiss: dismissBySwipe)
                        .tag(a.id)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: .never))
            .ignoresSafeArea()
            .offset(y: dragY)
            .scaleEffect(1 - dragProgress * 0.25)
            .onTapGesture { withAnimation(.easeInOut(duration: 0.2)) { chrome.toggle() } }

            HStack {
                Button { dismiss() } label: { Image(systemName: "xmark").padding(10) }
                Spacer()
                if let a = asset(selection), a.access != "view" {
                    Button { Task { await toggleFavorite(a) } } label: {
                        Image(systemName: a.isFavorite ? "heart.fill" : "heart").padding(10)
                    }
                }
                Button { showInfo = true } label: { Image(systemName: "info.circle").padding(10) }
            }
            .font(.title3)
            .foregroundStyle(.white)
            .padding(.horizontal, 8)
            .background(LinearGradient(colors: [.black.opacity(0.6), .clear], startPoint: .top, endPoint: .bottom).ignoresSafeArea())
            .opacity(chrome && dragY == 0 ? 1 : 0)
        }
        .presentationBackground(.clear)
        .statusBarHidden(!chrome)
        .onAppear { selection = startId }
        .sheet(isPresented: $showInfo) {
            if let a = asset(selection) { AssetInfoView(asset: a).presentationDetents([.medium, .large]) }
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
    @Binding var dragY: CGFloat
    var onDismiss: () -> Void
    @EnvironmentObject private var session: Session
    @State private var player: AVPlayer?
    @State private var scale: CGFloat = 1
    /// Bir sürüklemenin yönü ilk harekette belirlenir: yatay olanlar sayfa değiştirir, dikey olanlar kapatır.
    @State private var dragAxis: Axis?

    private var dismissGesture: some Gesture {
        DragGesture(minimumDistance: 12)
            .onChanged { v in
                guard scale <= 1.01 else { return }
                if dragAxis == nil {
                    dragAxis = abs(v.translation.height) > abs(v.translation.width) ? .vertical : .horizontal
                }
                if dragAxis == .vertical { dragY = v.translation.height }
            }
            .onEnded { v in
                defer { dragAxis = nil }
                guard dragAxis == .vertical, scale <= 1.01 else { return }
                if abs(v.translation.height) > 110 || abs(v.predictedEndTranslation.height) > 320 {
                    onDismiss()
                } else {
                    withAnimation(.spring(response: 0.32, dampingFraction: 0.86)) { dragY = 0 }
                }
            }
    }

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
                    RemoteImage(assetId: asset.id, variant: "preview", contentMode: .fit)
                }
                .scaleEffect(scale)
                .gesture(MagnificationGesture().onChanged { scale = max(1, $0) }.onEnded { _ in withAnimation { scale = 1 } })
            }
        }
        .contentShape(Rectangle())
        .simultaneousGesture(dismissGesture)
        .onAppear {
            guard asset.isVideo, player == nil, let client = session.client else { return }
            let url = client.url("assets/\(asset.id)/video")
            var headers: [String: String] = [:]
            if let token = client.token { headers["Authorization"] = "Bearer " + token }
            let item = AVPlayerItem(asset: AVURLAsset(url: url, options: ["AVURLAssetHTTPHeaderFieldsKey": headers]))
            player = AVPlayer(playerItem: item)
        }
        .onDisappear { player?.pause() }
    }
}

private struct AssetInfoView: View {
    let asset: Asset

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
