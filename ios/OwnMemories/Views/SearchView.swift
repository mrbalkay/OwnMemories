import SwiftUI

/// Akıllı arama durumu: GET /v1/search sonuçları (uygunluk sırasında), sayfalama ve dizin durumu.
/// Her yeni arama bir "nesil" açar; eski neslin geç gelen yanıtı yok sayılır.
@MainActor
final class SearchModel: ObservableObject {
    @Published private(set) var results: [Asset] = []
    /// `results` hangi sorgunun sonuçları (yazarken eski sonuçlar soluk görünmeye devam eder).
    @Published private(set) var resultsQuery = ""
    /// Son gönderilen sorgu (yükleniyor olabilir).
    @Published private(set) var query = ""
    @Published private(set) var mode: String?
    @Published private(set) var status: SearchStatus?
    /// Sunucunun bildirdiği toplam eşleşme sayısı (ilk sayfada gelir).
    @Published private(set) var total: Int?
    @Published private(set) var loading = false
    @Published private(set) var error: String?
    private var nextOffset: Int?
    private var generation = 0
    private var task: Task<Void, Never>?

    static let pageSize = 60

    var hasMore: Bool { nextOffset != nil }

    /// Yeni arama başlatır. Aynı sorgu zaten gösteriliyor/yükleniyorsa (ve hata yoksa) bir şey yapmaz.
    func start(_ raw: String, client: APIClient?, force: Bool = false) {
        let q = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !q.isEmpty else { clear(); return }
        if !force && q == query && error == nil { return }
        task?.cancel()
        generation += 1
        let g = generation
        query = q
        nextOffset = nil
        error = nil
        loading = true
        task = Task { [weak self] in await self?.fetch(offset: 0, generation: g, client: client) }
    }

    /// Sonuç listesinin sonuna gelindi: bir sonraki sayfayı yükler.
    func loadMore(client: APIClient?) {
        guard let offset = nextOffset, !loading, error == nil, !query.isEmpty else { return }
        loading = true
        let g = generation
        task = Task { [weak self] in await self?.fetch(offset: offset, generation: g, client: client) }
    }

    /// Sayfa yükleme hatasından sonra "Tekrar dene".
    func retryMore(client: APIClient?) {
        error = nil
        loadMore(client: client)
    }

    func clear() {
        task?.cancel()
        task = nil
        generation += 1
        query = ""
        resultsQuery = ""
        results = []
        mode = nil
        total = nil
        nextOffset = nil
        error = nil
        loading = false
    }

    func cancel() {
        task?.cancel()
        task = nil
    }

    func update(_ asset: Asset) {
        if let i = results.firstIndex(where: { $0.id == asset.id }) { results[i] = asset }
    }

    /// Boş ekranda dizinleme notunu gösterebilmek için GET /v1/search/status.
    func refreshStatus(client: APIClient?) async {
        guard let client else { return }
        if let s: SearchStatus = try? await client.send("GET", "search/status") {
            status = s.available ? s : nil
        }
    }

    private func fetch(offset: Int, generation g: Int, client: APIClient?) async {
        guard let client else {
            if g == generation {
                loading = false
                error = "Oturum bulunamadı; yeniden giriş yapın."
                if offset == 0 { results = []; resultsQuery = query }
            }
            return
        }
        let params = ["q": query, "limit": String(Self.pageSize), "offset": String(offset)]
        do {
            let r: SearchResponse = try await client.send("GET", "search", query: params)
            guard g == generation else { return }
            if offset == 0 {
                var seen = Set<String>()
                results = r.assets.filter { seen.insert($0.id).inserted }
            } else {
                var seen = Set(results.map(\.id))
                results.append(contentsOf: r.assets.filter { seen.insert($0.id).inserted })
            }
            resultsQuery = query
            mode = r.mode
            if offset == 0 { total = r.total } else if let t = r.total { total = t }
            if let s = r.status { status = s } else if offset == 0 && !r.isSmart { status = nil }
            // Boş sayfa geldiyse sonsuz döngüye girmemek için sayfalama biter.
            nextOffset = r.assets.isEmpty ? nil : r.nextOffset
            error = nil
            loading = false
        } catch {
            guard g == generation else { return }
            loading = false
            if Task.isCancelled {
                // Görünüm kapandı ya da arama iptal edildi: aynı sorgu yeniden yazılırsa tekrar aransın.
                if offset == 0 { query = "" }
                return
            }
            self.error = error.localizedDescription
            if offset == 0 {
                results = []
                resultsQuery = query
                nextOffset = nil
                total = nil
            }
        }
    }
}

