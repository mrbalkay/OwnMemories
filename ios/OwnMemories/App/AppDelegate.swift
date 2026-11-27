import UIKit

final class AppDelegate: NSObject, UIApplicationDelegate {
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        BackgroundJobs.register()
        BackgroundUploader.shared.reconnect()
        return true
    }

    /// Arka planda biten yüklemeler uygulamayı uyandırır; tamamlanınca sisteme haber verilir.
    func application(_ application: UIApplication, handleEventsForBackgroundURLSession identifier: String, completionHandler: @escaping () -> Void) {
        guard identifier == BackgroundUploader.identifier else { completionHandler(); return }
        BackgroundUploader.shared.backgroundCompletion = completionHandler
        BackgroundUploader.shared.reconnect()
        Task { @MainActor in BackupEngine.shared.start(reason: "arka plan yüklemesi") }
    }
}
