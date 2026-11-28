import SwiftUI
import UIKit

// Kişiler: elle etiketlenen ve yüz tanımayla bulunan kişiler (/v1/people, /v1/faces, /v1/assets/{id}/people).

// MARK: - API yardımcıları

/// Kişi uçlarına ortak çağrılar. Tümü geçerli kullanıcıya göre; başkasının kimliği 404.
enum PeopleAPI {
    /// Adlandırma sonucu: tamam ya da aynı adda başka bir kişi var (istemci birleştirmeyi önerir).
    enum NameOutcome {
        case done(Person)
        case conflict(otherId: Int, otherName: String)
    }

    private struct PersonRef: Encodable { let person_id: Int }
    private struct NameRef: Encodable { let name: String }
    private struct Into: Encodable { let into: Int }
    private struct Hidden: Encodable { let hidden: Bool }

    /// Seçiciden gelen seçim → {"person_id"} ya da {"name"} gövdesi (etiket ekleme, yüz atama, toplu etiket).
    static func body(for choice: PersonPickerSheet.Choice) -> Encodable {
        switch choice {
        case .existing(let p): return PersonRef(person_id: p.id)
        case .new(let n): return NameRef(name: n)
        }
    }

    /// PATCH /v1/people/{id} {"name"}: 409 name_exists yanıtında çakışan kişiyi döndürür.
    /// (APIClient.send hata gövdesindeki kişi kimliğini taşımadığı için istek burada elle yapılır.)
    static func rename(_ client: APIClient, _ id: Int, name: String) async throws -> NameOutcome {
        var req = client.authorized(client.url("people/\(id)"), method: "PATCH")
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.httpBody = try JSONEncoder().encode(NameRef(name: name))
        let data: Data
        let resp: URLResponse
        do {
            (data, resp) = try await URLSession.shared.data(for: req)
        } catch {
            throw APIError.unreachable(error.localizedDescription)
        }
        if let http = resp as? HTTPURLResponse, http.statusCode == 409,
           let c = try? JSONDecoder().decode(PersonNameConflict.self, from: data),
           c.error.code == "name_exists", let other = c.otherId {
            return .conflict(otherId: other, otherName: c.error.person?.name ?? name)
        }
        let env: PersonEnvelope = try APIClient.decode(data: data, response: resp)
        return .done(env.person)
    }

    /// POST /v1/people/{id}/merge {"into"}: kaynak silinir, hedef döner.
    static func merge(_ client: APIClient, _ id: Int, into target: Int) async throws -> Person {
        let env: PersonEnvelope = try await client.send("POST", "people/\(id)/merge", body: Into(into: target))
        return env.person
    }

    static func setHidden(_ client: APIClient, _ id: Int, hidden: Bool) async throws -> Person {
        let env: PersonEnvelope = try await client.send("PATCH", "people/\(id)", body: Hidden(hidden: hidden))
        return env.person
    }

    static func delete(_ client: APIClient, _ id: Int) async throws {
        let _: OkResponse = try await client.send("DELETE", "people/\(id)")
    }

    /// Bir kişiye (çoğunlukla adsız kümeye) seçiciden gelen ad: var olan kişi → onunla birleştir; yeni ad → adlandır.
    static func apply(_ choice: PersonPickerSheet.Choice, to personId: Int, client: APIClient) async throws -> NameOutcome {
        switch choice {
        case .existing(let target):
            if target.id == personId { return .done(target) }
            return .done(try await merge(client, personId, into: target.id))
        case .new(let name):
            return try await rename(client, personId, name: name)
        }
    }
}

/// Aynı adda kişi bulununca gösterilen birleştirme önerisi: kaynak kişi hedefe katılır.
struct MergeOffer: Identifiable {
    let sourceId: Int
    let targetId: Int
    let targetName: String
    var id: Int { sourceId }
}

enum PeopleFormat {
    private static let tr = Locale(identifier: "tr_TR")

    /// "1.240" (Türkçe binlik ayırıcı, cihaz dilinden bağımsız).
    static func number(_ n: Int) -> String { n.formatted(.number.locale(tr)) }

    static func photos(_ n: Int) -> String { "\(number(n)) fotoğraf" }

    /// Türkçe büyük/küçük harf ve aksan duyarsız eşleştirme ("ayse" → "Ayşe").
    static func matches(_ name: String, _ query: String) -> Bool {
        name.range(of: query, options: [.caseInsensitive, .diacriticInsensitive], locale: tr) != nil
    }

    static func same(_ a: String, _ b: String) -> Bool {
        // Sunucu (utf8mb4_unicode_ci) adları büyük/küçük harf ve aksan duyarsız karşılaştırır.
        a.compare(b, options: [.caseInsensitive, .diacriticInsensitive], range: nil, locale: tr) == .orderedSame
    }

    static func initial(_ name: String?) -> String? {
        guard let ch = name?.trimmingCharacters(in: .whitespacesAndNewlines).first else { return nil }
        return String(ch).uppercased(with: tr)
    }
}