/// Son aramalar: cihazda (UserDefaults) en yeni başta, en fazla 8 tane.
enum RecentSearches {
    private static let key = "searchRecents.v1"
    static let limit = 8
    private static let tr = Locale(identifier: "tr_TR")

    static func load() -> [String] {
        UserDefaults.standard.stringArray(forKey: key) ?? []
    }

    @discardableResult
    static func add(_ raw: String) -> [String] {
        let q = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !q.isEmpty else { return load() }
        let low = q.lowercased(with: tr)
        var list = load().filter { $0.lowercased(with: tr) != low }
        list.insert(q, at: 0)
        if list.count > limit { list = Array(list.prefix(limit)) }
        UserDefaults.standard.set(list, forKey: key)
        return list
    }

    @discardableResult
    static func remove(_ q: String) -> [String] {
        let list = load().filter { $0 != q }
        UserDefaults.standard.set(list, forKey: key)
        return list
    }

    static func clear() {
        UserDefaults.standard.removeObject(forKey: key)
    }
}

/// Akıllı arama ekranı: "kumsalda köpek", "doğum günü pastası" gibi doğal dilde sorgular.
/// Sonuçlar uygunluk sırasında 3 sütunlu ızgarada; dokununca görüntüleyici sonuç listesiyle açılır.
@MainActor
struct SearchView: View {
    /// Görüntüleyicide bir öğe değişince (favori vb.) kitaplığa da bildirilir.
    var onChange: (Asset) -> Void = { _ in }

    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @Environment(\.dismiss) private var dismiss
    @StateObject private var model = SearchModel()
    @State private var text = ""
    @State private var debounce: Task<Void, Never>?
    @State private var viewing: Asset?
    @State private var recents: [String] = RecentSearches.load()
    @FocusState private var fieldFocused: Bool
    @State private var didAutoFocus = false

    private struct Suggestion {
        let title: String
        let icon: String
    }

    private static let suggestions: [Suggestion] = [
        Suggestion(title: "Kumsal", icon: "beach.umbrella"),
        Suggestion(title: "Gün batımı", icon: "sunset"),
        Suggestion(title: "Doğum günü", icon: "birthday.cake"),
        Suggestion(title: "Kar", icon: "snowflake"),
        Suggestion(title: "Köpek", icon: "dog"),
        Suggestion(title: "Yemek", icon: "fork.knife"),
        Suggestion(title: "Dağ", icon: "mountain.2"),
        Suggestion(title: "Şehir", icon: "building.2"),
    ]

    private var trimmed: String { text.trimmingCharacters(in: .whitespacesAndNewlines) }

    private var columns: [GridItem] {
        Array(repeating: GridItem(.flexible(), spacing: theme.gridSpacing), count: 3)
    }

    var body: some View {
        VStack(spacing: 0) {
            topBar
            ScrollView {
                content
                    .padding(.bottom, 28)
            }
            .scrollDismissesKeyboard(.immediately)
        }
        .foregroundStyle(theme.ink)
        .background(theme.canvas.ignoresSafeArea())
        .task {
            // Görüntüleyici (tam ekran) kapanınca bu görev yeniden çalışır: klavye yalnızca ilk açılışta,
            // durum ise her dönüşte yenilenir.
            if !didAutoFocus {
                didAutoFocus = true
                // Tam ekran sunum açılırken odaklanma kaybolmasın: kısa bir gecikmeyle klavyeyi aç.
                try? await Task.sleep(for: .milliseconds(350))
                guard !Task.isCancelled else { return }
                if trimmed.isEmpty { fieldFocused = true }
            }
            await model.refreshStatus(client: session.client)
        }
        .onChange(of: text) { _, new in scheduleSearch(new) }
        .fullScreenCover(item: $viewing) { asset in
            AssetViewer(assets: model.results, startId: asset.id, onChange: { a in
                model.update(a)
                onChange(a)
            })
            .environmentObject(session)
            .environment(\.theme, theme)
        }
    }

    // MARK: - Üst çubuk

