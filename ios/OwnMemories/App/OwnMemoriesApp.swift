import SwiftUI

@main
struct OwnMemoriesApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @StateObject private var session = Session.shared
    @StateObject private var engine = BackupEngine.shared
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(session)
                .environmentObject(engine)
        }
        .onChange(of: scenePhase) { _, phase in
            switch phase {
            case .active:
                if session.isLoggedIn { engine.start(reason: "açılış") }
            case .background:
                BackgroundJobs.schedule()
            default:
                break
            }
        }
    }
}