extension View {
    /// "Bu adda bir kişi var — birleştirilsin mi?" sorusu.
    func mergeOfferAlert(_ offer: Binding<MergeOffer?>, onMerge: @escaping (MergeOffer) -> Void) -> some View {
        alert("Bu adda bir kişi var",
              isPresented: Binding(get: { offer.wrappedValue != nil }, set: { if !$0 { offer.wrappedValue = nil } }),
              presenting: offer.wrappedValue) { o in
            Button("Birleştir") { onMerge(o) }
            Button("Vazgeç", role: .cancel) {}
        } message: { o in
            Text(verbatim: "“\(o.targetName)” adlı bir kişi zaten var. İkisi tek kişide birleştirilsin mi? Fotoğrafları ve yüzleri “\(o.targetName)” altında toplanır.")
        }
    }

    /// Bir işlem başarısız olunca kısa uyarı.
    func peopleErrorAlert(_ message: Binding<String?>) -> some View {
        alert("İşlem yapılamadı",
              isPresented: Binding(get: { message.wrappedValue != nil }, set: { if !$0 { message.wrappedValue = nil } }),
              presenting: message.wrappedValue) { _ in
            Button("Tamam", role: .cancel) {}
        } message: { m in
            Text(m)
        }
    }
}

// MARK: - Yüz avatarı

/// Yuvarlak yüz kırpması (GET /v1/faces/{id}/thumb, Bearer ile ImageLoader üzerinden).
/// Yüz yoksa (elle etiketlenmiş kişi) kapak fotoğrafının küçük resmi, o da yoksa adın baş harfi gösterilir.
struct FaceAvatar: View {
    var faceId: Int?
    var assetId: String? = nil
    var name: String? = nil
    var size: CGFloat
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @State private var image: UIImage?
    @State private var faceFailed = false
    @State private var loadedFaceId: Int?

