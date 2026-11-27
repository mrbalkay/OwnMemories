import SwiftUI

struct RootView: View {
    @EnvironmentObject private var session: Session
    @EnvironmentObject private var router: AppRouter

    var body: some View {
        Group {
            if session.isLoggedIn {
                MainTabView()
                    .fullScreenCover(item: $router.memory) { target in
                        MemoryLoaderView(target: target).environmentObject(session)
                    }
                    .fullScreenCover(item: $router.asset) { target in
                        SingleAssetLoader(assetId: target.id).environmentObject(session)
                    }
            } else {
                LoginView()
            }
        }
        .tint(Color.accentColor)
    }
}

/// Widget'tan açılan tek fotoğraf.
struct SingleAssetLoader: View {
    let assetId: String
    @EnvironmentObject private var session: Session
    @State private var asset: Asset?

    var body: some View {
        Group {
            if let asset {
                AssetViewer(assets: [asset], startId: asset.id, onChange: { _ in })
            } else {
                ZStack { Color.black.ignoresSafeArea(); ProgressView().tint(.white) }
            }
        }
        .task {
            guard let client = session.client else { return }
            let r: AssetEnvelope? = try? await client.send("GET", "assets/\(assetId)")
            asset = r?.asset
        }
    }
}

struct MainTabView: View {
    var body: some View {
        TabView {
            LibraryView()
                .tabItem { Label("Fotoğraflar", systemImage: "photo.on.rectangle") }
            BackupView()
                .tabItem { Label("Yedekleme", systemImage: "arrow.triangle.2.circlepath.icloud") }
            SettingsView()
                .tabItem { Label("Ayarlar", systemImage: "gearshape") }
        }
    }
}
