import SwiftUI

// Temizlik: benzer/yinelenen kareler, bulanık ve karanlık fotoğraflar, ekran görüntüleri, kısa videolar
// (GET /v1/cleanup, POST /v1/cleanup/resolve|dismiss|reset). Seçilenler Çöp kutusuna taşınır (30 gün); hiçbir şey
// kalıcı silinmez. Ayarlar → Temizlik'ten açılır.

// MARK: - Durum

@MainActor
final class CleanupModel: ObservableObject {
    @Published var data: CleanupResponse?
    @Published var error: String?
    @Published var busy = false
    /// Grup başına çöpe gidecekler (varsayılan: en iyisi dışındakiler).
    @Published var trash: [String: Set<String>] = [:]
    /// Grup başına kullanıcının seçtiği en iyi kare (varsayılan: sunucunun önerisi).
    @Published var keep: [String: String] = [:]

    func load(_ client: APIClient?) async {
        guard let client else { return }
        do {
            let r: CleanupResponse = try await client.send("GET", "cleanup")
            for g in r.groups {
                if keep[g.id] == nil { keep[g.id] = g.keepId }
                if trash[g.id] == nil { trash[g.id] = g.suggestedTrash }
            }
            data = r
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    func keepId(_ g: CleanupGroup) -> String { keep[g.id] ?? g.keepId }
    func trashSet(_ g: CleanupGroup) -> Set<String> { trash[g.id] ?? g.suggestedTrash }

    func toggle(_ id: String, in g: CleanupGroup) {
        guard id != keepId(g) else { return }
        var s = trashSet(g)
        if s.contains(id) { s.remove(id) } else { s.insert(id) }
        trash[g.id] = s
    }

    func makeBest(_ id: String, in g: CleanupGroup) {
        let old = keepId(g)
        var s = trashSet(g)
        s.remove(id)
        s.insert(old)
        keep[g.id] = id
        trash[g.id] = s
    }

    /// Seçili grupların tamamına öneriyi uygular. Dönüş: çöpe taşınan öğe sayısı.
    func resolve(_ client: APIClient?, groups: [CleanupGroup]) async -> Int {
        let parts = groups.map { CleanupResolveRequest.part(for: $0, trash: trashSet($0)) }
        return await send(client, parts: parts)
    }

    func trashItems(_ client: APIClient?, ids: [String]) async -> Int {
        await send(client, parts: [CleanupResolveRequest.Part(keep: [], trash: ids)])
    }

    private func send(_ client: APIClient?, parts: [CleanupResolveRequest.Part]) async -> Int {
        guard let client else { return 0 }
        busy = true
        defer { busy = false }
        var n = 0
        do {
            for req in CleanupResolveRequest.batches(parts) {
                let r: CleanupResolveResponse = try await client.send("POST", "cleanup/resolve", body: req)
                n += r.trashed
            }
        } catch {
            self.error = error.localizedDescription
        }
        for p in parts {
            for id in p.trash {
                for key in trash.keys { trash[key]?.remove(id) }
            }
        }
        await load(client)
        return n
    }

    func dismiss(_ client: APIClient?, kind: String, ids: [String]) async {
        guard let client else { return }
        do {
            let _: CleanupDismissResponse = try await client.send("POST", "cleanup/dismiss", body: CleanupDismissRequest(kind: kind, ids: ids))
        } catch {
            self.error = error.localizedDescription
        }
        await load(client)
    }

    func reset(_ client: APIClient?) async -> Int {
        guard let client else { return 0 }
        do {
            let r: CleanupResetResponse = try await client.send("POST", "cleanup/reset", body: EmptyBody())
            await load(client)
            return r.restored
        } catch {
            self.error = error.localizedDescription
            return 0
        }
    }
}

enum CleanupFormat {
    static func bytes(_ n: Int64) -> String { ByteCountFormatter.string(fromByteCount: n, countStyle: .file) }

    static func date(_ iso: String) -> String {
        let parts = iso.prefix(10).split(separator: "-").compactMap { Int($0) }
        guard parts.count == 3 else { return String(iso.prefix(10)) }
        var c = DateComponents()
        c.year = parts[0]; c.month = parts[1]; c.day = parts[2]
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = TimeZone(identifier: "UTC") ?? .current
        guard let d = cal.date(from: c) else { return String(iso.prefix(10)) }
        let f = DateFormatter()
        f.locale = Locale(identifier: "tr_TR")
        f.timeZone = cal.timeZone
        f.dateFormat = "d MMMM yyyy"
        return f.string(from: d)
    }

    static func reason(_ r: String) -> String { r == "duplicate" ? "Neredeyse aynı" : "Benzer kareler" }

    static func why(_ r: String) -> String {
        switch r {
        case "favorite": return "Favori"
        case "resolution": return "En yüksek çözünürlük"
        case "sharpest": return "En net"
        case "location": return "Konum bilgisi var"
        default: return "Orijinal"
        }
    }

    static func title(_ c: CleanupCategory) -> String {
        switch c {
        case .blurry: return "Bulanık fotoğraflar"
        case .dark: return "Çok karanlık ya da parlak"
        case .screenshots: return "Ekran görüntüleri"
        case .videos: return "Kısa videolar"
        }
    }

    static func icon(_ c: CleanupCategory) -> String {
        switch c {
        case .blurry: return "camera.metering.unknown"
        case .dark: return "sun.max"
        case .screenshots: return "iphone"
        case .videos: return "video"
        }
    }

    static func empty(_ c: CleanupCategory) -> String {
        switch c {
        case .blurry: return "Bulanık fotoğraf yok."
        case .dark: return "Çok karanlık ya da aşırı parlak kare yok."
        case .screenshots: return "Ekran görüntüsü yok."
        case .videos: return "Kısa video yok."
        }
    }

    static func tag(_ item: CleanupItem, _ c: CleanupCategory) -> String? {
        switch c {
        case .blurry: return "Bulanık"
        case .dark: return item.why == "bright" ? "Aşırı parlak" : "Çok karanlık"
        case .videos:
            guard let ms = item.asset.durationMs else { return nil }
            return String(format: "%.1f sn", Double(ms) / 1000).replacingOccurrences(of: ".", with: ",")
        case .screenshots: return nil
        }
    }
}

// MARK: - Ana ekran

struct CleanupView: View {
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @StateObject private var model = CleanupModel()
    @State private var confirmAll = false
    @State private var toast: String?

    var body: some View {
        List {
            if let r = model.data {
                summary(r)
                if r.analysis.isRunning {
                    Section {
                        VStack(alignment: .leading, spacing: 8) {
                            Text("Fotoğraflar inceleniyor").font(.subheadline.weight(.semibold))
                            ProgressView(value: Double(r.analysis.analyzed), total: Double(max(1, r.analysis.total)))
                                .tint(theme.accent)
                            Text("\(r.analysis.analyzed) / \(r.analysis.total) fotoğraf incelendi. Sonuçlar inceleme sürdükçe artar.")
                                .font(.footnote).foregroundStyle(theme.muted)
                        }
                        .padding(.vertical, 4)
                    }
                    .listRowBackground(theme.groupSurface)
                }
                if !r.ml.available {
                    Section {
                        Label("Benzer fotoğrafları bulmak için sunucudaki akıllı arama hizmeti (ownmemories-ml) gerekli. Bulanık fotoğraflar ve ekran görüntüleri yine de listelenir.", systemImage: "info.circle")
                            .font(.footnote).foregroundStyle(theme.muted)
                    }
                    .listRowBackground(theme.groupSurface)
                }
                groupsSection(r)
                Section {
                    ForEach(CleanupCategory.allCases) { c in
                        NavigationLink {
                            CleanupListView(model: model, category: c)
                        } label: {
                            categoryRow(c, r)
                        }
                        .disabled(c.items(in: r).isEmpty)
                    }
                } header: {
                    Text("Diğer öneriler")
                }
                .listRowBackground(theme.groupSurface)
                Section {
                    Button("Gizlenen önerileri geri getir") {
                        Task {
                            let n = await model.reset(session.client)
                            toast = "\(n) gizlenen öneri geri geldi"
                        }
                    }
                } footer: {
                    Text("Hiçbir şey kalıcı silinmez: seçtikleriniz önce Çöp kutusuna gider ve 30 gün orada bekler.")
                }
                .listRowBackground(theme.groupSurface)
            } else if let e = model.error {
                Section {
                    Text(e).foregroundStyle(theme.muted)
                    Button("Yeniden dene") { Task { await model.load(session.client) } }
                }
                .listRowBackground(theme.groupSurface)
            } else {
                Section {
                    HStack(spacing: 12) {
                        ProgressView()
                        Text("Kütüphaneniz inceleniyor…").foregroundStyle(theme.muted)
                    }
                }
                .listRowBackground(theme.groupSurface)
            }
        }
        .themedList(theme)
        .navigationTitle("Temizlik")
        .refreshable { await model.load(session.client) }
        .task { if model.data == nil { await model.load(session.client) } }
        .confirmationDialog("Öneriler uygulansın mı?", isPresented: $confirmAll, titleVisibility: .visible) {
            Button("Çöp kutusuna taşı", role: .destructive) {
                Task {
                    let groups = model.data?.groups ?? []
                    let bytes = groups.reduce(Int64(0)) { $0 + $1.bytes(trashing: model.trashSet($1)) }
                    let n = await model.resolve(session.client, groups: groups)
                    toast = "\(n) öğe Çöp kutusuna taşındı · \(CleanupFormat.bytes(bytes)) boşalacak"
                }
            }
        } message: {
            Text(applyAllMessage)
        }
        .overlay(alignment: .bottom) {
            if let toast {
                Text(toast)
                    .font(.subheadline.weight(.medium))
                    .padding(.horizontal, 16).padding(.vertical, 10)
                    .background(.ultraThinMaterial, in: Capsule())
                    .padding(.bottom, 24)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .task {
                        try? await Task.sleep(for: .seconds(3))
                        withAnimation { self.toast = nil }
                    }
            }
        }
        .animation(.default, value: toast)
    }

    private var applyAllMessage: String {
        guard let r = model.data else { return "" }
        let ids = r.groups.reduce(0) { $0 + model.trashSet($1).count }
        let bytes = r.groups.reduce(Int64(0)) { $0 + $1.bytes(trashing: model.trashSet($1)) }
        return "\(r.groups.count) gruptaki \(ids) fotoğraf Çöp kutusuna taşınacak (\(CleanupFormat.bytes(bytes))). Her grubun en iyisi yerinde kalır; 30 gün içinde geri yükleyebilirsiniz."
    }

    @ViewBuilder
    private func summary(_ r: CleanupResponse) -> some View {
        Section {
            VStack(alignment: .leading, spacing: 10) {
                if r.isClean {
                    Label("Her şey derli toplu", systemImage: "checkmark.circle.fill")
                        .font(.system(.title2, design: theme.titleDesign).weight(theme.titleWeight))
                        .foregroundStyle(theme.ink)
                    Text("Şu an önerilecek bir şey yok.").font(.subheadline).foregroundStyle(theme.muted)
                } else {
                    HStack(alignment: .firstTextBaseline, spacing: 8) {
                        Text(CleanupFormat.bytes(r.stats.potentialBytes))
                            .font(.system(size: 38, weight: theme.titleWeight, design: theme.titleDesign))
                            .foregroundStyle(theme.ink)
                            .minimumScaleFactor(0.6)
                            .lineLimit(1)
                        Text("kazanılabilir").font(.subheadline).foregroundStyle(theme.muted)
                    }
                    let extra = r.groups.reduce(0) { $0 + $1.assets.count - 1 }
                    Text("\(r.stats.groups) benzer grupta \(extra) fazla kare")
                        .font(.subheadline).foregroundStyle(theme.muted)
                    if !r.groups.isEmpty {
                        Button {
                            confirmAll = true
                        } label: {
                            Label("Önerileri uygula", systemImage: "sparkles")
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.borderedProminent)
                        .tint(theme.accent)
                        .controlSize(.large)
                        .disabled(model.busy)
                        .padding(.top, 4)
                    }
                }
            }
            .padding(.vertical, 6)
            .accessibilityElement(children: .contain)
        }
        .listRowBackground(theme.groupSurface)
    }

    @ViewBuilder
    private func groupsSection(_ r: CleanupResponse) -> some View {
        Section {
            if r.groups.isEmpty {
                Text(r.ml.available ? "Benzer ya da yinelenen fotoğraf bulunamadı." : "Benzer fotoğraf taraması kullanılamıyor.")
                    .foregroundStyle(theme.muted)
            }
            ForEach(r.groups) { g in
                NavigationLink {
                    CleanupGroupView(model: model, groupId: g.id)
                } label: {
                    GroupRow(group: g, keepId: model.keepId(g), trash: model.trashSet(g))
                }
            }
        } header: {
            Text("Benzer gruplar")
        } footer: {
            if !r.groups.isEmpty {
                Text("Bir gruba dokunup hangi karenin kalacağını seçin. “En iyisi”: en yüksek çözünürlük, en net, favori ya da konumlu kare.")
            }
        }
        .listRowBackground(theme.groupSurface)
    }

    private func categoryRow(_ c: CleanupCategory, _ r: CleanupResponse) -> some View {
        let n = c.count(in: r.stats)
        return HStack(spacing: 12) {
            Image(systemName: CleanupFormat.icon(c))
                .font(.system(size: 15, weight: .semibold))
                .foregroundStyle(n > 0 ? theme.accent : theme.muted)
                .frame(width: 30, height: 30)
            VStack(alignment: .leading, spacing: 2) {
                Text(CleanupFormat.title(c)).foregroundStyle(theme.ink)
                Text(n > 0 ? "\(n) öğe · \(CleanupFormat.bytes(c.bytes(in: r.stats)))" : "Temiz")
                    .font(.footnote).foregroundStyle(theme.muted)
            }
        }
        .accessibilityElement(children: .combine)
    }
}

/// Grup satırı: küçük resim şeridi (en iyisi yeşil çerçeveli), neden ve kazanılacak alan.
private struct GroupRow: View {
    let group: CleanupGroup
    let keepId: String
    let trash: Set<String>
    @Environment(\.theme) private var theme

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 6) {
                Text(CleanupFormat.reason(group.reason))
                    .font(.caption.weight(.semibold))
                    .padding(.horizontal, 8).padding(.vertical, 3)
                    .background(theme.accent.opacity(0.14), in: Capsule())
                    .foregroundStyle(theme.accent)
                Text("\(group.assets.count) fotoğraf · \(CleanupFormat.date(group.takenAt))")
                    .font(.footnote).foregroundStyle(theme.muted)
            }
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    ForEach(ordered) { item in
                        RemoteImage(assetId: item.id)
                            .frame(width: 58, height: 58)
                            .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 8, style: .continuous)
                                .stroke(item.id == keepId ? theme.success : .clear, lineWidth: 2.5))
                            .opacity(trash.contains(item.id) ? 0.55 : 1)
                    }
                }
            }
            .allowsHitTesting(false)
            let bytes = group.bytes(trashing: trash)
            if bytes > 0 {
                Text("\(CleanupFormat.bytes(bytes)) kazanılır").font(.footnote.weight(.semibold)).foregroundStyle(theme.ink)
            }
        }
        .padding(.vertical, 4)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("\(CleanupFormat.reason(group.reason)), \(group.assets.count) fotoğraf, \(trash.count) silinecek")
    }

    private var ordered: [CleanupItem] {
        group.assets.filter { $0.id == keepId } + group.assets.filter { $0.id != keepId }
    }
}

