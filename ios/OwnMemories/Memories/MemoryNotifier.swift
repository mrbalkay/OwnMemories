import Foundation
import UIKit
import UserNotifications

/// Anı bildirimleri: önümüzdeki günlerin anılarını sunucudan sorar ve seçilen saatte yerel bildirim planlar.
@MainActor
final class MemoryNotifier: ObservableObject {
    static let shared = MemoryNotifier()

    enum Frequency: String, CaseIterable, Identifiable {
        case daily, weekly
        var id: String { rawValue }
        var title: String { self == .daily ? "Her gün" : "Haftada bir (pazar)" }
    }

    @Published var enabled: Bool = UserDefaults.standard.bool(forKey: "notifEnabled") {
        didSet { UserDefaults.standard.set(enabled, forKey: "notifEnabled"); Task { await changed() } }
    }
    @Published var frequency: Frequency = Frequency(rawValue: UserDefaults.standard.string(forKey: "notifFrequency") ?? "") ?? .daily {
        didSet { UserDefaults.standard.set(frequency.rawValue, forKey: "notifFrequency"); Task { await reschedule() } }
    }
    @Published var hour: Int = UserDefaults.standard.object(forKey: "notifHour") as? Int ?? 9 {
        didSet { UserDefaults.standard.set(hour, forKey: "notifHour"); Task { await reschedule() } }
    }
    @Published private(set) var denied = false

    private let prefix = "memory-"
    private var current: Task<Void, Never>?

    private func changed() async {
        if enabled {
            let granted = (try? await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound, .badge])) ?? false
            denied = !granted
            if !granted { enabled = false; return }
        }
        await reschedule()
    }

    /// Önceki planlama sürüyorsa iptal edilir; yeni planlama tamamlanınca eski bildirimler değiştirilir.
    func reschedule() async {
        current?.cancel()
        let task = Task { await self.performReschedule() }
        current = task
        await task.value
    }

    /// Çıkışta bütün anı bildirimlerini kaldırır.
    func clearAll() async {
        current?.cancel()
        let center = UNUserNotificationCenter.current()
        let pending = await center.pendingNotificationRequests().map(\.identifier).filter { $0.hasPrefix(prefix) }
        center.removePendingNotificationRequests(withIdentifiers: pending)
        let delivered = await center.deliveredNotifications().map(\.request.identifier).filter { $0.hasPrefix(prefix) }
        center.removeDeliveredNotifications(withIdentifiers: delivered)
    }

    private func performReschedule() async {
        let center = UNUserNotificationCenter.current()
        let existing = await center.pendingNotificationRequests().map(\.identifier).filter { $0.hasPrefix(prefix) }
        guard enabled, let client = Session.storedClient() else {
            center.removePendingNotificationRequests(withIdentifiers: existing)
            return
        }
        let settings = await center.notificationSettings()
        guard settings.authorizationStatus == .authorized || settings.authorizationStatus == .provisional else { return }
        var fresh: [UNNotificationRequest] = []
        var anyFetched = false

        let cal = Calendar.current
        let today = cal.startOfDay(for: Date())
        let dayFmt = DateFormatter()
        dayFmt.calendar = Calendar(identifier: .gregorian)
        dayFmt.locale = Locale(identifier: "en_US_POSIX")
        dayFmt.dateFormat = "yyyy-MM-dd"
        for offset in 0..<8 {
            guard let day = cal.date(byAdding: .day, value: offset, to: today),
                  let fire = cal.date(bySettingHour: hour, minute: 0, second: 0, of: day), fire > Date() else { continue }
            if frequency == .weekly && cal.component(.weekday, from: day) != 1 { continue }
            if Task.isCancelled { return }
            let date = dayFmt.string(from: day)
            guard let r: MemoriesResponse = try? await client.send("GET", "memories", query: ["date": date]) else { continue }
            anyFetched = true
            guard let m = r.memories.first(where: { $0.kind != "favorites" }) ?? r.memories.first else { continue }
            let content = UNMutableNotificationContent()
            content.title = m.title
            content.body = m.count == 1 ? "Bir anınız var, bakmak ister misiniz?" : "\(m.count) fotoğraf ve video sizi bekliyor."
            content.sound = .default
            content.threadIdentifier = "memories"
            content.userInfo = ["memoryDate": m.date, "memoryId": m.id, "requestDate": date]
            if let att = await attachment(for: m.coverAssetId, client: client) { content.attachments = [att] }
            let comps = cal.dateComponents([.year, .month, .day, .hour, .minute], from: fire)
            fresh.append(UNNotificationRequest(identifier: prefix + date, content: content, trigger: UNCalendarNotificationTrigger(dateMatching: comps, repeats: false)))
        }
        // Hiçbir gün sorulamadıysa (sunucuya ulaşılamadı) mevcut planı koru.
        guard anyFetched, !Task.isCancelled, enabled else { return }
        let keep = Set(fresh.map(\.identifier))
        center.removePendingNotificationRequests(withIdentifiers: existing.filter { !keep.contains($0) })
        for req in fresh where !Task.isCancelled && enabled {
            try? await center.add(req)
        }
    }

    private func attachment(for assetId: String, client: APIClient) async -> UNNotificationAttachment? {
        guard let img = await ImageLoader.shared.image(for: client.url("assets/\(assetId)/thumb"), client: client),
              let data = img.jpegData(compressionQuality: 0.85) else { return nil }
        let url = FileManager.default.temporaryDirectory.appendingPathComponent("memory-\(assetId).jpg")
        guard (try? data.write(to: url)) != nil else { return nil }
        return try? UNNotificationAttachment(identifier: assetId, url: url, options: nil)
    }
}
