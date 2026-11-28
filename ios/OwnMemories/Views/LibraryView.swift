import SwiftUI

@MainActor
final class LibraryModel: ObservableObject {
    @Published private(set) var assets: [Asset] = []
    @Published private(set) var sections: [DaySection] = []
    @Published private(set) var loading = false
    @Published private(set) var error: String?
    private var next: AssetCursor?
    private(set) var done = false
    /// Takvimden geçmiş bir güne atlandı: liste o günden başlar, üstte "en yeniye dön" görünür.
    @Published private(set) var jumped = false

    func reload(client: APIClient?) async {
        next = nil
        done = false
        jumped = false
        assets = []
        sections = []
        await loadMore(client: client)
    }

    /// Listeyi verilen imleçten (bir günün en yeni öğesinin hemen sonrası) yeniden başlatır.
    func jump(before: String, client: APIClient?) async {
        guard !loading else { return }
        next = AssetCursor(before: before, beforeId: nil)
        done = false
        jumped = true
        assets = []
        sections = []
        await loadMore(client: client)
    }

    func loadMore(client: APIClient?) async {
        guard let client, !loading, !done else { return }
        loading = true
        defer { loading = false }
        var q = ["limit": "200"]
        if let next {
            q["before"] = next.before
            if let id = next.beforeId { q["before_id"] = id }
        }
        do {
            let page: AssetPage = try await client.send("GET", "assets", query: q)
            assets.append(contentsOf: page.assets)
            sections = DayGrouping.group(assets)
            next = page.next
            done = page.next == nil
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    /// Yerinde yenileme: şu an yüklü aralığı (en eski yüklü öğenin tarihine kadar) baştan yeniden çeker
    /// ve listeyi tek seferde değiştirir. Yeni yedeklenen fotoğraflar tarih sırasındaki yerlerine girer;
    /// liste boşalmadığı için kaydırma konumu korunur.
    /// Başka bir yükleme sürdüğü için çalışamadıysa false döner (çağıran sonra tekrar dener).
    @discardableResult
    func refreshInPlace(client: APIClient?) async -> Bool {
        guard let client else { return true }
        // Geçmiş bir güne atlanmışken liste en baştan çekilmez (kullanıcının yeri bozulmasın).
        if jumped { return true }
        guard !loading else { return false }
        guard let oldest = assets.last?.takenAt else { await reload(client: client); return true }
        loading = true
        defer { loading = false }
        var fresh: [Asset] = []
        var cursor: AssetCursor?
        do {
            repeat {
                var q = ["limit": "500"]
                if let cursor {
                    q["before"] = cursor.before
                    if let id = cursor.beforeId { q["before_id"] = id }
                }
                let page: AssetPage = try await client.send("GET", "assets", query: q)
                fresh.append(contentsOf: page.assets)
                cursor = page.next
            } while cursor != nil && (fresh.last.map { $0.takenAt >= oldest } ?? false) && fresh.count < 20_000
            assets = fresh
            sections = DayGrouping.group(fresh)
            next = cursor
            done = cursor == nil
            error = nil
        } catch {
            // Sessiz: bir sonraki yüklemede ya da aşağı çekince tekrar denenir.
        }
        return true
    }

    func update(_ asset: Asset) {
        if let i = assets.firstIndex(where: { $0.id == asset.id }) {
            assets[i] = asset
            sections = DayGrouping.group(assets)
        }
    }

}

struct LibraryView: View {
    @EnvironmentObject private var session: Session
    @EnvironmentObject private var memories: MemoryService
    @EnvironmentObject private var engine: BackupEngine
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.theme) private var theme
    @StateObject private var model = LibraryModel()
    /// Yedekleme sürerken yeni yüklenenleri listeye getiren, sık istek atmamak için ertelenmiş yenileme.
    @State private var pendingRefresh: Task<Void, Never>?
    /// Yenileme sürerken yeni bir yükleme biterse işaretlenir; tur bitince bir kez daha yenilenir.
    @State private var refreshDirty = false
    @State private var viewing: Asset?
    @State private var story: Memory?
    @State private var featuredStart: Asset?
    @State private var calendarMonth: CalendarTarget?
    @State private var pendingScroll: String?
    @State private var searching = false
    @State private var showingPeople = false

    struct CalendarTarget: Identifiable { let id: String }   // "YYYY-MM" ya da "" (en yeni ay)

    private var columns: [GridItem] {
        Array(repeating: GridItem(.flexible(), spacing: theme.gridSpacing), count: theme.gridColumns)
    }

    var body: some View {
        NavigationStack {
          ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 0, pinnedViews: []) {
                    LibraryHeader(title: "Fotoğraflar", initial: session.user?.name, onSearch: { searching = true }, onPeople: { showingPeople = true }, onCalendar: {
                        calendarMonth = CalendarTarget(id: model.sections.first.map { String($0.id.prefix(7)) } ?? "")
                    })
                    .id("library-top")
                    if model.jumped {
                        Button {
                            Task {
                                await model.reload(client: session.client)
                                withAnimation { proxy.scrollTo("library-top", anchor: .top) }
                            }
                        } label: {
                            Label("En yeni fotoğraflara dön", systemImage: "arrow.up")
                                .font(.system(size: 14, weight: .semibold))
                                .padding(.horizontal, 14).padding(.vertical, 8)
                                .background(theme.surface, in: Capsule())
                                .overlay(Capsule().stroke(theme.hairline, lineWidth: 1))
                        }
                        .buttonStyle(.plain)
                        .frame(maxWidth: .infinity)
                        .padding(.bottom, 8)
                    }
                    if !memories.memories.isEmpty {
                        MemoriesStrip(memories: memories.memories) { story = $0 }
                    } else if theme.memoryLayout == .hero, let f = memories.featured.first {
                        FeaturedHero(asset: f) { featuredStart = f }
                    }
                    ForEach(Array(model.sections.enumerated()), id: \.element.id) { i, section in
                        if i == 0 || model.sections[i - 1].id.prefix(7) != section.id.prefix(7) {
                            monthHeader(String(section.id.prefix(7)), first: i == 0)
                                .contentShape(Rectangle())
                                .onTapGesture { calendarMonth = CalendarTarget(id: String(section.id.prefix(7))) }
                                .accessibilityAddTraits(.isButton)
                                .accessibilityHint("Takvimi açar")
                        }
                        Text(DayGrouping.title(for: section.id))
                            .id("day-" + section.id)
                            .font(theme.dayFont)
                            .foregroundStyle(theme.name == .galeri ? theme.ink : theme.ink.opacity(0.92))
                            .padding(.horizontal, 20).padding(.top, 12).padding(.bottom, 8)
                        LazyVGrid(columns: columns, spacing: theme.gridSpacing) {
                            ForEach(section.assets) { asset in
                                Button { viewing = asset } label: { Tile(asset: asset) }
                                    .buttonStyle(.plain)
                                    .onAppear {
                                        if asset.id == model.assets.last?.id { Task { await model.loadMore(client: session.client) } }
                                    }
                            }
                        }
                    }
                    if model.loading {
                        ProgressView().tint(theme.muted).frame(maxWidth: .infinity).padding()
                    } else if let err = model.error {
                        VStack(spacing: 10) {
                            Text(err).multilineTextAlignment(.center).foregroundStyle(theme.muted)
                            Button("Tekrar dene") { Task { await model.loadMore(client: session.client) } }
                        }
                        .frame(maxWidth: .infinity).padding(30)
                    } else if model.assets.isEmpty && model.done {
                        Text("Henüz fotoğraf yok. Yedekleme sekmesinden yedeklemeyi başlatın.")
                            .font(theme.isSerif ? Font.system(.body, design: .serif) : Font.body)
                            .multilineTextAlignment(.center).foregroundStyle(theme.muted).padding(40)
                            .frame(maxWidth: .infinity)
                    }
                }
                .padding(.bottom, 28)
            }
            .foregroundStyle(theme.ink)
            .background(alignment: .top) { AmbientGlow(assetId: ambientAssetId) }
            .background(theme.canvas.ignoresSafeArea())
            .toolbar(.hidden, for: .navigationBar)
            .sheet(item: $calendarMonth) { target in
                CalendarSheet(initialMonth: target.id.isEmpty ? nil : target.id) { day in
                    Task {
                        if model.sections.contains(where: { $0.id == day.day }) && !model.jumped {
                            pendingScroll = day.day
                        } else {
                            await model.jump(before: day.before, client: session.client)
                            pendingScroll = day.day
                        }
                    }
                }
                .environmentObject(session)
                .environment(\.theme, theme)
            }
            .onChange(of: pendingScroll) { _, day in
                guard let day else { return }
                // Sayfa kapanırken kaydırma kaybolmasın: kısa bir gecikmeyle hedef güne git.
                DispatchQueue.main.asyncAfter(deadline: .now() + 0.35) {
                    withAnimation(.easeInOut(duration: 0.3)) { proxy.scrollTo("day-" + day, anchor: .top) }
                    pendingScroll = nil
                }
            }
            .overlay(alignment: .bottom) {
                if showSyncPill {
                    SyncPill(done: engine.counts.done, total: engine.counts.total)
                        .padding(.bottom, 12)
                        .transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
            .refreshable {
                await model.reload(client: session.client)
                await memories.refresh(force: true)
            }
            .task {
                if model.assets.isEmpty { await model.reload(client: session.client) }
                await memories.refresh()
            }
            .onChange(of: engine.counts.done) { old, new in
                // Arka planda biten yüklemeler listeyi yeniden çekmesin; uygulamaya dönünce yenilenir.
                guard new > old, scenePhase == .active else { return }
                scheduleRefresh()
            }
            .onChange(of: engine.isRunning) { was, now in
                // Yedekleme bitti: son yüklenenlerin de listede olduğundan emin ol.
                if was, !now, scenePhase == .active { scheduleRefresh(delay: 1) }
            }
            .onChange(of: scenePhase) { _, phase in
                // Arka planda yüklenenler: uygulamaya dönünce listeyi güncelle.
                if phase == .active, !model.assets.isEmpty { scheduleRefresh(delay: 0.5) }
            }
            .fullScreenCover(item: $story) { m in
                MemoryStoryView(memory: m).environmentObject(session).environment(\.theme, theme)
            }
            .fullScreenCover(item: $featuredStart) { asset in
                AssetViewer(assets: memories.featured, startId: asset.id, onChange: { model.update($0) })
                    .environmentObject(session)
                    .environment(\.theme, theme)
            }
            .fullScreenCover(item: $viewing) { asset in
                AssetViewer(assets: model.assets, startId: asset.id, onChange: { model.update($0) })
                    .environmentObject(session)
                    .environment(\.theme, theme)
            }
            .fullScreenCover(isPresented: $searching) {
                SearchView(onChange: { model.update($0) })
                    .environmentObject(session)
                    .environment(\.theme, theme)
            }
            .fullScreenCover(isPresented: $showingPeople) {
                PeopleView(onChange: { model.update($0) })
                    .environmentObject(session)
                    .environment(\.theme, theme)
            }
          }
        }
    }

