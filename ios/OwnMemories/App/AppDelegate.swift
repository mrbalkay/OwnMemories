import UIKit
import UserNotifications

final class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        UNUserNotificationCenter.current().delegate = self
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

    /// Anı bildirimine dokunulunca anıyı aç.
    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse, withCompletionHandler completionHandler: @escaping () -> Void) {
        let info = response.notification.request.content.userInfo
        if let id = info["memoryId"] as? String {
            let date = (info["requestDate"] as? String) ?? (info["memoryDate"] as? String) ?? ""
            Task { @MainActor in AppRouter.shared.openMemory(requestDate: date, id: id) }
        }
        completionHandler()
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification, withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void) {
        completionHandler([.banner, .sound])
    }
}
