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

struct PhotoWidgetView: View {
    let entry: PhotoEntry
    @Environment(\.widgetFamily) private var family

    var body: some View {
        ZStack(alignment: .bottomLeading) {
            LinearGradient(colors: [.clear, .black.opacity(0.65)], startPoint: .center, endPoint: .bottom)
            VStack(alignment: .leading, spacing: 2) {
                Text(entry.title)
                    .font(.system(family == .systemSmall ? .subheadline : .title3, design: .serif).weight(.semibold))
                    .lineLimit(2)
                if !entry.subtitle.isEmpty {
                    Text(entry.subtitle).font(.caption2).opacity(0.85).lineLimit(1)
                }
            }
            .foregroundStyle(.white)
            .padding(family == .systemSmall ? 12 : 16)
        }
        .containerBackground(for: .widget) {
            if let file = entry.imageFile, let url = WidgetStore.imageURL(file), let img = UIImage(contentsOfFile: url.path) {
                Image(uiImage: img).resizable().scaledToFill()
            } else {
                LinearGradient(colors: [Color(red: 0.72, green: 0.66, blue: 0.91), Color(red: 0.96, green: 0.72, blue: 0.78)],
                               startPoint: .topLeading, endPoint: .bottomTrailing)
            }
        }
        .widgetURL(entry.link)
    }
}

struct MemoriesWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "OwnMemoriesMemories", provider: MemoriesProvider()) { entry in
            PhotoWidgetView(entry: entry)
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
            PhotoWidgetView(entry: entry)
        }
        .configurationDisplayName("Rastgele anı")
        .description("Arşivinizden saatte bir farklı bir fotoğraf.")
        .supportedFamilies([.systemSmall, .systemMedium, .systemLarge])
        .contentMarginsDisabled()
    }
}