// MARK: - Grup ayrıntısı

struct CleanupGroupView: View {
    @ObservedObject var model: CleanupModel
    let groupId: String
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @Environment(\.dismiss) private var dismiss
    @State private var preview: CleanupItem?

    private var group: CleanupGroup? { model.data?.groups.first { $0.id == groupId } }

    var body: some View {
        Group {
            if let g = group {
                content(g)
            } else {
                Text("Bu grup artık yok.").foregroundStyle(theme.muted)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .background(theme.background.ignoresSafeArea())
        .navigationTitle(group.map { CleanupFormat.reason($0.reason) } ?? "Grup")
        .navigationBarTitleDisplayMode(.inline)
        .sheet(item: $preview) { item in
            NavigationStack {
                RemoteImage(assetId: item.id, variant: "preview", contentMode: .fit)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .background(Color.black)
                    .navigationTitle(item.asset.filename)
                    .navigationBarTitleDisplayMode(.inline)
                    .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Kapat") { preview = nil } } }
            }
        }
    }

    @ViewBuilder
    private func content(_ g: CleanupGroup) -> some View {
        let keepId = model.keepId(g)
        let trash = model.trashSet(g)
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                Text("Kalacak kareyi “En iyisi yap” ile seçin; diğerlerine dokunarak tutun ya da silin.")
                    .font(.footnote).foregroundStyle(theme.muted)
                    .padding(.horizontal, 16)
                LazyVGrid(columns: [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)], spacing: 14) {
                    ForEach(g.assets.filter { $0.id == keepId } + g.assets.filter { $0.id != keepId }) { item in
                        ShotCard(item: item, isBest: item.id == keepId, isTrash: trash.contains(item.id),
                                 reasons: item.id == g.keepId ? g.keepReasons : [],
                                 onToggle: { withAnimation(.snappy) { model.toggle(item.id, in: g) } },
                                 onBest: { withAnimation(.snappy) { model.makeBest(item.id, in: g) } },
                                 onPreview: { preview = item })
                    }
                }
                .padding(.horizontal, 16)
            }
            .padding(.vertical, 12)
            .padding(.bottom, 90)
        }
        .safeAreaInset(edge: .bottom) {
            VStack(spacing: 8) {
                Button {
                    Task {
                        _ = await model.resolve(session.client, groups: [g])
                        dismiss()
                    }
                } label: {
                    Label(trash.isEmpty ? "Silinecek kare yok" : "\(trash.count) kareyi Çöp kutusuna taşı · \(CleanupFormat.bytes(g.bytes(trashing: trash)))",
                          systemImage: "trash")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .tint(trash.isEmpty ? theme.muted : .red)
                .controlSize(.large)
                .disabled(trash.isEmpty || model.busy)
            }
            .padding(.horizontal, 16).padding(.vertical, 10)
            .background(.bar)
        }
        .toolbar {
            ToolbarItem(placement: .primaryAction) {
                Button("Bunlar farklı") {
                    Task {
                        await model.dismiss(session.client, kind: "group", ids: g.assets.map(\.id))
                        dismiss()
                    }
                }
            }
        }
    }
}

