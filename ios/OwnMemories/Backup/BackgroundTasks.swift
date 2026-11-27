import BackgroundTasks
import Foundation

/// iOS arka plan görevleri: kısa yenileme (tarama + birkaç öğe) ve uzun işlem (ağ gerekli, ayara göre şarjda).
enum BackgroundJobs {
    static let refreshId = "net.balkay.ownmemories.refresh"
    static let backupId = "net.balkay.ownmemories.backup"

    static func register() {
        BGTaskScheduler.shared.register(forTaskWithIdentifier: refreshId, using: nil) { task in
            run(task, seconds: 25)
        }
        BGTaskScheduler.shared.register(forTaskWithIdentifier: backupId, using: nil) { task in
            run(task, seconds: nil)
        }
    }

    private static func run(_ task: BGTask, seconds: TimeInterval?) {
        let deadline = seconds.map { Date().addingTimeInterval($0) }
        let work = Task { @MainActor in
            // Yedekleme öncelikli; anılar ve bildirimler kalan sürede (en az 8 sn varsa) yenilenir.
            await BackupEngine.shared.runOnce(deadline: deadline.map { $0.addingTimeInterval(-8) })
            if Task.isCancelled { return }
            if deadline == nil || deadline!.timeIntervalSinceNow > 8 {
                await MemoryService.shared.refresh()
                await MemoryNotifier.shared.reschedule()
            }
        }
        task.expirationHandler = { work.cancel() }
        Task {
            await work.value
            task.setTaskCompleted(success: !work.isCancelled)
            await MainActor.run { schedule() }
        }
    }

    @MainActor
    static func schedule() {
        let refresh = BGAppRefreshTaskRequest(identifier: refreshId)
        refresh.earliestBeginDate = Date().addingTimeInterval(30 * 60)
        try? BGTaskScheduler.shared.submit(refresh)

        let backup = BGProcessingTaskRequest(identifier: backupId)
        backup.requiresNetworkConnectivity = true
        backup.requiresExternalPower = BackupEngine.shared.settings.chargingOnly
        backup.earliestBeginDate = Date().addingTimeInterval(15 * 60)
        try? BGTaskScheduler.shared.submit(backup)
    }
}
