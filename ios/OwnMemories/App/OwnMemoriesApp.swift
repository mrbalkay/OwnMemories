import SwiftUI

@main
struct OwnMemoriesApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @StateObject private var session = Session.shared
    @StateObject private var engine = BackupEngine.shared
    @StateObject private var memories = MemoryService.shared
    @StateObject private var notifier = MemoryNotifier.shared
    @StateObject private var router = AppRouter.shared
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(session)
                .environmentObject(engine)
                .environmentObject(memories)
                .environmentObject(notifier)
                .environmentObject(router)
                .onOpenURL { router.handle($0) }
        }
        .onChange(of: scenePhase) { _, phase in
            switch phase {
            case .active:
                if session.isLoggedIn {
                    engine.start(reason: "açılış")
                    Task {
                        await memories.refresh()
                        await notifier.reschedule()
                    }
                }
            case .background:
                BackgroundJobs.schedule()
            default:
                break
            }
        }
    }
}