private struct ShotCard: View {
    let item: CleanupItem
    let isBest: Bool
    let isTrash: Bool
    let reasons: [String]
    let onToggle: () -> Void
    let onBest: () -> Void
    let onPreview: () -> Void
    @Environment(\.theme) private var theme

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Button(action: isBest ? onPreview : onToggle) {
                theme.placeholder
                    .aspectRatio(1, contentMode: .fit)
                    .overlay(RemoteImage(assetId: item.id))
                    .clipShape(RoundedRectangle(cornerRadius: theme.tileRadius + 6, style: .continuous))
                    .saturation(isTrash ? 0.3 : 1)
                    .brightness(isTrash ? -0.15 : 0)
                    .overlay(RoundedRectangle(cornerRadius: theme.tileRadius + 6, style: .continuous)
                        .stroke(isBest ? theme.success : (isTrash ? Color.red.opacity(0.7) : .clear), lineWidth: isBest ? 3 : 2))
                    .overlay(alignment: .topLeading) {
                        if isBest {
                            Label("En iyisi", systemImage: "checkmark")
                                .font(.caption.weight(.bold))
                                .padding(.horizontal, 8).padding(.vertical, 4)
                                .background(theme.success, in: Capsule())
                                .foregroundStyle(.white)
                                .padding(8)
                        }
                    }
                    .overlay(alignment: .topTrailing) {
                        if !isBest {
                            Image(systemName: isTrash ? "trash.circle.fill" : "checkmark.circle.fill")
                                .font(.system(size: 24))
                                .symbolRenderingMode(.palette)
                                .foregroundStyle(.white, isTrash ? Color.red : Color.black.opacity(0.45))
                                .padding(8)
                        }
                    }
            }
            .buttonStyle(.plain)
            .accessibilityLabel("\(item.asset.filename), \(isBest ? "En iyisi" : (isTrash ? "Silinecek" : "Tutulacak"))")
            .accessibilityHint(isBest ? "Büyük göster" : "Tutmak ya da silmek için dokunun")
            .contextMenu {
                Button { onPreview() } label: { Label("Büyük göster", systemImage: "arrow.up.left.and.arrow.down.right") }
                if !isBest { Button { onBest() } label: { Label("En iyisi yap", systemImage: "star") } }
            }
            Text(facts).font(.caption).foregroundStyle(theme.muted).lineLimit(2)
            if isBest, !reasons.isEmpty {
                Text(reasons.map(CleanupFormat.why).joined(separator: " · "))
                    .font(.caption.weight(.semibold)).foregroundStyle(theme.success)
            } else if !isBest {
                Button("En iyisi yap", action: onBest).font(.caption.weight(.semibold)).tint(theme.accent)
            }
        }
    }

    private var facts: String {
        var parts: [String] = []
        if let w = item.asset.width, let h = item.asset.height { parts.append("\(w)×\(h)") }
        parts.append(CleanupFormat.bytes(item.asset.size))
        switch item.sharpnessLevel {
        case .blurry: parts.append("Bulanık")
        case .soft: parts.append("Biraz yumuşak")
        case .sharp: parts.append("Net")
        case nil: break
        }
        return parts.joined(separator: " · ")
    }
}