    var body: some View {
        ZStack {
            Circle().fill(theme.placeholder)
            if let image {
                Image(uiImage: image).resizable().scaledToFill()
            } else if faceId == nil || faceFailed, let assetId {
                RemoteImage(assetId: assetId, variant: "thumb")
            } else if faceId == nil || faceFailed {
                if let ch = PeopleFormat.initial(name) {
                    Text(ch)
                        .font(.system(size: size * 0.42, weight: .semibold, design: theme.isSerif ? .serif : .default))
                        .foregroundStyle(theme.muted)
                } else {
                    Image(systemName: "person.fill")
                        .font(.system(size: size * 0.42))
                        .foregroundStyle(theme.muted.opacity(0.7))
                }
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
        .overlay(Circle().stroke(theme.hairline, lineWidth: 1))
        .accessibilityHidden(true)
        .task(id: faceId) {
            // Görünüm yeniden belirince (tam ekran kapanınca) aynı yüz için titreme olmasın.
            if loadedFaceId == faceId, image != nil || faceFailed { return }
            image = nil
            faceFailed = false
            loadedFaceId = faceId
            guard let faceId, let client = session.client else { return }
            let url = client.url("faces/\(faceId)/thumb")
            switch await ImageLoader.shared.load(url, client: client) {
            case .image(let img): image = img
            case .pending, .failed: faceFailed = true
            }
        }
    }
}

/// Çipleri satır satır dizen akış düzeni (sığmayan bir sonraki satıra geçer).
struct PeopleChipFlow: Layout {
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

// MARK: - Kişi seçici

/// Ad yaz / var olan kişiyi seç (otomatik tamamlama: GET /v1/people). "Kişi ekle", "Bu kim?", "Adlandır", "Birleştir".
struct PersonPickerSheet: View {
    enum Choice: Equatable {
        case existing(Person)
        case new(String)
    }

    let title: String
    let allowsNew: Bool
    let excluding: Set<Int>
    /// Var olan kişi seçilince sorulacak onay metni (nil: sorulmaz).
    let confirmExisting: ((Person) -> String)?
    let onPick: (Choice) -> Void

    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @Environment(\.dismiss) private var dismiss
    @State private var text: String
    @State private var people: [Person] = []
    @State private var loaded = false
    @State private var confirming: Person?
    @FocusState private var focused: Bool

    init(title: String, allowsNew: Bool = true, excluding: Set<Int> = [], initialText: String = "",
         confirmExisting: ((Person) -> String)? = nil, onPick: @escaping (Choice) -> Void) {
        self.title = title
        self.allowsNew = allowsNew
        self.excluding = excluding
        self.confirmExisting = confirmExisting
        self.onPick = onPick
        _text = State(initialValue: initialText)
    }

    private var trimmed: String {
        text.trimmingCharacters(in: .whitespacesAndNewlines).replacingOccurrences(of: "\\s+", with: " ", options: .regularExpression)
    }

    private var candidates: [Person] {
        let named = people.filter { $0.isNamed && !excluding.contains($0.id) }
        let q = trimmed
        guard !q.isEmpty else { return named }
        return named.filter { PeopleFormat.matches($0.displayName, q) }
    }

    private var exact: Person? {
        let q = trimmed
        guard !q.isEmpty else { return nil }
        return people.first { $0.isNamed && !excluding.contains($0.id) && PeopleFormat.same($0.displayName, q) }
    }

    var body: some View {
        NavigationStack {
            List {
                Section {
                    TextField("Ad", text: $text, prompt: Text(allowsNew ? "Ad yazın" : "Kişi arayın").foregroundColor(theme.muted))
                        .focused($focused)
                        .submitLabel(.done)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.words)
                        .onSubmit(submit)
                    if allowsNew, !trimmed.isEmpty, exact == nil {
                        Button { pick(.new(trimmed)) } label: {
                            HStack(spacing: 12) {
                                Image(systemName: "person.badge.plus")
                                    .font(.system(size: 16, weight: .semibold))
                                    .foregroundStyle(accent)
                                    .frame(width: 34, height: 34)
                                Text(verbatim: "“\(trimmed)” adlı yeni kişi")
                                    .foregroundStyle(theme.ink)
                                Spacer(minLength: 0)
                            }
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                }
                if !candidates.isEmpty {
                    Section(trimmed.isEmpty ? "Kişiler" : "Eşleşen kişiler") {
                        ForEach(candidates) { p in
                            Button { choose(p) } label: {
                                HStack(spacing: 12) {
                                    FaceAvatar(faceId: p.coverFaceId, assetId: p.coverAssetId, name: p.name, size: 34)
                                    VStack(alignment: .leading, spacing: 1) {
                                        Text(p.displayName).foregroundStyle(theme.ink)
                                        Text(PeopleFormat.photos(p.count)).font(.system(size: 12)).foregroundStyle(theme.muted)
                                    }
                                    Spacer(minLength: 0)
                                    if p.hidden {
                                        Image(systemName: "eye.slash").font(.system(size: 13)).foregroundStyle(theme.muted)
                                    }
                                }
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                        }
                    }
                } else if !loaded {
                    Section { ProgressView().tint(theme.muted).frame(maxWidth: .infinity) }
                } else if !allowsNew {
                    Section { Text("Eşleşen kişi yok.").foregroundStyle(theme.muted) }
                }
            }
            .themedList(theme)
            .tint(accent)
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Vazgeç") { dismiss() }
                }
            }
            .alert("Birleştirilsin mi?",
                   isPresented: Binding(get: { confirming != nil }, set: { if !$0 { confirming = nil } }),
                   presenting: confirming) { p in
                Button("Birleştir") { pick(.existing(p)) }
                Button("Vazgeç", role: .cancel) {}
            } message: { p in
                Text(confirmExisting?(p) ?? "")
            }
        }
        .preferredColorScheme(theme.colorScheme)
        .task {
            guard !loaded else { return }
            if let client = session.client,
               let r: PeopleList = try? await client.send("GET", "people", query: ["include_hidden": "1"]) {
                people = r.people
            }
            loaded = true
        }
        .onAppear { focused = true }
    }

    private var accent: Color { theme.name == .yerel ? theme.accent : theme.ink }

    private func submit() {
        if let e = exact {
            choose(e)
        } else if allowsNew, !trimmed.isEmpty {
            pick(.new(trimmed))
        } else if candidates.count == 1, let only = candidates.first {
            choose(only)
        }
    }

    private func choose(_ p: Person) {
        if confirmExisting != nil {
            focused = false
            confirming = p
        } else {
            pick(.existing(p))
        }
    }

    private func pick(_ c: Choice) {
        onPick(c)
        dismiss()
    }
}

// MARK: - Kişiler listesi

@MainActor
final class PeopleModel: ObservableObject {
    @Published private(set) var people: [Person] = []
    @Published private(set) var status: FaceScanStatus?
    @Published private(set) var loading = false
    @Published private(set) var loaded = false
    @Published private(set) var error: String?
    @Published var includeHidden = false
    private var generation = 0

    var named: [Person] { people.filter(\.isNamed) }
    var unnamed: [Person] { people.filter { !$0.isNamed } }

    func load(client: APIClient?) async {
        guard let client else { return }
        generation += 1
        let g = generation
        loading = true
        do {
            let r: PeopleList = try await client.send("GET", "people", query: ["include_hidden": includeHidden ? "1" : "0"])
            guard g == generation else { return }
            people = r.people
            error = nil
        } catch {
            guard g == generation else { return }
            self.error = error.localizedDescription
        }
        loaded = true
        loading = false
        let s = try? await client.send("GET", "people/status", as: FaceScanStatus.self)
        if g == generation { status = s }
    }

    /// Bir kişi değişti (ad, gizli): listeyi yeniden çekmeden önce yerinde günceller.
    func replace(_ p: Person) {
        if let i = people.firstIndex(where: { $0.id == p.id }) { people[i] = p }
    }

    func remove(_ id: Int) {
        people.removeAll { $0.id == id }
    }
}

/// Kişiler ekranı: adlı kişiler, "Adlandırılmamış" yüz kümeleri ("Bu kim?"), gizlileri göster, tarama durumu.
struct PeopleView: View {
    /// Görüntüleyicide bir öğe değişince (favori vb.) kitaplığa da bildirilir.
    var onChange: (Asset) -> Void = { _ in }

    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @Environment(\.dismiss) private var dismiss
    @StateObject private var model = PeopleModel()
    @State private var path: [Person] = []
    @State private var naming: Person?
    @State private var pendingName: (person: Person, choice: PersonPickerSheet.Choice)?
    @State private var mergeOffer: MergeOffer?
    @State private var actionError: String?

    private var columns: [GridItem] {
        [GridItem(.adaptive(minimum: theme.name == .sinema ? 92 : 100), spacing: 14, alignment: .top)]
    }

    var body: some View {
        NavigationStack(path: $path) {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    header
                    content
                }
                .padding(.bottom, 28)
            }
            .foregroundStyle(theme.ink)
            .background(theme.canvas.ignoresSafeArea())
            .navigationTitle("Kişiler")
            .toolbar(.hidden, for: .navigationBar)
            .refreshable { await model.load(client: session.client) }
            .task { await model.load(client: session.client) }
            .navigationDestination(for: Person.self) { p in
                PersonView(person: p, model: model, onChange: onChange)
            }
        }
        .tint(theme.name == .yerel ? theme.accent : theme.ink)
        .preferredColorScheme(theme.colorScheme)
        .sheet(item: $naming, onDismiss: applyPendingName) { p in
            PersonPickerSheet(title: "Bu kim?", excluding: [p.id]) { choice in
                pendingName = (p, choice)
            }
            .environmentObject(session)
            .environment(\.theme, theme)
        }
        .mergeOfferAlert($mergeOffer) { o in Task { await merge(o) } }
        .peopleErrorAlert($actionError)
    }

    // MARK: Üst çubuk

    private var header: some View {
        HStack(alignment: .center, spacing: 10) {
            VStack(alignment: .leading, spacing: 2) {
                Text("Kişiler")
                    .font(theme.largeTitle)
                    .tracking(theme.name == .yerel ? -0.8 : -0.5)
                    .foregroundStyle(theme.ink)
                if model.loaded, !model.people.isEmpty {
                    Text(verbatim: "\(PeopleFormat.number(model.named.count)) kişi")
                        .font(.system(size: 13)).foregroundStyle(theme.muted)
                }
            }
            Spacer(minLength: 8)
            headerButton(model.includeHidden ? "eye" : "eye.slash",
                         label: model.includeHidden ? "Gizlileri sakla" : "Gizlileri göster",
                         active: model.includeHidden) {
                model.includeHidden.toggle()
                Task { await model.load(client: session.client) }
            }
            headerButton("xmark", label: "Kapat", active: false) { dismiss() }
        }
        .padding(.horizontal, 20)
        .padding(.top, 8)
        .padding(.bottom, theme.name == .galeri ? 16 : 12)
        .overlay(alignment: .bottom) {
            if theme.name == .galeri { Rectangle().fill(theme.hairline).frame(height: 1) }
        }
    }

    private var buttonSize: CGFloat { theme.name == .galeri ? 30 : 36 }

    private func headerButton(_ icon: String, label: String, active: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon)
                .font(.system(size: 15, weight: .semibold))
                .foregroundStyle(active ? (theme.name == .sinema ? theme.background : Color.white)
                                 : (theme.name == .yerel ? theme.accent : theme.ink))
                .frame(width: buttonSize, height: buttonSize)
                .background(buttonFill(active), in: Circle())
                .overlay(Circle().stroke(theme.hairline, lineWidth: theme.name == .yerel ? 0 : 1))
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }

    private func buttonFill(_ active: Bool) -> AnyShapeStyle {
        if active { return AnyShapeStyle(theme.name == .yerel ? theme.accent : theme.ink) }
        return theme.name == .galeri ? AnyShapeStyle(Color.clear) : AnyShapeStyle(Color.primary.opacity(0.08))
    }

    // MARK: İçerik

    @ViewBuilder
    private var content: some View {
        VStack(alignment: .leading, spacing: 26) {
            if let s = model.status, s.isScanning {
                statusNote(s)
            }
            if !model.loaded {
                ProgressView().tint(theme.muted).frame(maxWidth: .infinity).padding(.top, 60)
            } else if let err = model.error, model.people.isEmpty {
                VStack(spacing: 12) {
                    Text(err).multilineTextAlignment(.center).foregroundStyle(theme.muted)
                    Button("Tekrar dene") { Task { await model.load(client: session.client) } }
                        .foregroundStyle(theme.name == .yerel ? theme.accent : theme.ink)
                }
                .frame(maxWidth: .infinity)
                .padding(.top, 40)
            } else if model.loaded && model.people.isEmpty {
                emptyState
            } else {
                if !model.named.isEmpty {
                    LazyVGrid(columns: columns, spacing: 20) {
                        ForEach(model.named) { p in
                            NavigationLink(value: p) { PersonCell(person: p) }
                                .buttonStyle(PressableStyle())
                        }
                    }
                }
                if !model.unnamed.isEmpty {
                    VStack(alignment: .leading, spacing: 14) {
                        VStack(alignment: .leading, spacing: 3) {
                            sectionTitle("Adlandırılmamış")
                            Text("Benzer yüzler bir araya getirildi. Adını verdiğiniz kişiler yeni fotoğraflarda kendiliğinden tanınır.")
                                .font(.system(size: 13))
                                .foregroundStyle(theme.muted)
                        }
                        LazyVGrid(columns: columns, spacing: 20) {
                            ForEach(model.unnamed) { p in unnamedCell(p) }
                        }
                    }
                }
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 16)
    }

    private var emptyState: some View {
        VStack(spacing: 12) {
            Image(systemName: "person.2")
                .font(.system(size: 34, weight: .light))
                .foregroundStyle(theme.muted)
            Text("Henüz kişi yok")
                .font(theme.title(22))
                .foregroundStyle(theme.ink)
            Text(emptyText)
                .font(.system(size: 15))
                .foregroundStyle(theme.muted)
                .multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
        .padding(.horizontal, 8)
        .padding(.top, 50)
    }

    private var emptyText: String {
        if let s = model.status, s.isScanning {
            return "Yüzler taranıyor; bulunan kişiler burada belirecek. Bu arada bir fotoğrafın ayrıntılarından kişi ekleyebilirsiniz."
        }
        return "Bir fotoğrafın ayrıntılarında “Kişi ekle” ile etiketleyin; yüz tanıma açıksa tanınan yüzler de burada toplanır."
    }

    private func sectionTitle(_ title: String) -> some View {
        Text(title)
            .font(theme.sectionFont)
            .textCase(theme.name == .galeri ? .uppercase : nil)
            .tracking(theme.name == .galeri ? 1.2 : 0)
            .foregroundStyle(theme.name == .galeri ? theme.muted : theme.ink)
    }

    private func unnamedCell(_ p: Person) -> some View {
        VStack(spacing: 6) {
            NavigationLink(value: p) {
                VStack(spacing: 6) {
                    FaceAvatar(faceId: p.coverFaceId, assetId: p.coverAssetId, name: nil, size: PersonCell.avatarSize(theme))
                        .opacity(p.hidden ? 0.45 : 1)
                    Text(PeopleFormat.photos(p.count))
                        .font(.system(size: 12))
                        .foregroundStyle(theme.muted)
                        .lineLimit(1)
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(PressableStyle())
            .accessibilityLabel(Text(verbatim: "Adsız kişi, \(PeopleFormat.photos(p.count))"))
            Button { naming = p } label: {
                Text("Bu kim?")
                    .font(.system(size: 13, weight: .semibold))
                    .foregroundStyle(theme.name == .yerel ? theme.accent : theme.ink)
                    .padding(.horizontal, 10)
                    .frame(height: 26)
                    .background(theme.name == .galeri ? AnyShapeStyle(Color.clear) : AnyShapeStyle(Color.primary.opacity(0.08)), in: Capsule())
                    .overlay(Capsule().stroke(theme.hairline, lineWidth: theme.name == .yerel ? 0 : 1))
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Bu kim? Adlandır")
        }
        .frame(maxWidth: .infinity)
    }

    private func statusNote(_ s: FaceScanStatus) -> some View {
        let radius: CGFloat = theme.name == .galeri ? 2 : 14
        return VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 6) {
                Image(systemName: "face.dashed")
                    .foregroundStyle(theme.name == .yerel ? theme.accent : theme.muted)
                Text(verbatim: "Yüzler taranıyor: \(PeopleFormat.number(s.scanned)) / \(PeopleFormat.number(s.total))")
                    .foregroundStyle(theme.ink)
                    .monospacedDigit()
                Spacer(minLength: 0)
            }
            .font(.system(size: 13, weight: .medium))
            ProgressView(value: Double(min(s.scanned, s.total)), total: Double(max(s.total, 1)))
                .tint(theme.progressTint)
            Text("Fotoğraflar arka planda inceleniyor; kişiler tarama ilerledikçe belirir.")
                .font(.system(size: 12))
                .foregroundStyle(theme.muted)
        }
        .padding(12)
        .background(theme.groupSurface, in: RoundedRectangle(cornerRadius: radius, style: .continuous))
        .overlay(
            RoundedRectangle(cornerRadius: radius, style: .continuous)
                .stroke(theme.hairline, lineWidth: theme.name == .yerel ? 0 : 1)
        )
    }

    // MARK: Eylemler

    /// "Bu kim?" seçici kapandıktan sonra (uyarı sayfa kapanırken kaybolmasın diye) uygulanır.
    private func applyPendingName() {
        guard let pending = pendingName else { return }
        pendingName = nil
        Task { await name(pending.person, pending.choice) }
    }

    private func name(_ p: Person, _ choice: PersonPickerSheet.Choice) async {
        guard let client = session.client else { return }
        do {
            switch try await PeopleAPI.apply(choice, to: p.id, client: client) {
            case .done:
                break
            case let .conflict(otherId, otherName):
                mergeOffer = MergeOffer(sourceId: p.id, targetId: otherId, targetName: otherName)
                return
            }
        } catch {
            actionError = error.localizedDescription
        }
        await model.load(client: client)
    }

    private func merge(_ o: MergeOffer) async {
        guard let client = session.client else { return }
        do {
            _ = try await PeopleAPI.merge(client, o.sourceId, into: o.targetId)
        } catch {
            actionError = error.localizedDescription
        }
        await model.load(client: client)
    }
}

/// Kişiler ızgarası hücresi: yuvarlak yüz, ad, fotoğraf sayısı.
private struct PersonCell: View {
    let person: Person
    @Environment(\.theme) private var theme

    static func avatarSize(_ theme: AppTheme) -> CGFloat { theme.name == .sinema ? 84 : 92 }

    var body: some View {
        VStack(spacing: 7) {
            FaceAvatar(faceId: person.coverFaceId, assetId: person.coverAssetId, name: person.name, size: Self.avatarSize(theme))
                .opacity(person.hidden ? 0.45 : 1)
                .overlay(alignment: .bottomTrailing) {
                    if person.hidden {
                        Image(systemName: "eye.slash.fill")
                            .font(.system(size: 11, weight: .semibold))
                            .foregroundStyle(theme.ink)
                            .frame(width: 24, height: 24)
                            .background(theme.surfaceElevated, in: Circle())
                            .overlay(Circle().stroke(theme.hairline, lineWidth: 1))
                    }
                }
            VStack(spacing: 1) {
                Text(person.displayName)
                    .font(theme.isSerif ? Font.system(size: 15, design: .serif) : Font.system(size: 15, weight: .semibold))
                    .foregroundStyle(theme.ink)
                    .lineLimit(1)
                Text(PeopleFormat.photos(person.count))
                    .font(.system(size: 12))
                    .foregroundStyle(theme.muted)
                    .lineLimit(1)
            }
        }
        .frame(maxWidth: .infinity)
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
        .accessibilityLabel(Text(verbatim: "\(person.displayName), \(PeopleFormat.photos(person.count))\(person.hidden ? ", gizli" : "")"))
    }
}

// MARK: - Kişi sayfası

@MainActor
final class PersonPhotosModel: ObservableObject {
    @Published private(set) var assets: [Asset] = []
    @Published private(set) var loading = false
    @Published private(set) var error: String?
    private var next: AssetCursor?
    private(set) var done = false
    private(set) var personId: Int?
    private var generation = 0

    func reload(personId: Int, client: APIClient?) async {
        self.personId = personId
        generation += 1
        next = nil
        done = false
        assets = []
        error = nil
        loading = false
        await loadMore(client: client)
    }

    func loadMore(client: APIClient?) async {
        guard let client, let personId, !loading, !done else { return }
        let g = generation
        loading = true
        var q = ["limit": "200", "person": String(personId)]
        if let next {
            q["before"] = next.before
            if let id = next.beforeId { q["before_id"] = id }
        }
        do {
            let page: AssetPage = try await client.send("GET", "assets", query: q)
            guard g == generation else { return }
            assets.append(contentsOf: page.assets)
            next = page.next
            done = page.next == nil
            error = nil
        } catch {
            guard g == generation else { return }
            self.error = error.localizedDescription
        }
        loading = false
    }

    /// Yerinde yenileme (görüntüleyiciden dönünce): yüklü kadar öğeyi baştan çeker, listeyi boşaltmadan değiştirir.
    func refresh(client: APIClient?) async {
        guard let client, let personId, !loading else { return }
        let g = generation
        let want = max(assets.count, 1)
        loading = true
        var fresh: [Asset] = []
        var cursor: AssetCursor?
        do {
            repeat {
                var q = ["limit": "500", "person": String(personId)]
                if let cursor {
                    q["before"] = cursor.before
                    if let id = cursor.beforeId { q["before_id"] = id }
                }
                let page: AssetPage = try await client.send("GET", "assets", query: q)
                fresh.append(contentsOf: page.assets)
                cursor = page.next
            } while cursor != nil && fresh.count < want && g == generation
            guard g == generation else { return }
            assets = fresh
            next = cursor
            done = cursor == nil
            error = nil
        } catch {
            // Sessiz: aşağı çekince tekrar denenir.
        }
        if g == generation { loading = false }
    }

    func update(_ asset: Asset) {
        if let i = assets.firstIndex(where: { $0.id == asset.id }) { assets[i] = asset }
    }
}

/// Bir kişinin sayfası: yüz, ad (adlandır/yeniden adlandır), sayı, Birleştir, Gizle/Göster, Sil; fotoğraf ızgarası.
struct PersonView: View {
    @ObservedObject var model: PeopleModel
    var onChange: (Asset) -> Void

    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @Environment(\.dismiss) private var dismiss
    @State private var person: Person
    @StateObject private var photos = PersonPhotosModel()
    @State private var viewing: Asset?
    @State private var naming = false
    @State private var merging = false
    @State private var pendingChoice: PersonPickerSheet.Choice?
    @State private var mergeOffer: MergeOffer?
    @State private var confirmDelete = false
    @State private var busy = false
    @State private var actionError: String?

    init(person: Person, model: PeopleModel, onChange: @escaping (Asset) -> Void) {
        _person = State(initialValue: person)
        self.model = model
        self.onChange = onChange
    }

    private var columns: [GridItem] {
        Array(repeating: GridItem(.flexible(), spacing: theme.gridSpacing), count: theme.gridColumns)
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 0) {
                header
                    .padding(.horizontal, 20)
                    .padding(.top, 12)
                    .padding(.bottom, 22)
                LazyVGrid(columns: columns, spacing: theme.gridSpacing) {
                    ForEach(photos.assets) { asset in
                        Button { viewing = asset } label: { PersonPhotoTile(asset: asset) }
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
                } else if photos.assets.isEmpty && photos.done {
                    Text("Bu kişinin fotoğrafı yok.")
                        .foregroundStyle(theme.muted)
                        .frame(maxWidth: .infinity)
                        .padding(40)
                }
            }
            .padding(.bottom, 28)
        }
        .foregroundStyle(theme.ink)
        .background(theme.canvas.ignoresSafeArea())
        .navigationTitle(person.displayName)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar(.visible, for: .navigationBar)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Menu {
                    Button { naming = true } label: { Label(person.isNamed ? "Yeniden adlandır" : "Adlandır", systemImage: "pencil") }
                    Button { merging = true } label: { Label("Başka kişiyle birleştir", systemImage: "person.2") }
                    Button { Task { await toggleHidden() } } label: {
                        Label(person.hidden ? "Kişiyi göster" : "Kişiyi gizle", systemImage: person.hidden ? "eye" : "eye.slash")
                    }
                    Divider()
                    Button(role: .destructive) { confirmDelete = true } label: { Label("Kişiyi sil", systemImage: "trash") }
                } label: {
                    Image(systemName: "ellipsis.circle")
                }
                .accessibilityLabel("Kişi seçenekleri")
            }
        }
        .refreshable { await photos.refresh(client: session.client) }
        .task(id: person.id) {
            // Görüntüleyiciden dönünce görev yeniden çalışır: ızgara (ve kaydırma konumu) korunur.
            if photos.personId != person.id { await photos.reload(personId: person.id, client: session.client) }
        }
        .sheet(isPresented: $naming, onDismiss: applyPendingChoice) {
            PersonPickerSheet(title: person.isNamed ? "Yeniden adlandır" : "Bu kim?", excluding: [person.id],
                              initialText: person.name ?? "",
                              confirmExisting: { other in
                                  "“\(other.displayName)” ile birleştirilsin mi? Bu kişinin fotoğrafları ve yüzleri “\(other.displayName)” altında toplanır."
                              }) { choice in
                pendingChoice = choice
            }
            .environmentObject(session)
            .environment(\.theme, theme)
        }
        .sheet(isPresented: $merging, onDismiss: applyPendingChoice) {
            PersonPickerSheet(title: "Kiminle birleştirilsin?", allowsNew: false, excluding: [person.id],
                              confirmExisting: { other in
                                  "“\(person.displayName)”, “\(other.displayName)” ile birleştirilecek. Fotoğrafları ve yüzleri “\(other.displayName)” altında toplanır."
                              }) { choice in
                pendingChoice = choice
            }
            .environmentObject(session)
            .environment(\.theme, theme)
        }
        .fullScreenCover(item: $viewing, onDismiss: {
            // Görüntüleyicide kişi fotoğraftan çıkarılmış olabilir.
            Task {
                await photos.refresh(client: session.client)
                await reloadPerson()
            }
        }) { asset in
            AssetViewer(assets: photos.assets, startId: asset.id, onChange: { a in
                photos.update(a)
                onChange(a)
            })
            .environmentObject(session)
            .environment(\.theme, theme)
        }
        .confirmationDialog("Kişi silinsin mi?", isPresented: $confirmDelete, titleVisibility: .visible) {
            Button("Kişiyi sil", role: .destructive) { Task { await deletePerson() } }
            Button("Vazgeç", role: .cancel) {}
        } message: {
            Text("Fotoğraflar silinmez; yalnızca bu kişi ve etiketleri kaldırılır. Yüzler yeniden bu kişiye atanmaz.")
        }
        .mergeOfferAlert($mergeOffer) { o in Task { await merge(into: o.targetId) } }
        .peopleErrorAlert($actionError)
    }