    /// Yerel: yedekleme sürerken sekme çubuğunun üstünde durum hapı.
    private var showSyncPill: Bool {
        guard theme.name == .yerel, engine.counts.total > 0 else { return false }
        return engine.isRunning || engine.counts.inFlight > 0
    }

    /// Sinema: ekranın üstünde, günün anısının (yoksa en yeni fotoğrafın) bulanık rengi.
    private var ambientAssetId: String? {
        guard theme.ambientGlow else { return nil }
        return memories.memories.first?.coverAssetId ?? memories.featured.first?.id ?? model.assets.first?.id
    }

    @ViewBuilder
    private func monthHeader(_ ym: String, first: Bool) -> some View {
        let title = Text(MonthTitle.text(for: ym)).font(theme.monthFont)
        switch theme.name {
        case .sinema:
            title.padding(.horizontal, 20).padding(.top, first ? 22 : 30).padding(.bottom, 2)
        case .galeri:
            VStack(alignment: .leading, spacing: 0) {
                Rectangle().fill(theme.hairline).frame(height: 1)
                title.padding(.horizontal, 20).padding(.top, 16).padding(.bottom, 2)
            }
            .padding(.top, first ? 0 : 18)
        case .yerel:
            title.padding(.horizontal, 20).padding(.top, 22).padding(.bottom, 2)
        }
    }

