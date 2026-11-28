import SwiftUI

/// Anı metinleri için tarih biçimi (aktörden bağımsız; görünümlerin her yerinden çağrılabilir).
enum StoryText {
    /// Fotoğrafın yerel çekim günü: "28 Kasım 2023".
    static func date(_ a: Asset) -> String {
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

/// Anı kartlarında kullanılan metinler.
extension Memory {
    /// Kapak fotoğrafının tarihi ("28 Kasım 2023"); yoksa öğe sayısı.
    var dateLine: String {
        let cover = assets.first { $0.id == coverAssetId } ?? assets.first
        let c = cover.map { StoryText.date($0) } ?? ""
        return c.isEmpty ? countLine : c
    }

    var countLine: String { "\(count) öğe" }
}

/// Henüz anı yokken Sinema'nın üst afişi: kitaplıktan bir fotoğraf, kenardan kenara.
struct FeaturedHero: View {
    let asset: Asset
    var onOpen: () -> Void
    @Environment(\.theme) private var theme

    private var month: String { MonthTitle.text(for: String((asset.takenLocal ?? asset.takenAt).prefix(7))) }

    var body: some View {
        Button(action: onOpen) {
            Color.clear
                .frame(maxWidth: .infinity)
                .frame(height: 300)
                .overlay { RemoteImage(assetId: asset.id, variant: "preview") }
                .overlay(LinearGradient(stops: [.init(color: theme.background, location: 0),
                                                .init(color: theme.background.opacity(0.55), location: 0.28),
                                                .init(color: .clear, location: 0.7)],
                                        startPoint: .bottom, endPoint: .top))
                .overlay(alignment: .bottomLeading) {
                    VStack(alignment: .leading, spacing: 6) {
                        HStack(spacing: 8) {
                            Rectangle().fill(theme.muted).frame(width: 18, height: 1)
                            Text("Kitaplığınızdan").font(.system(size: 13)).foregroundStyle(Color(hex: 0xD9DBE0))
                        }
                        Text(month)
                            .font(.system(size: 34, weight: .regular, design: .serif))
                            .foregroundStyle(.white)
                        Text(StoryText.date(asset))
                            .font(.system(size: 13))
                            .foregroundStyle(Color(hex: 0xD9DBE0))
                    }
                    .padding(.horizontal, 20).padding(.bottom, 18)
                }
                .clipped()
                .contentShape(Rectangle())
        }
        .buttonStyle(PressableStyle())
        .accessibilityLabel("Kitaplığınızdan, \(month)")
    }
}

/// Kitaplığın üstündeki anılar şeridi. Düzen temaya göre değişir:
/// Sinema büyük bir afişle açılır, Galeri sergi duvarı gibi dizer, Yerel zengin kapaklı kartlar gösterir.
struct MemoriesStrip: View {
    let memories: [Memory]
    var onOpen: (Memory) -> Void
    @Environment(\.theme) private var theme

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            if theme.memoryLayout == .cards {
                Text("Anılar")
                    .font(theme.sectionFont)
                    .foregroundStyle(theme.ink)
                    .padding(.horizontal, 20)
            }
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(alignment: .top, spacing: theme.memoryLayout == .hero ? 10 : 12) {
                    ForEach(Array(memories.enumerated()), id: \.element.id) { i, m in
                        Button { onOpen(m) } label: { card(m, index: i) }
                            .buttonStyle(PressableStyle())
                    }
                }
                .padding(.horizontal, 20)
                .padding(.bottom, theme.memoryLayout == .wall ? 22 : 8)
            }
        }
        .padding(.top, theme.memoryLayout == .hero ? 4 : 0)
    }

    @ViewBuilder
    private func card(_ m: Memory, index: Int) -> some View {
        switch theme.memoryLayout {
        case .hero: PosterCard(memory: m, big: index == 0)
        case .wall: WallCard(memory: m)
        case .cards: CoverCard(memory: m)
        }
    }
}

/// Eski adla erişim (tek tip kart gereken yerler için): temanın kart biçimini kullanır.
struct MemoryCard: View {
    let memory: Memory
    @Environment(\.theme) private var theme

    var body: some View {
        switch theme.memoryLayout {
        case .hero: PosterCard(memory: memory, big: false)
        case .wall: WallCard(memory: memory)
        case .cards: CoverCard(memory: memory)
        }
    }
}