    private var header: some View {
        VStack(spacing: 10) {
            FaceAvatar(faceId: person.coverFaceId, assetId: person.coverAssetId, name: person.name, size: 112)
                .opacity(person.hidden ? 0.6 : 1)
            Button { naming = true } label: {
                HStack(spacing: 6) {
                    Text(person.isNamed ? person.displayName : "Ad ekle")
                        .font(theme.title(28))
                        .foregroundStyle(person.isNamed ? theme.ink : theme.muted)
                        .lineLimit(2)
                        .multilineTextAlignment(.center)
                    Image(systemName: "pencil")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundStyle(theme.muted)
                }
            }
            .buttonStyle(.plain)
            .accessibilityLabel(Text(verbatim: person.isNamed ? "\(person.displayName), yeniden adlandır" : "Ad ekle"))
            Text(verbatim: PeopleFormat.photos(person.count) + (person.hidden ? " · Gizli" : ""))
                .font(.system(size: 13))
                .foregroundStyle(theme.muted)
            HStack(spacing: 10) {
                actionButton("person.2", "Birleştir") { merging = true }
                actionButton(person.hidden ? "eye" : "eye.slash", person.hidden ? "Göster" : "Gizle") {
                    Task { await toggleHidden() }
                }
            }
            .padding(.top, 4)
            .disabled(busy)
        }
        .frame(maxWidth: .infinity)
    }

