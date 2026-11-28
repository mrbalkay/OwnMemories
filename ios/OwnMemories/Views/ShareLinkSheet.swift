import CoreImage.CIFilterBuiltins
import SwiftUI
import UIKit

// Bağlantıyla paylaşım: tailnet dışındaki kişiler için herkese açık bağlantı (POST /v1/shares) ve
// iOS paylaşım sayfası (ShareLink → UIActivityViewController). Yönetim: ShareLinksView (Ayarlar).
// Kullanım: .sheet { ShareLinkSheet(target: .album(id: a.id, title: a.title)) }  veya  .selection(ids: [...])

enum ShareLinkTarget: Equatable {
    case album(id: String, title: String)
    case selection(ids: [String])
}

struct ShareLinkInfo: Decodable, Identifiable, Equatable {
    let id: Int
    let kind: String
    let albumId: String?
    let title: String
    let count: Int
    let coverAssetId: String?
    let expiresAt: String?
    let hasPassword: Bool
    let allowDownload: Bool
    let showLocation: Bool
    let views: Int
    let status: String

    enum CodingKeys: String, CodingKey {
        case id, kind, title, count, views, status
        case albumId = "album_id"
        case coverAssetId = "cover_asset_id"
        case expiresAt = "expires_at"
        case hasPassword = "has_password"
        case allowDownload = "allow_download"
        case showLocation = "show_location"
    }
}

struct ShareLinkCreated: Decodable {
    let link: ShareLinkInfo
    let token: String
    let url: String
    let funnel: Bool?
}

private struct ShareLinkList: Decodable {
    let links: [ShareLinkInfo]
    let funnel: Bool?
}

private struct ShareLinkEnvelope: Decodable {
    let link: ShareLinkInfo
}

private struct ShareLinkCreateBody: Encodable {
    let kind: String
    let album_id: String?
    let ids: [String]?
    let title: String
    let expires: String
    let password: String?
    let allow_download: Bool
    let show_location: Bool
}

enum ShareLinkQR {
    /// Yerel QR kodu (CoreImage; dış kütüphane yok).
    static func image(_ text: String) -> UIImage? {
        let f = CIFilter.qrCodeGenerator()
        f.message = Data(text.utf8)
        f.correctionLevel = "M"
        guard let out = f.outputImage?.transformed(by: CGAffineTransform(scaleX: 10, y: 10)),
              let cg = CIContext().createCGImage(out, from: out.extent) else { return nil }
        return UIImage(cgImage: cg)
    }
}

struct ShareLinkSheet: View {
    let target: ShareLinkTarget
    @EnvironmentObject private var session: Session
    @Environment(\.dismiss) private var dismiss
    @Environment(\.theme) private var theme
    @State private var title = ""
    @State private var expires = "1m"
    @State private var password = ""
    @State private var allowDownload = true
    @State private var showLocation = false
    @State private var busy = false
    @State private var errorText: String?
    @State private var created: ShareLinkCreated?
    @State private var copied = false