/// Sinema: afiş kartı. Kenardan kenara kapak, alttan koyu geçiş, küçük üst satır ve serif başlık.
private struct PosterCard: View {
    let memory: Memory
    let big: Bool

    var body: some View {
        RemoteImage(assetId: memory.coverAssetId, variant: "preview")
            .frame(width: big ? 220 : 136, height: 184)
            .overlay(LinearGradient(stops: [.init(color: .black.opacity(0.78), location: 0), .init(color: .clear, location: 0.58)],
                                    startPoint: .bottom, endPoint: .top))
            .overlay(alignment: .bottomLeading) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(memory.dateLine)
                        .font(.system(size: 11))
                        .foregroundStyle(Color(hex: 0xD9DBE0))
                        .lineLimit(1)
                    Text(memory.title)
                        .font(.system(size: big ? 19 : 17, weight: .regular, design: .serif))
                        .foregroundStyle(.white)
                        .lineLimit(2)
                        .multilineTextAlignment(.leading)
                }
                .padding(.horizontal, 12).padding(.bottom, 11)
            }
            .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(Color.white.opacity(0.08), lineWidth: 1))
            .shadow(color: .black.opacity(0.6), radius: 20, y: 14)
    }
}

/// Galeri: sergi duvarındaki çerçeveli eser; altında serif başlık ve sessiz bir künye.
private struct WallCard: View {
    let memory: Memory
    @Environment(\.theme) private var theme

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            RemoteImage(assetId: memory.coverAssetId, variant: "preview")
                .frame(width: 132, height: 176)
                .clipShape(RoundedRectangle(cornerRadius: 2, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 2, style: .continuous).stroke(theme.hairline, lineWidth: 0.5))
            Text(memory.title)
                .font(.system(size: 15, weight: .regular, design: .serif))
                .foregroundStyle(theme.ink)
                .lineLimit(2)
                .multilineTextAlignment(.leading)
                .padding(.top, 9)
            Text(memory.dateLine)
                .font(.system(size: 12))
                .foregroundStyle(theme.muted)
                .lineLimit(1)
                .padding(.top, 1)
        }
        .frame(width: 132, alignment: .leading)
    }
}

/// Yerel: Fotoğraflar uygulamasındaki gibi zengin kapak kartı; büyük kalın başlık, ince cam kenar.
private struct CoverCard: View {
    let memory: Memory

    var body: some View {
        RemoteImage(assetId: memory.coverAssetId, variant: "preview")
            .frame(width: 250, height: 300)
            .overlay(LinearGradient(stops: [.init(color: .clear, location: 0.38), .init(color: .black.opacity(0.66), location: 1)],
                                    startPoint: .top, endPoint: .bottom))
            .overlay(alignment: .bottomLeading) {
                VStack(alignment: .leading, spacing: 4) {
                    Text(memory.dateLine)
                        .font(.system(size: 13, weight: .semibold))
                        .opacity(0.86)
                        .lineLimit(1)
                    Text(memory.title)
                        .font(.system(size: 23, weight: .bold))
                        .tracking(-0.4)
                        .lineLimit(2)
                        .multilineTextAlignment(.leading)
                }
                .foregroundStyle(.white)
                .shadow(color: .black.opacity(0.25), radius: 8, y: 1)
                .padding(.horizontal, 20).padding(.bottom, 18)
            }
            .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(Color.white.opacity(0.14), lineWidth: 0.5))
            .shadow(color: Color(red: 20 / 255, green: 20 / 255, blue: 40 / 255).opacity(0.22), radius: 16, y: 10)
    }
}

struct PressableStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? 0.96 : 1)
            .animation(.spring(response: 0.25, dampingFraction: 0.7), value: configuration.isPressed)
    }
}

/// Bildirim/widget'tan gelen anıyı sunucudan yükleyip gösterir.
struct MemoryLoaderView: View {
    let target: AppRouter.MemoryTarget
    @Environment(\.theme) private var theme
    @EnvironmentObject private var session: Session
    @Environment(\.dismiss) private var dismiss
    @State private var memory: Memory?
    @State private var failed = false

