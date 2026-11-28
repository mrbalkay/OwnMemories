import BackgroundTasks
import Foundation

/// iOS arka plan görevleri: kısa yenileme (tarama + birkaç öğe) ve uzun işlem (ağ gerekli, ayara göre şarjda).
enum BackgroundJobs {
    static let refreshId = "net.balkay.ownmemories.refresh"
    static let backupId = "net.balkay.ownmemories.backup"

    static func register() {
        let ok1 = BGTaskScheduler.shared.register(forTaskWithIdentifier: refreshId, using: nil) { task in
            run(task, seconds: 25)
        }
        let ok2 = BGTaskScheduler.shared.register(forTaskWithIdentifier: backupId, using: nil) { task in
            run(task, seconds: nil)
        }
        if !ok1 || !ok2 { DiagLog.add("Arka plan görevleri kaydedilemedi") }
    }

    private static func run(_ task: BGTask, seconds: TimeInterval?) {
        let deadline = seconds.map { Date().addingTimeInterval($0) }
        let kind = seconds == nil ? "gece işlemi" : "yenileme"
        DiagLog.add("Arka plan görevi başladı: \(kind)")
        let work = Task { @MainActor in
            let engine = BackupEngine.shared
            if seconds == nil {
                // Uzun görev: önce büyük dosyalar (sırayla), sonra büyük bir grup teslim.
                await engine.runOnce(deadline: nil)
                await engine.backgroundHandOff(budget: 60, reason: kind)
            } else {
                await engine.backgroundHandOff(budget: 15, reason: kind)
            }
            if Task.isCancelled { return }
            if deadline == nil || deadline!.timeIntervalSinceNow > 6 {
                await MemoryService.shared.refresh()
                await MemoryNotifier.shared.reschedule()
            }
        }
        task.expirationHandler = { work.cancel(); DiagLog.add("Arka plan görevinin süresi doldu") }
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
