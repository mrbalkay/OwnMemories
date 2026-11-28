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
        .onChange(of: scenePhase) { old, phase in
            DiagLog.add("Sahne: \(Self.phaseName(old)) → \(Self.phaseName(phase))")
            switch phase {
            case .active:
                engine.setSceneActive(true)
                if session.isLoggedIn {
                    engine.start(reason: "açılış")
                    Task {
                        await memories.refresh()
                        await notifier.reschedule()
                    }
                }
            case .background:
                engine.enterBackground()
                BackgroundJobs.schedule()
                Task { await ImageLoader.shared.purge() }
            default:
                engine.setSceneActive(false)
            }
        }
    }

    private static func phaseName(_ phase: ScenePhase) -> String {
        switch phase {
        case .active: return "etkin"
        case .inactive: return "etkin değil"
        case .background: return "arka plan"
        @unknown default: return "bilinmiyor"
        }
    }
}