    var body: some View {
        Group {
            if let memory {
                MemoryStoryView(memory: memory)
            } else {
                ZStack {
                    storyBackdrop(theme).ignoresSafeArea()
                    if failed {
                        VStack(spacing: 14) {
                            Text("Anı yüklenemedi.").foregroundStyle(.white)
                            Button("Kapat") { dismiss() }
                        }
                    } else {
                        ProgressView().tint(.white)
                    }
                }
            }
        }
        .task {
            guard let client = session.client else { failed = true; return }
            let r: MemoriesResponse? = try? await client.send("GET", "memories", query: ["date": target.requestDate])
            memory = r?.memories.first { $0.id == target.memoryId } ?? r?.memories.first
            failed = memory == nil
        }
    }
}

/// Hikâye arka planı: Galeri'de karanlık sergi salonu (#0D0E0F), diğerlerinde siyah.
func storyBackdrop(_ theme: AppTheme) -> Color {
    theme.name == .galeri ? Color(hex: 0x0D0E0F) : .black
}

/// Tam ekran anı gösterisi: ilerleme çubukları, otomatik ilerleme, dokununca ileri/geri, basılı tutunca durur.
/// Görünüm temaya göre: Sinema serif afiş başlığı ve cam düğmeler; Galeri karanlık salon, italik künye ve
/// yavaş çözülmeler; Yerel SF kalın başlık ve cam daireler.
struct MemoryStoryView: View {
    let memory: Memory
    @Environment(\.dismiss) private var dismiss
    @Environment(\.theme) private var theme
    @EnvironmentObject private var session: Session
    /// Geziler: anıdaki gezi albüme dönüştürüldüyse albüm kimliği (sunucudan ya da bu oturumda).
    @State private var tripAlbumId: String?
    @State private var tripBusy = false
    @State private var index = 0
    @State private var progress: Double = 0
    @State private var paused = false
    @State private var showViewer = false
    private let step: Double = 5
    private let tick: Double = 0.05

    private var asset: Asset { memory.assets[min(index, memory.assets.count - 1)] }

    var body: some View {
        GeometryReader { geo in
            ZStack {
                storyBackdrop(theme).ignoresSafeArea()
                photoLayer
                scrim.ignoresSafeArea().allowsHitTesting(false)
                HStack(spacing: 0) {
                    Color.clear.contentShape(Rectangle()).frame(width: geo.size.width * 0.35).onTapGesture { go(index - 1) }
                    Color.clear.contentShape(Rectangle()).onTapGesture { go(index + 1) }
                }
                .onLongPressGesture(minimumDuration: 0.25, maximumDistance: 30, perform: {}, onPressingChanged: { pressing in paused = pressing })
                VStack(alignment: .leading, spacing: 0) {
                    progressBars
                    topBar.padding(.top, theme.name == .galeri ? 12 : 14)
                    Spacer(minLength: 0)
                    bottomBlock
                }
                .padding(.horizontal, 16)
                .padding(.top, 8)
                .padding(.bottom, 12)
            }
        }
        .statusBarHidden()
        // Yalnız bu ağaç koyu: preferredColorScheme sunum üstünden pencereye sızıp kapanınca
        // Yerel'in sistem görünümüne dönmesini engelleyebiliyor.
        .environment(\.colorScheme, .dark)
        .animation(.easeInOut(duration: theme.name == .galeri ? 1.2 : 0.6), value: index)
        .task(id: index) {
            progress = 0
            while progress < 1 {
                try? await Task.sleep(nanoseconds: UInt64(tick * 1_000_000_000))
                if Task.isCancelled { return }
                if !paused && !showViewer { progress += tick / step }
            }
            go(index + 1)
        }
        .fullScreenCover(isPresented: $showViewer, onDismiss: { paused = false }) {
            AssetViewer(assets: memory.assets, startId: asset.id, onChange: { _ in })
                .environment(\.theme, theme)
        }
    }

    // MARK: Katmanlar

    /// Kenardan kenara fotoğraf; yavaş Ken Burns yakınlaşması (Yerel'de daha az).
    private var photoLayer: some View {
        Color.clear
            .overlay {
                ZStack {
                    RemoteImage(assetId: asset.id, variant: "thumb", showsPlaceholder: false)
                    RemoteImage(assetId: asset.id, variant: "preview", showsPlaceholder: false)
                }
                .scaleEffect(CGFloat(1 + (theme.name == .yerel ? 0.03 : 0.07) * progress))
            }
            .clipped()
            .ignoresSafeArea()
            .id(asset.id)
            .transition(.opacity)
    }