    /// Art arda tamamlanan yüklemeleri birleştirir: ilk tamamlanmadan kısa süre sonra bir kez yeniler;
    /// o sırada gelenler bir sonraki tura kalır (yükleme sürdükçe birkaç saniyede bir güncellenir).
    private func scheduleRefresh(delay: Double = 2.5) {
        refreshDirty = true
        guard pendingRefresh == nil else { return }
        pendingRefresh = Task {
            var wait = delay
            var skipped = 0
            while refreshDirty && skipped < 20 {
                try? await Task.sleep(for: .seconds(wait))
                refreshDirty = false
                if await model.refreshInPlace(client: session.client) {
                    wait = 2.5
                } else {
                    // Sayfa yükleniyordu: kısa süre sonra yeniden dene.
                    refreshDirty = true
                    skipped += 1
                    wait = 1
                }
            }
            pendingRefresh = nil
        }
    }
}

/// Kitaplık/Yedekleme ekran başlığı: Sinema ve Galeri'de serif, Yerel'de SF kalın büyük başlık.
struct LibraryHeader: View {
    let title: String
    var subtitle: String? = nil
    var initial: String? = nil
    var onSearch: (() -> Void)? = nil
    var onPeople: (() -> Void)? = nil
    var onCalendar: (() -> Void)? = nil
    @Environment(\.theme) private var theme