    private var topBar: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(alignment: .center, spacing: 10) {
                Text("Ara")
                    .font(theme.largeTitle)
                    .tracking(theme.name == .yerel ? -0.8 : -0.5)
                    .foregroundStyle(theme.ink)
                Spacer(minLength: 8)
                Button {
                    debounce?.cancel()
                    model.cancel()
                    dismiss()
                } label: {
                    Image(systemName: "xmark")
                        .font(.system(size: 15, weight: .semibold))
                        .foregroundStyle(theme.name == .yerel ? theme.accent : theme.ink)
                        .frame(width: closeSize, height: closeSize)
                        .background(theme.name == .galeri ? AnyShapeStyle(Color.clear) : AnyShapeStyle(Color.primary.opacity(0.08)), in: Circle())
                        .overlay(Circle().stroke(theme.hairline, lineWidth: theme.name == .yerel ? 0 : 1))
                }
                .buttonStyle(.plain)
                .accessibilityLabel("Kapat")
            }
            searchField
        }
        .padding(.horizontal, 16)
        .padding(.top, 8)
        .padding(.bottom, 12)
        .overlay(alignment: .bottom) {
            if theme.name == .galeri { Rectangle().fill(theme.hairline).frame(height: 1) }
        }
    }

    private var searchField: some View {
        HStack(spacing: 8) {
            Image(systemName: "magnifyingglass")
                .font(.system(size: 16, weight: .semibold))
                .foregroundStyle(theme.muted)
                .accessibilityHidden(true)
            TextField("Ara", text: $text, prompt: Text("Fotoğraflarınızda arayın: kumsal, doğum günü, kar…").foregroundColor(theme.muted))
                .font(.system(size: 17))
                .foregroundStyle(theme.ink)
                .tint(theme.name == .yerel ? theme.accent : theme.ink)
                .focused($fieldFocused)
                .submitLabel(.search)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
                .onSubmit { submit() }
            if model.loading && !trimmed.isEmpty {
                ProgressView()
                    .controlSize(.small)
                    .tint(theme.muted)
            }
            if !text.isEmpty {
                Button {
                    text = ""
                    fieldFocused = true
                } label: {
                    Image(systemName: "xmark.circle.fill")
                        .font(.system(size: 16))
                        .foregroundStyle(theme.muted)
                }
                .buttonStyle(.plain)
                .accessibilityLabel("Aramayı temizle")
            }
        }
        .padding(.horizontal, 12)
        .frame(height: 48)
        .background(fieldFill, in: RoundedRectangle(cornerRadius: fieldRadius, style: .continuous))
        .overlay(
            RoundedRectangle(cornerRadius: fieldRadius, style: .continuous)
                .stroke(theme.hairline, lineWidth: theme.name == .yerel ? 0 : 1)
        )
    }

    // MARK: - İçerik

    @ViewBuilder
    private var content: some View {
        if trimmed.isEmpty {
            idleContent
        } else if model.results.isEmpty {
            if let err = model.error, !model.loading, model.resultsQuery == trimmed {
                errorState(err)
            } else if model.loading || model.resultsQuery != trimmed {
                ProgressView()
                    .tint(theme.muted)
                    .frame(maxWidth: .infinity)
                    .padding(.top, 60)
            } else {
                emptyState
            }
        } else {
            resultsSection
        }
    }

    /// Sorgu yokken: dizinleme notu, öneri çipleri, son aramalar.
    private var idleContent: some View {
        VStack(alignment: .leading, spacing: 24) {
            if let s = model.status, s.isIndexing {
                statusNote(s)
            }
            VStack(alignment: .leading, spacing: 12) {
                sectionTitle("Öneriler")
                SearchChipFlow(spacing: 8) {
                    ForEach(Self.suggestions, id: \.title) { s in chip(s) }
                }
            }
            if !recents.isEmpty {
                VStack(alignment: .leading, spacing: 4) {
                    HStack {
                        sectionTitle("Son aramalar")
                        Spacer()
                        Button("Temizle") {
                            RecentSearches.clear()
                            withAnimation { recents = [] }
                        }
                        .font(.system(size: 14, weight: .medium))
                        .foregroundStyle(theme.name == .yerel ? theme.accent : theme.muted)
                        .buttonStyle(.plain)
                    }
                    .padding(.bottom, 4)
                    ForEach(recents, id: \.self) { term in recentRow(term) }
                }
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 16)
    }

    private var resultsSection: some View {
        let stale = model.resultsQuery != trimmed
        return VStack(alignment: .leading, spacing: 0) {
            VStack(alignment: .leading, spacing: 10) {
                Text(verbatim: "“\(model.resultsQuery)” için \(countText)")
                    .font(theme.monthFont)
                    .foregroundStyle(theme.ink)
                    .lineLimit(2)
                if let s = model.status, s.isIndexing {
                    statusNote(s)
                } else if model.mode == "text" {
                    HStack(alignment: .firstTextBaseline, spacing: 6) {
                        Image(systemName: "text.magnifyingglass")
                        Text("Akıllı arama şu an kullanılamıyor; dosya adı, açıklama ve kamera bilgisinde arandı.")
                    }
                    .font(.system(size: 13))
                    .foregroundStyle(theme.muted)
                }
            }
            .padding(.horizontal, 20)
            .padding(.top, 16)
            .padding(.bottom, 12)

            LazyVGrid(columns: columns, spacing: theme.gridSpacing) {
                ForEach(model.results) { asset in
                    Button { open(asset) } label: { SearchTile(asset: asset) }
                        .buttonStyle(.plain)
                        .onAppear {
                            if asset.id == model.results.last?.id { model.loadMore(client: session.client) }
                        }
                }
            }
            .opacity(stale ? 0.45 : 1)
            .animation(.easeInOut(duration: 0.2), value: stale)

            if model.loading && !stale {
                ProgressView().tint(theme.muted).frame(maxWidth: .infinity).padding()
            } else if let err = model.error, !stale {
                VStack(spacing: 10) {
                    Text(err).font(.system(size: 14)).multilineTextAlignment(.center).foregroundStyle(theme.muted)
                    retryButton { model.retryMore(client: session.client) }
                }
                .frame(maxWidth: .infinity)
                .padding(24)
            }
        }
    }

    private var countText: String {
        if let t = model.total, t >= model.results.count { return "\(t.formatted()) sonuç" }
        let n = model.results.count.formatted()
        return model.hasMore ? "\(n)+ sonuç" : "\(n) sonuç"
    }

    private var emptyState: some View {
        VStack(spacing: 14) {
            if let s = model.status, s.isIndexing {
                statusNote(s).padding(.bottom, 10)
            }
            Image(systemName: "photo.on.rectangle.angled")
                .font(.system(size: 36, weight: .light))
                .foregroundStyle(theme.muted)
            Text("Sonuç bulunamadı")
                .font(theme.title(22))
                .foregroundStyle(theme.ink)
            Text(verbatim: "“\(model.resultsQuery)” için eşleşen fotoğraf yok. Farklı bir kelime deneyin.")
                .font(.system(size: 15))
                .foregroundStyle(theme.muted)
                .multilineTextAlignment(.center)
            SearchChipFlow(spacing: 8) {
                ForEach(Self.suggestions.prefix(4), id: \.title) { s in chip(s) }
            }
            .padding(.top, 8)
        }
        .frame(maxWidth: .infinity)
        .padding(.horizontal, 28)
        .padding(.top, 40)
    }

    private func errorState(_ message: String) -> some View {
        VStack(spacing: 12) {
            Image(systemName: "exclamationmark.triangle")
                .font(.system(size: 32, weight: .light))
                .foregroundStyle(theme.muted)
            Text("Arama yapılamadı")
                .font(theme.title(22))
                .foregroundStyle(theme.ink)
            Text(message)
                .font(.system(size: 15))
                .foregroundStyle(theme.muted)
                .multilineTextAlignment(.center)
            retryButton { model.start(trimmed, client: session.client, force: true) }
                .padding(.top, 4)
        }
        .frame(maxWidth: .infinity)
        .padding(.horizontal, 28)
        .padding(.top, 50)
    }

    // MARK: - Parçalar

    private func sectionTitle(_ title: String) -> some View {
        Text(title)
            .font(theme.sectionFont)
            .textCase(theme.name == .galeri ? .uppercase : nil)
            .tracking(theme.name == .galeri ? 1.2 : 0)
            .foregroundStyle(theme.name == .galeri ? theme.muted : theme.ink)
    }

    private func chip(_ s: Suggestion) -> some View {
        Button { runNow(s.title) } label: {
            HStack(spacing: 6) {
                Image(systemName: s.icon)
                    .font(.system(size: 13, weight: .semibold))
                    .foregroundStyle(theme.name == .yerel ? theme.accent : theme.muted)
                Text(s.title)
                    .font(.system(size: 15, weight: .medium))
                    .foregroundStyle(theme.ink)
            }
            .padding(.horizontal, 14)
            .frame(height: 36)
            .background(chipFill, in: RoundedRectangle(cornerRadius: chipRadius, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: chipRadius, style: .continuous)
                    .stroke(theme.hairline, lineWidth: theme.name == .yerel ? 0 : 1)
            )
            .contentShape(Rectangle())
        }
        .buttonStyle(PressableStyle())
    }

    private func recentRow(_ term: String) -> some View {
        HStack(spacing: 12) {
            Button { runNow(term) } label: {
                HStack(spacing: 12) {
                    Image(systemName: "clock.arrow.circlepath")
                        .font(.system(size: 15))
                        .foregroundStyle(theme.muted)
                    Text(term)
                        .font(.system(size: 16))
                        .foregroundStyle(theme.ink)
                        .lineLimit(1)
                    Spacer(minLength: 0)
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            Button {
                withAnimation { recents = RecentSearches.remove(term) }
            } label: {
                Image(systemName: "xmark")
                    .font(.system(size: 12, weight: .semibold))
                    .foregroundStyle(theme.muted)
                    .frame(width: 28, height: 28)
                    .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel(Text(verbatim: "“\(term)” aramasını sil"))
        }
        .padding(.vertical, 8)
        .overlay(alignment: .bottom) { Rectangle().fill(theme.hairline).frame(height: 1) }
    }

    private func statusNote(_ s: SearchStatus) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 6) {
                Image(systemName: "sparkles")
                    .foregroundStyle(theme.name == .yerel ? theme.accent : theme.muted)
                Text(verbatim: "Akıllı arama hazırlanıyor: %\(s.percent)")
                    .foregroundStyle(theme.ink)
                Spacer(minLength: 8)
                Text(verbatim: "\(s.indexed.formatted()) / \(s.total.formatted())")
                    .foregroundStyle(theme.muted)
                    .monospacedDigit()
            }
            .font(.system(size: 13, weight: .medium))
            ProgressView(value: Double(min(s.indexed, s.total)), total: Double(max(s.total, 1)))
                .tint(theme.progressTint)
            Text("Fotoğraflar arka planda inceleniyor; bitene kadar sonuçlar eksik olabilir.")
                .font(.system(size: 12))
                .foregroundStyle(theme.muted)
        }
        .padding(12)
        .background(theme.groupSurface, in: RoundedRectangle(cornerRadius: noteRadius, style: .continuous))
        .overlay(
            RoundedRectangle(cornerRadius: noteRadius, style: .continuous)
                .stroke(theme.hairline, lineWidth: theme.name == .yerel ? 0 : 1)
        )
    }

    private func retryButton(_ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Label("Tekrar dene", systemImage: "arrow.clockwise")
                .font(.system(size: 14, weight: .semibold))
                .foregroundStyle(theme.name == .yerel ? theme.accent : theme.ink)
                .padding(.horizontal, 16).padding(.vertical, 9)
                .background(theme.surface, in: Capsule())
                .overlay(Capsule().stroke(theme.hairline, lineWidth: 1))
        }
        .buttonStyle(.plain)
    }

    // MARK: - Tema ölçüleri

    private var closeSize: CGFloat { theme.name == .galeri ? 30 : 36 }
    private var fieldRadius: CGFloat { theme.name == .galeri ? 2 : 12 }
    private var chipRadius: CGFloat { theme.name == .galeri ? 2 : 18 }
    private var noteRadius: CGFloat { theme.name == .galeri ? 2 : 14 }

    private var fieldFill: Color {
        switch theme.name {
        case .sinema: return theme.surface
        case .galeri: return theme.background
        case .yerel: return Color(uiColorLight: 0xEEEEF0, dark: 0x1C1C1E)
        }
    }

    private var chipFill: Color {
        switch theme.name {
        case .sinema: return theme.surfaceElevated.opacity(0.8)
        case .galeri: return Color.clear
        case .yerel: return Color(uiColorLight: 0xF2F2F7, dark: 0x1C1C1E)
        }
    }

    // MARK: - Eylemler

    /// Yazarken ~400 ms bekleyip arar; boş sorgu sonuçları temizler.
    private func scheduleSearch(_ value: String) {
        let q = value.trimmingCharacters(in: .whitespacesAndNewlines)
        debounce?.cancel()
        debounce = nil
        if q.isEmpty {
            model.clear()
            return
        }
        // Bu sorgu zaten aranıyor/gösteriliyor (ör. çipe dokunuldu).
        if q == model.query && model.error == nil { return }
        let client = session.client
        debounce = Task { @MainActor in
            try? await Task.sleep(for: .milliseconds(400))
            guard !Task.isCancelled else { return }
            model.start(q, client: client)
        }
    }

    /// Klavyedeki "Ara": beklemeden arar ve son aramalara ekler.
    private func submit() {
        let q = trimmed
        guard !q.isEmpty else { return }
        debounce?.cancel()
        debounce = nil
        recents = RecentSearches.add(q)
        model.start(q, client: session.client)
    }

    /// Öneri çipi ya da son arama: alanı doldurur ve hemen arar.
    private func runNow(_ term: String) {
        debounce?.cancel()
        debounce = nil
        fieldFocused = false
        recents = RecentSearches.add(term)
        model.start(term, client: session.client)
        text = term
    }

    private func open(_ asset: Asset) {
        fieldFocused = false
        if !model.resultsQuery.isEmpty { recents = RecentSearches.add(model.resultsQuery) }
        viewing = asset
    }
}