    var body: some View {
        NavigationStack {
            Group {
                if let created {
                    result(created)
                } else {
                    form
                }
            }
            .navigationTitle(created == nil ? heading : "Bağlantı hazır")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(created == nil ? "Vazgeç" : "Bitti") { dismiss() }
                }
            }
        }
        .tint(theme.accent)
        .onAppear {
            if case let .album(_, t) = target, title.isEmpty { title = t }
        }
    }

    private var heading: String {
        switch target {
        case .album: return "Albümü paylaş"
        case let .selection(ids): return ids.count == 1 ? "Fotoğrafı paylaş" : "\(ids.count) öğeyi paylaş"
        }
    }

    private var form: some View {
        Form {
            Section {
                TextField("Başlık (isteğe bağlı)", text: $title)
            } footer: {
                Text("Bağlantıyı alan herkes, Tailscale olmadan da fotoğrafları görebilir. İstediğiniz an kapatabilirsiniz.")
            }
            .listRowBackground(theme.groupSurface)
            Section {
                Picker("Geçerlilik süresi", selection: $expires) {
                    Text("1 gün").tag("1d")
                    Text("1 hafta").tag("1w")
                    Text("1 ay").tag("1m")
                    Text("Süresiz").tag("never")
                }
                .pickerStyle(.segmented)
            } header: {
                Text("Geçerlilik süresi")
            }
            .listRowBackground(theme.groupSurface)
            Section {
                SecureField("Parola (isteğe bağlı)", text: $password)
                    .textContentType(.newPassword)
            } footer: {
                Text("Boş bırakırsanız parola sorulmaz. En az 4 karakter.")
            }
            .listRowBackground(theme.groupSurface)
            Section {
                Toggle("İndirmeye izin ver", isOn: $allowDownload).tint(theme.toggleTint)
                Toggle("Konumu göster", isOn: $showLocation).tint(theme.toggleTint)
            } footer: {
                Text(showLocation
                     ? "Fotoğraflardaki konum bilgisi bağlantıyı açan herkese görünür."
                     : "Konum kapalıyken fotoğraf ve videolardaki GPS bilgisi silinerek sunulur.")
            }
            .listRowBackground(theme.groupSurface)
            if case .album = target {
                Section {
                    Label("Albüme sonradan eklenen fotoğraflar (katılımcıların ekledikleri dahil) da bu bağlantıda görünür.", systemImage: "rectangle.stack")
                        .font(.footnote)
                        .foregroundStyle(theme.muted)
                }
                .listRowBackground(theme.groupSurface)
            }
            if let errorText {
                Section { Text(errorText).foregroundStyle(.red) }
                    .listRowBackground(theme.groupSurface)
            }
            Section {
                Button {
                    Task { await create() }
                } label: {
                    HStack {
                        Spacer()
                        if busy {
                            ProgressView()
                        } else {
                            Label("Bağlantı oluştur", systemImage: "link").font(.headline)
                        }
                        Spacer()
                    }
                }
                .disabled(busy)
            }
            .listRowBackground(theme.groupSurface)
        }
        .scrollContentBackground(.hidden)
        .background(theme.canvas)
    }

    private func result(_ c: ShareLinkCreated) -> some View {
        ScrollView {
            VStack(spacing: 18) {
                if let img = ShareLinkQR.image(c.url) {
                    Image(uiImage: img)
                        .interpolation(.none)
                        .resizable()
                        .scaledToFit()
                        .frame(width: 210, height: 210)
                        .padding(12)
                        .background(Color.white, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        .accessibilityLabel("Paylaşım bağlantısının QR kodu")
                }
                Text(c.url)
                    .font(.footnote.monospaced())
                    .foregroundStyle(theme.ink)
                    .textSelection(.enabled)
                    .multilineTextAlignment(.center)
                if let url = URL(string: c.url) {
                    ShareLink(item: url, subject: Text(c.link.title), message: Text(c.link.title)) {
                        Label("Paylaş…", systemImage: "square.and.arrow.up")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.borderedProminent)
                    .controlSize(.large)
                }
                Button {
                    UIPasteboard.general.string = c.url
                    copied = true
                } label: {
                    Label(copied ? "Kopyalandı" : "Bağlantıyı kopyala", systemImage: copied ? "checkmark" : "doc.on.doc")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)
                .controlSize(.large)
                Text("Güvenlik için bu adres yalnızca şimdi gösterilir. Kaybederseniz web'deki “Paylaşılan bağlantılar” sayfasından yeni adres oluşturabilirsiniz.")
                    .font(.footnote)
                    .foregroundStyle(theme.muted)
                    .multilineTextAlignment(.center)
                if c.funnel == false {
                    Label("Herkese açık adres henüz ayarlanmadı; bu bağlantı şimdilik yalnızca Tailscale ağınızdaki cihazlarda açılır. Sunucuda bir kez: sudo bash server/setup-share.sh", systemImage: "exclamationmark.triangle")
                        .font(.footnote)
                        .foregroundStyle(theme.ink)
                        .padding(12)
                        .background(theme.groupSurface, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
            }
            .padding(24)
        }
        .background(theme.canvas)
    }

    @MainActor
    private func create() async {
        guard let client = session.client else { return }
        busy = true
        defer { busy = false }
        errorText = nil
        var kind = "selection"
        var albumId: String?
        var ids: [String]?
        switch target {
        case let .album(id, _):
            kind = "album"
            albumId = id
        case let .selection(list):
            ids = list
        }
        let body = ShareLinkCreateBody(
            kind: kind, album_id: albumId, ids: ids,
            title: title.trimmingCharacters(in: .whitespacesAndNewlines), expires: expires,
            password: password.isEmpty ? nil : password, allow_download: allowDownload, show_location: showLocation)
        do {
            let res: ShareLinkCreated = try await client.send("POST", "shares", body: body)
            created = res
        } catch {
            errorText = error.localizedDescription
        }
    }
}

/// Paylaşılan bağlantılar: görüntülenme sayıları, kaydırarak kapatma.
struct ShareLinksView: View {
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @State private var links: [ShareLinkInfo] = []
    @State private var loading = true
    @State private var errorText: String?

    var body: some View {
        List {
            if let errorText {
                Text(errorText).foregroundStyle(.red)
                    .listRowBackground(theme.groupSurface)
            }
            if !loading && links.isEmpty && errorText == nil {
                ContentUnavailableView("Henüz bağlantı yok", systemImage: "link",
                                       description: Text("Bir fotoğrafı açıp bağlantı düğmesine dokunun ya da web'de bir albümde “Bağlantıyla paylaş”ı kullanın."))
                    .listRowBackground(Color.clear)
            }
            ForEach(links) { l in
                VStack(alignment: .leading, spacing: 4) {
                    HStack(alignment: .firstTextBaseline) {
                        Text(l.title).font(theme.body(16, weight: .semibold)).foregroundStyle(theme.ink).lineLimit(1)
                        Spacer(minLength: 8)
                        Text(Self.statusText(l.status))
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(l.status == "active" ? theme.accent : theme.muted)
                    }
                    Text(Self.meta(l)).font(.footnote).foregroundStyle(theme.muted)
                }
                .padding(.vertical, 2)
                .listRowBackground(theme.groupSurface)
                .swipeActions {
                    if l.status != "revoked" {
                        Button("Kapat", role: .destructive) { Task { await revoke(l) } }
                    }
                }
            }
        }
        .scrollContentBackground(.hidden)
        .background(theme.canvas)
        .navigationTitle("Paylaşılan bağlantılar")
        .overlay { if loading && links.isEmpty { ProgressView() } }
        .task { await load() }
        .refreshable { await load() }
    }

    static func statusText(_ s: String) -> String {
        switch s {
        case "active": return "Etkin"
        case "expired": return "Süresi doldu"
        default: return "Kapatıldı"
        }
    }

    static func meta(_ l: ShareLinkInfo) -> String {
        var parts = ["\(l.count) öğe", "\(l.views) görüntülenme", l.kind == "album" ? "Albüm" : "Seçim"]
        if l.hasPassword { parts.append("Parolalı") }
        if l.showLocation { parts.append("Konum açık") }
        return parts.joined(separator: " · ")
    }

    @MainActor
    private func load() async {
        guard let client = session.client else { return }
        defer { loading = false }
        do {
            let res: ShareLinkList = try await client.send("GET", "shares")
            links = res.links
            errorText = nil
        } catch {
            errorText = error.localizedDescription
        }
    }

    @MainActor
    private func revoke(_ l: ShareLinkInfo) async {
        guard let client = session.client else { return }
        do {
            let res: ShareLinkEnvelope = try await client.send("POST", "shares/\(l.id)/revoke")
            if let i = links.firstIndex(where: { $0.id == l.id }) { links[i] = res.link }
        } catch {
            errorText = error.localizedDescription
        }
    }
}