    var body: some View {
        HStack(alignment: .center, spacing: 10) {
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(theme.largeTitle)
                    .tracking(theme.name == .yerel ? -0.8 : -0.5)
                    .foregroundStyle(theme.ink)
                if let subtitle {
                    Text(subtitle).font(.system(size: 13)).foregroundStyle(theme.muted)
                }
            }
            Spacer(minLength: 8)
            if let onSearch {
                headerButton("magnifyingglass", label: "Ara", action: onSearch)
            }
            if let onPeople {
                headerButton("person.2", label: "Kişiler", action: onPeople)
            }
            if let onCalendar {
                headerButton("calendar", label: "Takvim", action: onCalendar)
            }
            if let initial, let ch = initial.first {
                Text(String(ch).uppercased())
                    .font(.system(size: theme.name == .galeri ? 12 : 15, weight: .semibold))
                    .foregroundStyle(theme.name == .yerel ? Color.white : theme.ink)
                    .frame(width: avatarSize, height: avatarSize)
                    .background(avatarFill, in: Circle())
                    .overlay(Circle().stroke(theme.hairline, lineWidth: theme.name == .yerel ? 0 : 1))
                    .accessibilityHidden(true)
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 8)
        .padding(.bottom, theme.name == .galeri ? 16 : 12)
    }

    private var avatarSize: CGFloat { theme.name == .galeri ? 26 : 36 }

    private func headerButton(_ icon: String, label: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon)
                .font(.system(size: 16, weight: .semibold))
                .foregroundStyle(theme.name == .yerel ? theme.accent : theme.ink)
                .frame(width: avatarSize, height: avatarSize)
                .background(theme.name == .galeri ? AnyShapeStyle(Color.clear) : AnyShapeStyle(Color.primary.opacity(0.08)), in: Circle())
                .overlay(Circle().stroke(theme.hairline, lineWidth: theme.name == .yerel ? 0 : 1))
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }

    private var avatarFill: AnyShapeStyle {
        switch theme.name {
        case .sinema: return AnyShapeStyle(Color.white.opacity(0.1))
        case .galeri: return AnyShapeStyle(theme.background)
        case .yerel: return AnyShapeStyle(LinearGradient(colors: [Color(hex: 0x8E8E93), Color(hex: 0x5A5A5F)], startPoint: .top, endPoint: .bottom))
        }
    }
}