    private func actionButton(_ icon: String, _ title: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Label(title, systemImage: icon)
                .font(.system(size: 14, weight: .semibold))
                .foregroundStyle(theme.name == .yerel ? theme.accent : theme.ink)
                .padding(.horizontal, 14)
                .frame(height: 34)
                .background(theme.name == .galeri ? AnyShapeStyle(Color.clear) : AnyShapeStyle(Color.primary.opacity(0.08)),
                            in: RoundedRectangle(cornerRadius: theme.name == .galeri ? 2 : 17, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: theme.name == .galeri ? 2 : 17, style: .continuous)
                    .stroke(theme.hairline, lineWidth: theme.name == .yerel ? 0 : 1))
        }
        .buttonStyle(.plain)
    }

    // MARK: Eylemler

    private func applyPendingChoice() {
        guard let choice = pendingChoice else { return }
        pendingChoice = nil
        Task { await apply(choice) }
    }

    private func apply(_ choice: PersonPickerSheet.Choice) async {
        guard let client = session.client else { return }
        busy = true
        defer { busy = false }
        do {
            switch try await PeopleAPI.apply(choice, to: person.id, client: client) {
            case .done(let p):
                person = p
            case let .conflict(otherId, otherName):
                mergeOffer = MergeOffer(sourceId: person.id, targetId: otherId, targetName: otherName)
                return
            }
        } catch {
            actionError = error.localizedDescription
        }
        await model.load(client: client)
    }

    private func merge(into target: Int) async {
        guard let client = session.client else { return }
        busy = true
        defer { busy = false }
        do {
            person = try await PeopleAPI.merge(client, person.id, into: target)
        } catch {
            actionError = error.localizedDescription
        }
        await model.load(client: client)
    }

    private func toggleHidden() async {
        guard let client = session.client else { return }
        busy = true
        defer { busy = false }
        do {
            let p = try await PeopleAPI.setHidden(client, person.id, hidden: !person.hidden)
            person = p
            model.replace(p)
        } catch {
            actionError = error.localizedDescription
        }
        await model.load(client: client)
    }

    private func deletePerson() async {
        guard let client = session.client else { return }
        busy = true
        defer { busy = false }
        do {
            try await PeopleAPI.delete(client, person.id)
            model.remove(person.id)
            dismiss()
            await model.load(client: client)
        } catch {
            actionError = error.localizedDescription
        }
    }

    /// Sayı/kapak güncellensin; kişi artık yoksa (son fotoğraftan da çıkarıldı, adsız küme silindi) geri dönülür.
    private func reloadPerson() async {
        guard let client = session.client else { return }
        do {
            let env: PersonEnvelope = try await client.send("GET", "people/\(person.id)")
            person = env.person
        } catch let APIError.server(status, _, _, _) where status == 404 {
            dismiss()
        } catch {
            // Sessiz.
        }
        await model.load(client: client)
    }
}

/// Kişi sayfası ızgara hücresi (kitaplık ızgarasıyla aynı görünüm).
private struct PersonPhotoTile: View {
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
            .accessibilityLabel(Text(asset.isVideo ? "Video" : "Fotoğraf"))
            .accessibilityAddTraits(.isButton)
    }
}