// MARK: - Liste (bulanık, karanlık, ekran görüntüleri, kısa videolar)

struct CleanupListView: View {
    @ObservedObject var model: CleanupModel
    let category: CleanupCategory
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @State private var selected: Set<String> = []
    @State private var seeded = false
    @State private var confirm = false

    private var items: [CleanupItem] { model.data.map { category.items(in: $0) } ?? [] }
    private var selectedBytes: Int64 { items.filter { selected.contains($0.id) }.reduce(0) { $0 + $1.asset.size } }

    var body: some View {
        ScrollView {
            if items.isEmpty {
                Text(CleanupFormat.empty(category)).foregroundStyle(theme.muted).padding(.top, 60)
            }
            LazyVGrid(columns: [GridItem(.adaptive(minimum: 104), spacing: 3)], spacing: 3) {
                ForEach(items) { item in
                    let on = selected.contains(item.id)
                    Button {
                        if on { selected.remove(item.id) } else { selected.insert(item.id) }
                    } label: {
                        theme.placeholder
                            .aspectRatio(1, contentMode: .fit)
                            .overlay(RemoteImage(assetId: item.id))
                            .clipShape(RoundedRectangle(cornerRadius: theme.tileRadius, style: .continuous))
                            .overlay(alignment: .topTrailing) {
                                Image(systemName: on ? "checkmark.circle.fill" : "circle")
                                    .font(.system(size: 22))
                                    .symbolRenderingMode(.palette)
                                    .foregroundStyle(.white, on ? theme.accent : Color.black.opacity(0.3))
                                    .padding(6)
                            }
                            .overlay(alignment: .bottomLeading) {
                                if let tag = CleanupFormat.tag(item, category) {
                                    Text(tag).font(.caption2.weight(.semibold))
                                        .padding(.horizontal, 6).padding(.vertical, 2)
                                        .background(.black.opacity(0.55), in: Capsule())
                                        .foregroundStyle(.white)
                                        .padding(6)
                                }
                            }
                            .opacity(on ? 1 : 0.8)
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(item.asset.filename)
                    .accessibilityAddTraits(on ? .isSelected : [])
                    .contextMenu {
                        Button {
                            Task { await model.dismiss(session.client, kind: category.dismissKind, ids: [item.id]) }
                        } label: { Label("Bu iyi (bir daha önerme)", systemImage: "eye.slash") }
                    }
                }
            }
            .padding(.horizontal, 3)
            .padding(.bottom, 90)
        }
        .background(theme.background.ignoresSafeArea())
        .navigationTitle(CleanupFormat.title(category))
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .primaryAction) {
                Button(selected.count == items.count && !items.isEmpty ? "Seçimi kaldır" : "Tümünü seç") {
                    selected = selected.count == items.count ? [] : Set(items.map(\.id))
                }
                .disabled(items.isEmpty)
            }
        }
        .safeAreaInset(edge: .bottom) {
            if !items.isEmpty {
                Button {
                    confirm = true
                } label: {
                    Label("\(selected.count) öğeyi Çöp kutusuna taşı · \(CleanupFormat.bytes(selectedBytes))", systemImage: "trash")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .tint(.red)
                .controlSize(.large)
                .disabled(selected.isEmpty || model.busy)
                .padding(.horizontal, 16).padding(.vertical, 10)
                .background(.bar)
            }
        }
        .confirmationDialog("Seçilenler Çöp kutusuna taşınsın mı?", isPresented: $confirm, titleVisibility: .visible) {
            Button("Çöp kutusuna taşı", role: .destructive) {
                Task {
                    _ = await model.trashItems(session.client, ids: Array(selected))
                    selected = []
                }
            }
        } message: {
            Text("\(selected.count) öğe (\(CleanupFormat.bytes(selectedBytes))) Çöp kutusuna gider; 30 gün içinde geri yükleyebilirsiniz.")
        }
        .onAppear {
            if !seeded {
                seeded = true
                if category.preselects { selected = Set(items.map(\.id)) }
            }
        }
        .onChange(of: items.map(\.id)) { _, ids in
            selected.formIntersection(ids)
        }
    }
}