    private var scrim: LinearGradient {
        switch theme.name {
        case .sinema:
            return LinearGradient(stops: [
                .init(color: .black.opacity(0.62), location: 0), .init(color: .black.opacity(0.2), location: 0.16),
                .init(color: .clear, location: 0.26), .init(color: .clear, location: 0.52),
                .init(color: .black.opacity(0.82), location: 1),
            ], startPoint: .top, endPoint: .bottom)
        case .galeri:
            return LinearGradient(stops: [
                .init(color: .black.opacity(0.35), location: 0), .init(color: .clear, location: 0.22),
                .init(color: .clear, location: 0.52), .init(color: .black.opacity(0.62), location: 1),
            ], startPoint: .top, endPoint: .bottom)
        case .yerel:
            return LinearGradient(stops: [
                .init(color: .black.opacity(0.74), location: 0), .init(color: .black.opacity(0.44), location: 0.26),
                .init(color: .clear, location: 0.44), .init(color: .clear, location: 0.64),
                .init(color: .black.opacity(0.62), location: 1),
            ], startPoint: .top, endPoint: .bottom)
        }
    }

    private var progressBars: some View {
        HStack(spacing: 4) {
            ForEach(memory.assets.indices, id: \.self) { i in
                GeometryReader { g in
                    Capsule().fill(.white.opacity(theme.name == .galeri ? 0.35 : 0.3))
                        .overlay(alignment: .leading) {
                            Capsule().fill(.white).frame(width: g.size.width * fill(i))
                        }
                }
                .frame(height: theme.name == .galeri ? 2 : 2.5)
            }
        }
        .allowsHitTesting(false)
    }

    @ViewBuilder
    private var topBar: some View {
        switch theme.name {
        case .sinema:
            HStack(spacing: 10) {
                Circle().fill(.white.opacity(0.12))
                    .overlay(Image(systemName: "sparkles").font(.system(size: 13, weight: .medium)))
                    .overlay(Circle().stroke(.white.opacity(0.7), lineWidth: 1.5))
                    .frame(width: 32, height: 32)
                    .allowsHitTesting(false)
                VStack(alignment: .leading, spacing: 0) {
                    Text(memory.title).font(.system(size: 14, weight: .semibold))
                    Text(memory.dateLine).font(.system(size: 12)).foregroundStyle(Color(hex: 0xE2E4E8))
                }
                .allowsHitTesting(false)
                Spacer()
                plainButton("xmark", label: "Kapat") { dismiss() }
            }
            .foregroundStyle(.white)
        case .galeri:
            HStack {
                Text("Anılar").font(.system(size: 13)).allowsHitTesting(false)
                Spacer()
                plainButton("xmark", label: "Kapat") { dismiss() }
            }
            .foregroundStyle(.white)
        case .yerel:
            HStack(alignment: .top, spacing: 8) {
                VStack(alignment: .leading, spacing: 9) {
                    Text(memory.title)
                        .font(.system(size: 28, weight: .bold))
                        .tracking(-0.6)
                        .shadow(color: .black.opacity(0.35), radius: 14)
                        .lineLimit(3)
                    Text("\(memory.dateLine), \(memory.countLine)")
                        .font(.system(size: 14, weight: .medium))
                        .opacity(0.9)
                }
                .allowsHitTesting(false)
                Spacer(minLength: 8)
                glassButton("xmark", label: "Kapat") { dismiss() }
            }
            .foregroundStyle(.white)
            .padding(.leading, 4)
        }
    }

