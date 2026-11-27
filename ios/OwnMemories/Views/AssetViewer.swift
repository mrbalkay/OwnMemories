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

    private func asset(_ id: String) -> Asset? { current[id] ?? assets.first { $0.id == id } }

    var body: some View {
        ZStack(alignment: .top) {
            Color.black.ignoresSafeArea()
            TabView(selection: $selection) {
                ForEach(assets) { a in
                    AssetPageView(asset: a)
                        .tag(a.id)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: .never))
            .ignoresSafeArea()
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
            .opacity(chrome ? 1 : 0)
        }
        .statusBarHidden(!chrome)
        .onAppear { selection = startId }
        .sheet(isPresented: $showInfo) {
            if let a = asset(selection) { AssetInfoView(asset: a).presentationDetents([.medium, .large]) }
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
                    RemoteImage(assetId: asset.id, variant: "preview", contentMode: .fit)
                }
                .scaleEffect(scale)
                .gesture(MagnificationGesture().onChanged { scale = max(1, $0) }.onEnded { _ in withAnimation { scale = 1 } })
            }
        }
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
