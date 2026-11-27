import SwiftUI

struct RootView: View {
    @EnvironmentObject private var session: Session

    var body: some View {
        Group {
            if session.isLoggedIn {
                MainTabView()
            } else {
                LoginView()
            }
        }
        .tint(Color.accentColor)
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