    @ViewBuilder
    private var bottomBlock: some View {
        switch theme.name {
        case .sinema:
            VStack(alignment: .leading, spacing: 0) {
                Text(StoryText.date(asset)).font(.system(size: 14)).foregroundStyle(Color(hex: 0xDADCE1))
                    .allowsHitTesting(false)
                Text(memory.title)
                    .font(.system(size: 38, weight: .regular, design: .serif))
                    .tracking(-0.7)
                    .lineLimit(3)
                    .padding(.top, 8).padding(.bottom, 10)
                    .allowsHitTesting(false)
                Text(memory.countLine).font(.system(size: 14)).foregroundStyle(Color(hex: 0xC9CCD3))
                    .allowsHitTesting(false)
                HStack(spacing: 10) {
                    Spacer()
                    tripAlbumGlass
                    glassButton("photo", label: "Fotoğrafı aç") { openViewer() }
                }
                .padding(.top, 22)
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 8)
        case .galeri:
            VStack(alignment: .leading, spacing: 0) {
                Text(StoryText.date(asset))
                    .font(.system(size: 17, design: .serif).italic())
                    .opacity(0.85)
                    .allowsHitTesting(false)
                Text(memory.title)
                    .font(.system(size: 40, weight: .regular, design: .serif))
                    .tracking(-0.6)
                    .lineLimit(3)
                    .padding(.top, 6)
                    .allowsHitTesting(false)
                Text(memory.countLine).font(.system(size: 14)).opacity(0.85).padding(.top, 10)
                    .allowsHitTesting(false)
                HStack {
                    Button { openViewer() } label: {
                        Label("Fotoğrafı aç", systemImage: "photo").font(.system(size: 13))
                    }
                    if memory.tripId != nil {
                        Button { Task { await convertTrip() } } label: {
                            Label(albumMade ? "Albüm oluşturuldu" : "Albüme dönüştür", systemImage: albumMade ? "checkmark" : "rectangle.stack.badge.plus")
                                .font(.system(size: 13))
                        }
                        .disabled(albumMade || tripBusy)
                        .padding(.leading, 14)
                    }
                    Spacer()
                    Text(verbatim: "\(index + 1) / \(memory.assets.count)")
                        .font(.system(size: 13, design: .serif))
                        .opacity(0.85)
                        .allowsHitTesting(false)
                }
                .padding(.top, 40)
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 8)
        case .yerel:
            HStack(spacing: 10) {
                VStack(alignment: .leading, spacing: 1) {
                    Text(StoryText.date(asset)).font(.system(size: 15, weight: .semibold))
                    Text(memory.countLine).font(.system(size: 13)).opacity(0.85)
                }
                .allowsHitTesting(false)
                Spacer(minLength: 8)
                tripAlbumGlass
                glassButton("photo", label: "Fotoğrafı aç") { openViewer() }
            }
            .foregroundStyle(.white)
        }
    }

    private func plainButton(_ icon: String, label: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 17, weight: .medium)).frame(width: 40, height: 40)
        }
        .accessibilityLabel(label)
    }

    /// Cam daire düğme (Sinema 48, Yerel 36).
    private func glassButton(_ icon: String, label: String, action: @escaping () -> Void) -> some View {
        let size: CGFloat = theme.name == .sinema ? 48 : 36
        return Button(action: action) {
            Image(systemName: icon)
                .font(.system(size: theme.name == .sinema ? 18 : 15, weight: .semibold))
                .foregroundStyle(.white)
                .frame(width: size, height: size)
                .background(.ultraThinMaterial, in: Circle())
                .overlay(Circle().stroke(.white.opacity(0.14), lineWidth: 1))
        }
        .environment(\.colorScheme, .dark)
        .accessibilityLabel(label)
    }

    private func openViewer() {
        showViewer = true
        paused = true
    }

    // MARK: Geziler — "Albüme dönüştür"

    private var albumMade: Bool { (tripAlbumId ?? memory.albumId) != nil }

    @ViewBuilder
    private var tripAlbumGlass: some View {
        if memory.tripId != nil {
            glassButton(albumMade ? "checkmark" : "rectangle.stack.badge.plus", label: albumMade ? "Albüm oluşturuldu" : "Albüme dönüştür") {
                Task { await convertTrip() }
            }
            .disabled(albumMade || tripBusy)
        }
    }

    private func convertTrip() async {
        guard let id = memory.tripId, !albumMade, let client = session.client else { return }
        tripBusy = true
        paused = true
        defer { tripBusy = false }
        if let r = try? await client.send("POST", "trips/\(id)/album", body: EmptyBody(), as: TripAlbumResponse.self) {
            tripAlbumId = r.album.id
        }
    }

    private func fill(_ i: Int) -> Double { i < index ? 1 : (i == index ? progress : 0) }

    private func go(_ n: Int) {
        if n >= memory.assets.count { dismiss(); return }
        index = max(0, n)
    }
}
