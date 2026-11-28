import SwiftUI
import UIKit
import WidgetKit

@main
struct OwnMemoriesWidgetBundle: WidgetBundle {
    var body: some Widget {
        MemoriesWidget()
        RandomPhotoWidget()
    }
}

struct PhotoEntry: TimelineEntry {
    let date: Date
    let title: String
    let subtitle: String
    let imageFile: String?
    let link: URL?
}

private func entries(from photos: [(title: String, photo: WidgetPhoto, link: URL)], every minutes: Int) -> [PhotoEntry] {
    let now = Date()
    return photos.prefix(24).enumerated().map { i, item in
        PhotoEntry(date: now.addingTimeInterval(TimeInterval(i * minutes * 60)), title: item.title, subtitle: item.photo.caption,
                   imageFile: item.photo.file, link: item.link)
    }
}

private let placeholderEntry = PhotoEntry(date: Date(), title: "Anılar", subtitle: "Uygulamayı açınca burada görünür", imageFile: nil, link: nil)

/// "Bu gün geçmişte": bugünün anılarındaki fotoğrafları sırayla gösterir; anı yoksa rastgele bir an.
struct MemoriesProvider: TimelineProvider {
    func placeholder(in context: Context) -> PhotoEntry { placeholderEntry }

    func getSnapshot(in context: Context, completion: @escaping (PhotoEntry) -> Void) {
        completion(build().first ?? placeholderEntry)
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<PhotoEntry>) -> Void) {
        let list = build()
        let midnight = Calendar.current.startOfDay(for: Date()).addingTimeInterval(24 * 3600 + 60)
        let refresh = min((list.last?.date ?? Date()).addingTimeInterval(30 * 60), midnight)
        completion(Timeline(entries: list.isEmpty ? [placeholderEntry] : list, policy: .after(refresh)))
    }

    private func build() -> [PhotoEntry] {
        let s = WidgetStore.load()
        var items: [(String, WidgetPhoto, URL)] = []
        // "N yıl önce bugün" yalnız bugünün anlık görüntüsüyse gösterilir; değilse rastgele anlara düşülür.
        if Calendar.current.isDateInToday(s.updatedAt) {
            for m in s.memories {
                for p in m.photos { items.append((m.title, p, DeepLink.memory(requestDate: m.requestDate, id: m.id))) }
            }
        }
        if items.isEmpty {
            items = s.randoms.map { ("Geçmişten bir an", $0, DeepLink.asset($0.assetId)) }
        }
        return entries(from: items.map { (title: $0.0, photo: $0.1, link: $0.2) }, every: 30)
    }
}

struct RandomProvider: TimelineProvider {
    func placeholder(in context: Context) -> PhotoEntry { placeholderEntry }

    func getSnapshot(in context: Context, completion: @escaping (PhotoEntry) -> Void) {
        completion(build().first ?? placeholderEntry)
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<PhotoEntry>) -> Void) {
        let list = build()
        let refresh = (list.last?.date ?? Date()).addingTimeInterval(60 * 60)
        completion(Timeline(entries: list.isEmpty ? [placeholderEntry] : list, policy: .after(refresh)))
    }

    private func build() -> [PhotoEntry] {
        let s = WidgetStore.load()
        return entries(from: s.randoms.shuffled().map { (title: "Geçmişten bir an", photo: $0, link: DeepLink.asset($0.assetId)) }, every: 60)
    }
}

/// Widget görünümü; seçili tema App Group'tan okunur (uygulama tema değişince zaman çizelgesini yeniler).
/// Sinema: kenardan kenara fotoğraf, koyu geçiş, serif başlık. Galeri: orta boyda beyaz kart, solda
/// fotoğraf sağda serif künye; küçükte fotoğraf ve ince serif yazı. Yerel: SF kalın başlık, üstte simgeli etiket.
struct PhotoWidgetView: View {
    let entry: PhotoEntry
    /// Üst satır: "Bu gün geçmişte" / "Rastgele anı".
    let eyebrow: String
    @Environment(\.widgetFamily) private var family

    private var theme: AppTheme { AppTheme.of(ThemeName.stored) }
    private var small: Bool { family == .systemSmall }
    /// Görsel bir kez okunur (gövde birden çok kez erişir; widget bellek sınırı dar).
    private let photo: UIImage?