/// Arama sonucu hücresi (kitaplık ızgarasıyla aynı görünüm).
private struct SearchTile: View {
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
            .overlay(alignment: .bottomLeading) {
                if let m = asset.textMatch { textBadge(m) }
            }
            .contentShape(Rectangle())
            .accessibilityLabel(Text(accessibilityText))
            .accessibilityAddTraits(.isButton)
    }

    private var accessibilityText: String {
        let kind = asset.isVideo ? "Video" : "Fotoğraf"
        guard let m = asset.textMatch else { return kind }
        return "\(kind), “\(m.term)” yazısı bulundu: \(m.snippet)"
    }

    /// Fotoğraftaki yazıda bulunan sözcük: hücrenin sol altında küçük rozet (yalnız vurgulu sözcük).
    private func textBadge(_ m: AssetTextMatch) -> some View {
        let hit = m.parts.first(where: { $0.m })?.t ?? m.term
        return HStack(spacing: 3) {
            Image(systemName: "text.viewfinder").font(.system(size: 9, weight: .bold))
            Text(hit)
                .font(.system(size: 10, weight: .bold))
                .lineLimit(1)
                .padding(.horizontal, 3)
                .background(theme.name == .galeri ? theme.accent.opacity(0.18) : Color.yellow.opacity(0.9),
                            in: RoundedRectangle(cornerRadius: theme.name == .galeri ? 1 : 3, style: .continuous))
                .foregroundStyle(theme.name == .galeri ? theme.accent : Color.black)
        }
        .foregroundStyle(theme.name == .galeri ? theme.ink : Color.white)
        .padding(.horizontal, 5)
        .padding(.vertical, 3)
        .background(theme.name == .galeri ? AnyShapeStyle(Color.white.opacity(0.94)) : AnyShapeStyle(Material.ultraThin),
                    in: RoundedRectangle(cornerRadius: theme.name == .galeri ? 2 : 8, style: .continuous))
        .environment(\.colorScheme, theme.name == .galeri ? .light : .dark)
        .padding(4)
        .accessibilityHidden(true)
    }
}

