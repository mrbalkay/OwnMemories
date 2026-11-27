import SwiftUI

@MainActor
final class LibraryModel: ObservableObject {
    @Published private(set) var assets: [Asset] = []
    @Published private(set) var sections: [DaySection] = []
    @Published private(set) var loading = false
    @Published private(set) var error: String?
    private var next: AssetCursor?
    private(set) var done = false

    func reload(client: APIClient?) async {
        next = nil
        done = false
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

    func update(_ asset: Asset) {
        if let i = assets.firstIndex(where: { $0.id == asset.id }) {
            assets[i] = asset
            sections = DayGrouping.group(assets)
        }
    }
}

struct LibraryView: View {
    @EnvironmentObject private var session: Session
    @StateObject private var model = LibraryModel()
    @State private var viewing: Asset?
    private let columns = [GridItem(.adaptive(minimum: 100), spacing: 2)]

    var body: some View {
        NavigationStack {
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 0, pinnedViews: []) {
                    ForEach(model.sections) { section in
                        Text(DayGrouping.title(for: section.id))
                            .font(.system(.title3, design: .serif))
                            .padding(.horizontal, 14).padding(.top, 18).padding(.bottom, 8)
                        LazyVGrid(columns: columns, spacing: 2) {
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
                        ProgressView().frame(maxWidth: .infinity).padding()
                    } else if let err = model.error {
                        VStack(spacing: 10) {
                            Text(err).multilineTextAlignment(.center).foregroundStyle(.secondary)
                            Button("Tekrar dene") { Task { await model.loadMore(client: session.client) } }
                        }
                        .frame(maxWidth: .infinity).padding(30)
                    } else if model.assets.isEmpty && model.done {
                        Text("Henüz fotoğraf yok. Yedekleme sekmesinden yedeklemeyi başlatın.")
                            .multilineTextAlignment(.center).foregroundStyle(.secondary).padding(40)
                    }
                }
            }
            .navigationTitle("Fotoğraflar")
            .refreshable { await model.reload(client: session.client) }
            .task { if model.assets.isEmpty { await model.reload(client: session.client) } }
            .fullScreenCover(item: $viewing) { asset in
                AssetViewer(assets: model.assets, startId: asset.id, onChange: { model.update($0) })
                    .environmentObject(session)
            }
        }
    }
}

private struct Tile: View {
    let asset: Asset

    var body: some View {
        Color.clear
            .aspectRatio(1, contentMode: .fit)
            .overlay(RemoteImage(assetId: asset.id))
            .clipped()
            .overlay(alignment: .topTrailing) {
                HStack(spacing: 4) {
                    if asset.isVideo {
                        Image(systemName: "play.fill")
                        if let ms = asset.durationMs { Text(String(format: "%ld:%02ld", ms / 60000, (ms / 1000) % 60)) }
                    }
                    if asset.livePairId != nil && !asset.isVideo { Image(systemName: "livephoto") }
                    if asset.isFavorite { Image(systemName: "heart.fill") }
                }
                .font(.caption2.bold())
                .foregroundStyle(.white)
                .shadow(radius: 2)
                .padding(5)
            }
            .contentShape(Rectangle())
    }
}