/// Sinema ortam ışığı: fotoğrafın yoğun bulanık, doygun, karartılmış hâli; aşağı doğru zemine karışır.
struct AmbientGlow: View {
    let assetId: String?
    var height: CGFloat = 460

    var body: some View {
        if let assetId {
            RemoteImage(assetId: assetId, variant: "thumb")
                .frame(height: height)
                .frame(maxWidth: .infinity)
                .blur(radius: 60, opaque: true)
                .saturation(1.5)
                .brightness(-0.25)
                .opacity(0.9)
                .clipped()
                .mask(LinearGradient(stops: [.init(color: .black, location: 0), .init(color: .black.opacity(0.55), location: 0.45), .init(color: .clear, location: 1)],
                                     startPoint: .top, endPoint: .bottom))
                .ignoresSafeArea(edges: .top)
                .allowsHitTesting(false)
                .accessibilityHidden(true)
        }
    }
}

/// Yerel: sekme çubuğunun üstünde yüzen yedekleme durumu ("Yedekleniyor 4.812 / 6.552").
private struct SyncPill: View {
    let done: Int
    let total: Int
    @Environment(\.theme) private var theme

    var body: some View {
        HStack(spacing: 8) {
            ZStack {
                Circle().stroke(theme.ink.opacity(0.12), lineWidth: 3)
                Circle().trim(from: 0, to: total > 0 ? CGFloat(done) / CGFloat(total) : 0)
                    .stroke(theme.accent, style: StrokeStyle(lineWidth: 3, lineCap: .round))
                    .rotationEffect(.degrees(-90))
            }
            .frame(width: 18, height: 18)
            Text("Yedekleniyor").font(.system(size: 13, weight: .semibold)).foregroundStyle(theme.ink)
            Text(verbatim: "\(done.formatted()) / \(total.formatted())").font(.system(size: 13, weight: .medium)).foregroundStyle(theme.muted)
                .monospacedDigit()
        }
        .padding(.leading, 8).padding(.trailing, 14)
        .frame(height: 34)
        .background(.regularMaterial, in: Capsule())
        .overlay(Capsule().stroke(theme.hairline, lineWidth: 0.5))
        .shadow(color: .black.opacity(0.16), radius: 14, y: 6)
    }
}

enum MonthTitle {
    static func text(for ym: String) -> String {
        let p = ym.split(separator: "-").compactMap { Int($0) }
        guard p.count == 2 else { return ym }
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = TimeZone(identifier: "UTC")!
        guard let d = cal.date(from: DateComponents(year: p[0], month: p[1], day: 15)) else { return ym }
        let f = DateFormatter()
        f.locale = Locale(identifier: "tr_TR")
        f.timeZone = cal.timeZone
        f.setLocalizedDateFormatFromTemplate("MMMMyyyy")
        return f.string(from: d).capitalized(with: Locale(identifier: "tr_TR"))
    }
}

private struct Tile: View {
    let asset: Asset
    @Environment(\.theme) private var theme

    var body: some View {
        theme.placeholder
            .aspectRatio(1, contentMode: .fit)
            .overlay(RemoteImage(assetId: asset.id))
            .clipShape(RoundedRectangle(cornerRadius: theme.tileRadius, style: .continuous))
            .overlay(alignment: .topTrailing) {
                HStack(spacing: 4) {
                    if asset.isVideo {
                        Image(systemName: "play.fill")
                        if let ms = asset.durationMs { Text(String(format: "%ld:%02ld", ms / 60000, (ms / 1000) % 60)) }
                    }
                    if asset.livePairId != nil && !asset.isVideo { Image(systemName: "livephoto") }
                    if asset.isFavorite { Image(systemName: "heart.fill") }
                }
                .font(.system(size: 10, weight: .semibold))
                .foregroundStyle(.white)
                .shadow(color: .black.opacity(0.6), radius: 2, y: 1)
                .padding(5)
            }
            .contentShape(Rectangle())
    }
}