    init(entry: PhotoEntry, eyebrow: String) {
        self.entry = entry
        self.eyebrow = eyebrow
        if let file = entry.imageFile, let url = WidgetStore.imageURL(file) {
            photo = UIImage(contentsOfFile: url.path)
        } else {
            photo = nil
        }
    }
    /// Sağlayıcıların rastgele fotoğraf için kullandığı genel başlık; bu durumda başlık yerine tarih öne çıkar.
    private static let generic = "Geçmişten bir an"
    private var isGeneric: Bool { entry.title == Self.generic }
    private var kicker: String {
        // "Bu gün geçmişte" widget'ı anı yokken rastgele ana düşer: etiket buna göre değişir.
        isGeneric && eyebrow == "Bu gün geçmişte" ? Self.generic : eyebrow
    }
    private var headline: String { isGeneric && !entry.subtitle.isEmpty ? entry.subtitle : entry.title }
    private var detail: String? {
        entry.subtitle.isEmpty || entry.subtitle == headline ? nil : entry.subtitle
    }

    var body: some View {
        content
            .widgetURL(entry.link)
    }

    @ViewBuilder
    private var content: some View {
        switch theme.name {
        case .sinema: sinema
        case .galeri: galeri
        case .yerel: yerel
        }
    }

    // MARK: Sinema

    private var sinema: some View {
        ZStack(alignment: .bottomLeading) {
            LinearGradient(stops: [.init(color: .black.opacity(0.78), location: 0), .init(color: .black.opacity(0.25), location: 0.52),
                                   .init(color: .clear, location: 0.72)], startPoint: .bottom, endPoint: .top)
            VStack(alignment: .leading, spacing: 1) {
                Text(kicker).font(.system(size: 12, weight: .semibold)).foregroundStyle(.white.opacity(0.9))
                Text(headline)
                    .font(.system(size: family == .systemLarge ? 26 : 20, weight: .medium, design: .serif))
                    .lineLimit(2)
                if let detail {
                    Text(detail).font(.system(size: 11)).foregroundStyle(.white.opacity(0.82)).lineLimit(1).padding(.top, 1)
                }
            }
            .foregroundStyle(.white)
            .shadow(color: .black.opacity(0.45), radius: 8)
            .padding(.horizontal, 14).padding(.bottom, 12)
        }
        .containerBackground(for: .widget) {
            if let img = photo {
                Image(uiImage: img).resizable().scaledToFill()
            } else {
                ZStack {
                    Color(hex: 0x15171C)
                    RadialGradient(colors: [Color(hex: 0x3A3F4A), .clear], center: .top, startRadius: 0, endRadius: 220)
                }
            }
        }
    }

    // MARK: Galeri

