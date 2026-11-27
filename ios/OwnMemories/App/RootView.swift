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
    @Environment(\.dismiss) private var dismiss
    @State private var asset: Asset?
    @State private var failed = false

    var body: some View {
        Group {
            if let asset {
                AssetViewer(assets: [asset], startId: asset.id, onChange: { _ in })
            } else {
                ZStack(alignment: .topLeading) {
                    Color.black.ignoresSafeArea()
                    VStack(spacing: 14) {
                        if failed {
                            Text("Fotoğraf açılamadı. Sunucuya ulaşılamıyor olabilir.").foregroundStyle(.white).multilineTextAlignment(.center)
                        } else {
                            ProgressView().tint(.white)
                        }
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .padding(30)
                    Button { dismiss() } label: { Image(systemName: "xmark").font(.title3).foregroundStyle(.white).padding(16) }
                }
            }
        }
        .task {
            guard let client = session.client else { failed = true; return }
            let r: AssetEnvelope? = try? await client.send("GET", "assets/\(assetId)")
            asset = r?.asset
            failed = asset == nil
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
