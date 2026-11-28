import SwiftUI
import UIKit

struct RootView: View {
    @EnvironmentObject private var session: Session
    @EnvironmentObject private var router: AppRouter
    @StateObject private var themes = ThemeStore.shared

    var body: some View {
        Group {
            if session.isLoggedIn {
                MainTabView()
                    .fullScreenCover(item: $router.memory) { target in
                        MemoryLoaderView(target: target).environmentObject(session).environment(\.theme, themes.theme)
                    }
                    .fullScreenCover(item: $router.asset) { target in
                        SingleAssetLoader(assetId: target.id).environmentObject(session).environment(\.theme, themes.theme)
                    }
            } else {
                LoginView()
            }
        }
        .environmentObject(themes)
        .environment(\.theme, themes.theme)
        .tint(themes.theme.accent)
        .preferredColorScheme(themes.theme.colorScheme)
        .task { if session.isLoggedIn { await session.refreshUser() } }
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
    @Environment(\.theme) private var theme

    var body: some View {
        TabView {
            LibraryView()
                .tabItem { Label("Fotoğraflar", systemImage: "photo.on.rectangle") }
            MapScreen()
                .tabItem { Label("Harita", systemImage: "map") }
            BackupView()
                .tabItem { Label("Yedekleme", systemImage: "arrow.triangle.2.circlepath.icloud") }
            SettingsView()
                .tabItem { Label("Ayarlar", systemImage: "gearshape") }
        }
        .onAppear { BarStyler.apply(theme) }
        .onChange(of: theme.name) { _, new in BarStyler.apply(AppTheme.of(new)) }
    }
}

/// Sekme ve gezinme çubuklarının tema görünümü (UIKit görünüm vekilleri). Tema değişince
/// açık pencerelerdeki mevcut çubuklar da hemen güncellenir.
@MainActor
enum BarStyler {
    static func apply(_ theme: AppTheme) {
        let tab = tabAppearance(theme)
        let nav = navAppearance(theme)
        UITabBar.appearance().standardAppearance = tab
        UITabBar.appearance().scrollEdgeAppearance = tab
        UINavigationBar.appearance().standardAppearance = nav
        UINavigationBar.appearance().scrollEdgeAppearance = nav
        UINavigationBar.appearance().compactAppearance = nav
        for scene in UIApplication.shared.connectedScenes {
            guard let ws = scene as? UIWindowScene else { continue }
            for window in ws.windows {
                // preferredColorScheme(nil) koyu temadan sonra her zaman sisteme dönmüyor (Sinema → Yerel);
                // pencere düzeyinde açıkça ayarlamak sunulan tüm sayfalara da yayılır.
                window.overrideUserInterfaceStyle = style(theme.colorScheme)
                update(window.rootViewController, tab: tab, nav: nav)
            }
        }
    }

    private static func style(_ scheme: ColorScheme?) -> UIUserInterfaceStyle {
        guard let scheme else { return .unspecified }
        return scheme == .dark ? .dark : .light
    }

    private static func update(_ vc: UIViewController?, tab: UITabBarAppearance, nav: UINavigationBarAppearance) {
        guard let vc else { return }
        if let tc = vc as? UITabBarController {
            tc.tabBar.standardAppearance = tab
            tc.tabBar.scrollEdgeAppearance = tab
        }
        if let nc = vc as? UINavigationController {
            nc.navigationBar.standardAppearance = nav
            nc.navigationBar.scrollEdgeAppearance = nav
            nc.navigationBar.compactAppearance = nav
        }
        for child in vc.children { update(child, tab: tab, nav: nav) }
    }

    private static func tabAppearance(_ theme: AppTheme) -> UITabBarAppearance {
        let a = UITabBarAppearance()
        switch theme.name {
        case .sinema:
            // Koyu cam: rgba(28,30,36,.55) + bulanıklık, ince açık çizgi.
            a.configureWithTransparentBackground()
            a.backgroundEffect = UIBlurEffect(style: .systemUltraThinMaterialDark)
            a.backgroundColor = UIColor(red: 28 / 255, green: 30 / 255, blue: 36 / 255, alpha: 0.55)
            a.shadowColor = UIColor.white.withAlphaComponent(0.14)
        case .galeri:
            // Beyaz %86 + bulanıklık, üstte kılcal çizgi.
            a.configureWithTransparentBackground()
            a.backgroundEffect = UIBlurEffect(style: .systemThinMaterialLight)
            a.backgroundColor = UIColor.white.withAlphaComponent(0.86)
            a.shadowColor = UIColor(red: 226 / 255, green: 225 / 255, blue: 220 / 255, alpha: 1)
        case .yerel:
            a.configureWithDefaultBackground()
            a.backgroundEffect = UIBlurEffect(style: .systemChromeMaterial)
        }
        let normal = UIColor(theme.tabNormal)
        let selected = UIColor(theme.tabSelected)
        let selectedIcon = theme.name == .galeri ? UIColor(theme.accent) : selected
        for layout in [a.stackedLayoutAppearance, a.inlineLayoutAppearance, a.compactInlineLayoutAppearance] {
            layout.normal.iconColor = normal
            layout.normal.titleTextAttributes = [.foregroundColor: normal, .font: UIFont.systemFont(ofSize: 10, weight: .medium)]
            layout.selected.iconColor = selectedIcon
            layout.selected.titleTextAttributes = [.foregroundColor: selected, .font: UIFont.systemFont(ofSize: 10, weight: .semibold)]
        }
        return a
    }

    private static func navAppearance(_ theme: AppTheme) -> UINavigationBarAppearance {
        let a = UINavigationBarAppearance()
        switch theme.name {
        case .sinema:
            a.configureWithTransparentBackground()
            a.backgroundEffect = UIBlurEffect(style: .systemUltraThinMaterialDark)
            a.backgroundColor = UIColor(theme.background).withAlphaComponent(0.6)
        case .galeri:
            a.configureWithOpaqueBackground()
            a.backgroundColor = UIColor(theme.background)
            a.shadowColor = UIColor(theme.hairline)
        case .yerel:
            a.configureWithDefaultBackground()
        }
        let ink = UIColor(theme.ink)
        a.largeTitleTextAttributes = [.foregroundColor: ink, .font: theme.uiTitleFont(34)]
        a.titleTextAttributes = [.foregroundColor: ink, .font: theme.isSerif ? theme.uiTitleFont(19) : UIFont.systemFont(ofSize: 17, weight: .semibold)]
        return a
    }
}