    @ViewBuilder
    private var galeri: some View {
        if family == .systemMedium {
            HStack(spacing: 0) {
                Color.clear
                    .aspectRatio(1, contentMode: .fit)
                    .overlay(galeriPhoto)
                    .clipped()
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 6) {
                        Text("O")
                            .font(.system(size: 9, weight: .regular, design: .serif))
                            .foregroundStyle(.white)
                            .frame(width: 14, height: 14)
                            .background(theme.ink, in: RoundedRectangle(cornerRadius: 4, style: .continuous))
                        Text(kicker).font(.system(size: 11)).foregroundStyle(theme.muted)
                    }
                    Text(headline)
                        .font(.system(size: 24, weight: .regular, design: .serif))
                        .foregroundStyle(theme.ink)
                        .lineLimit(3)
                        .minimumScaleFactor(0.8)
                        .padding(.top, 10)
                    Spacer(minLength: 4)
                    if let detail {
                        Text(detail).font(.system(size: 12)).foregroundStyle(theme.muted).lineLimit(2)
                    }
                }
                .padding(.horizontal, 16).padding(.top, 16).padding(.bottom, 14)
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
            }
            .containerBackground(for: .widget) { Color.white }
        } else if family == .systemLarge {
            VStack(spacing: 0) {
                Color.clear
                    .overlay(galeriPhoto)
                    .clipped()
                VStack(alignment: .leading, spacing: 2) {
                    Text(kicker).font(.system(size: 11)).foregroundStyle(theme.muted)
                    Text(headline)
                        .font(.system(size: 22, weight: .regular, design: .serif))
                        .foregroundStyle(theme.ink)
                        .lineLimit(2)
                    if let detail {
                        Text(detail).font(.system(size: 12)).foregroundStyle(theme.muted).lineLimit(1)
                    }
                }
                .padding(.horizontal, 16).padding(.vertical, 12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .overlay(alignment: .top) { Rectangle().fill(theme.hairline).frame(height: 1) }
            }
            .containerBackground(for: .widget) { Color.white }
        } else {
            ZStack(alignment: .bottomLeading) {
                LinearGradient(stops: [.init(color: .clear, location: 0.45), .init(color: .black.opacity(photo == nil ? 0 : 0.55), location: 1)],
                               startPoint: .top, endPoint: .bottom)
                VStack(alignment: .leading, spacing: 0) {
                    Text(kicker).font(.system(size: 11)).opacity(0.85)
                    Text(headline)
                        .font(.system(size: 18, weight: .regular, design: .serif))
                        .lineLimit(2)
                }
                .foregroundStyle(photo == nil ? theme.ink : Color.white)
                .padding(.horizontal, 14).padding(.bottom, 12)
            }
            .containerBackground(for: .widget) {
                if let img = photo {
                    Image(uiImage: img).resizable().scaledToFill()
                } else {
                    theme.background
                }
            }
        }
    }

    @ViewBuilder
    private var galeriPhoto: some View {
        if let img = photo {
            Image(uiImage: img).resizable().scaledToFill()
        } else {
            theme.background
        }
    }

    // MARK: Yerel

    private var yerel: some View {
        ZStack {
            LinearGradient(stops: [.init(color: .black.opacity(0.28), location: 0), .init(color: .clear, location: 0.38),
                                   .init(color: .clear, location: 0.5), .init(color: .black.opacity(0.55), location: 1)],
                           startPoint: .top, endPoint: .bottom)
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 6) {
                    Image(systemName: "sparkles")
                        .font(.system(size: 9, weight: .bold))
                        .frame(width: 16, height: 16)
                        .background(Color(hex: 0x0A7CFF), in: RoundedRectangle(cornerRadius: 5, style: .continuous))
                    Text(kicker).font(.system(size: 12, weight: .semibold))
                }
                Spacer(minLength: 0)
                Text(headline)
                    .font(.system(size: small ? 18 : (family == .systemLarge ? 26 : 20), weight: .bold))
                    .tracking(-0.4)
                    .lineLimit(2)
                if let detail {
                    Text(detail).font(.system(size: 12.5)).opacity(0.9).lineLimit(1).padding(.top, 3)
                }
            }
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .leading)
            .padding(.horizontal, 15).padding(.top, 13).padding(.bottom, 13)
        }
        .containerBackground(for: .widget) {
            if let img = photo {
                Image(uiImage: img).resizable().scaledToFill()
            } else {
                LinearGradient(colors: [Color(hex: 0x6F9BE8), Color(hex: 0x9C82DE), Color(hex: 0xE49A93)],
                               startPoint: .topLeading, endPoint: .bottomTrailing)
            }
        }
    }
}

struct MemoriesWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "OwnMemoriesMemories", provider: MemoriesProvider()) { entry in
            PhotoWidgetView(entry: entry, eyebrow: "Bu gün geçmişte")
        }
        .configurationDisplayName("Bu gün geçmişte")
        .description("Geçmiş yıllarda bugün çektiğiniz fotoğraflar.")
        .supportedFamilies([.systemSmall, .systemMedium, .systemLarge])
        .contentMarginsDisabled()
    }
}

struct RandomPhotoWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "OwnMemoriesRandom", provider: RandomProvider()) { entry in
            PhotoWidgetView(entry: entry, eyebrow: "Rastgele anı")
        }
        .configurationDisplayName("Rastgele anı")
        .description("Arşivinizden saatte bir farklı bir fotoğraf.")
        .supportedFamilies([.systemSmall, .systemMedium, .systemLarge])
        .contentMarginsDisabled()
    }
}
