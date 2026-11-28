import UIKit
import UserNotifications

final class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        BackgroundJobs.register()
        _ = BackupEngine.shared   // arka plan yükleme sonuçlarını alacak kancaları kur
        BackgroundUploader.shared.reconnect()
        if application.applicationState == .background { DiagLog.add("Uygulama arka planda başlatıldı") }
        return true
    }

    /// Arka planda biten yüklemeler uygulamayı uyandırır; tamamlanınca sisteme haber verilir.
    func application(_ application: UIApplication, handleEventsForBackgroundURLSession identifier: String, completionHandler: @escaping () -> Void) {
        guard identifier == BackgroundUploader.identifier else { completionHandler(); return }
        BackgroundUploader.shared.backgroundCompletion = completionHandler
        // Motoru (ve tamamlanma kancalarını) hazırla, sonra oturuma yeniden bağlan; olaylar bitince
        // BackupEngine sıradaki grubu teslim edip sistemi bilgilendirir.
        Task { @MainActor in
            _ = BackupEngine.shared
            BackgroundUploader.shared.reconnect()
        }
    }

    /// Anı bildirimine dokunulunca anıyı aç.
    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse, withCompletionHandler completionHandler: @escaping () -> Void) {
        let info = response.notification.request.content.userInfo
        if let id = info["memoryId"] as? String {
            let date = (info["requestDate"] as? String) ?? (info["memoryDate"] as? String) ?? ""
            Task { @MainActor in AppRouter.shared.openMemory(requestDate: date, id: id) }
        } else if let id = info["assetId"] as? String, let url = URL(string: "ownmemories://asset?id=\(id)") {
            Task { @MainActor in AppRouter.shared.handle(url) }
        }
        completionHandler()
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification, withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void) {
        completionHandler([.banner, .sound])
    }
}