/// Çipleri satır satır dizen basit akış düzeni (sığmayan bir sonraki satıra geçer).
private struct SearchChipFlow: Layout {
    var spacing: CGFloat = 8

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let maxWidth = proposal.width.flatMap { $0.isFinite ? $0 : nil } ?? .greatestFiniteMagnitude
        var x: CGFloat = 0
        var y: CGFloat = 0
        var rowHeight: CGFloat = 0
        var widest: CGFloat = 0
        for view in subviews {
            let size = view.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > maxWidth {
                y += rowHeight + spacing
                x = 0
                rowHeight = 0
            }
            widest = max(widest, x + size.width)
            x += size.width + spacing
            rowHeight = max(rowHeight, size.height)
        }
        let width = proposal.width.flatMap { $0.isFinite ? $0 : nil } ?? widest
        return CGSize(width: width, height: y + rowHeight)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x = bounds.minX
        var y = bounds.minY
        var rowHeight: CGFloat = 0
        for view in subviews {
            let size = view.sizeThatFits(.unspecified)
            if x > bounds.minX && x + size.width > bounds.maxX {
                y += rowHeight + spacing
                x = bounds.minX
                rowHeight = 0
            }
            view.place(at: CGPoint(x: x, y: y), anchor: .topLeading, proposal: ProposedViewSize(size))
            x += size.width + spacing
            rowHeight = max(rowHeight, size.height)
        }
    }
}
