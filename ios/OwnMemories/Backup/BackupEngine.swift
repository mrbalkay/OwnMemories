import Foundation
import CoreLocation
import Photos
import SwiftData
import UIKit

/// Yedekleme motoru: tarar, dışa aktarır, SHA-1 ile sunucuya sorar, parçalı arka plan yüklemesi yapar.
@MainActor
final class BackupEngine: NSObject, ObservableObject, PHPhotoLibraryChangeObserver {
    static let shared = BackupEngine()

    struct Counts: Equatable {
        var total = 0, done = 0, pending = 0, failed = 0
    }

    @Published private(set) var authorization: PHAuthorizationStatus = PhotoScanner.authorization
    @Published private(set) var isRunning = false
    @Published private(set) var counts = Counts()
    @Published private(set) var status = "Hazır"
    @Published private(set) var currentName: String?
    @Published private(set) var currentProgress: Double = 0
    @Published private(set) var lastBackupAt: Date? = UserDefaults.standard.object(forKey: "lastBackupAt") as? Date
    @Published private(set) var lastError: String?
    @Published var settings: BackupSettings {
        didSet { saveSettings(); start(reason: "ayar") }
    }

    let container: ModelContainer
    private var context: ModelContext { container.mainContext }
    private var runTask: Task<Void, Never>?
    private var observing = false
    private var debounce: Task<Void, Never>?
    private let maxAttempts = 5

    private override init() {
        container = try! ModelContainer(for: BackupRecord.self)
        if let data = UserDefaults.standard.data(forKey: "backupSettings"), let s = try? JSONDecoder().decode(BackupSettings.self, from: data) {
            settings = s
        } else {
            settings = BackupSettings()
        }
        super.init()
        UIDevice.current.isBatteryMonitoringEnabled = true
        NetworkMonitor.shared.onChange = { [weak self] in
            Task { @MainActor in self?.start(reason: "ağ") }
        }
        refreshCounts()
    }

    private func saveSettings() {
        UserDefaults.standard.set(try? JSONEncoder().encode(settings), forKey: "backupSettings")
    }

    // MARK: - İzin ve kütüphane değişiklikleri

    func requestAuthorization() async {
        authorization = await PhotoScanner.requestAuthorization()
        observeLibrary()
        start(reason: "izin")
    }

    func observeLibrary() {
        authorization = PhotoScanner.authorization
        guard !observing, authorization == .authorized || authorization == .limited else { return }
        PHPhotoLibrary.shared().register(self)
        observing = true
    }

    nonisolated func photoLibraryDidChange(_ changeInstance: PHChange) {
        Task { @MainActor in
            self.debounce?.cancel()
            self.debounce = Task {
                try? await Task.sleep(nanoseconds: 3_000_000_000)
                if !Task.isCancelled { self.start(reason: "kütüphane") }
            }
        }
    }

    // MARK: - Çalıştırma

    /// Ön planda yedeklemeyi başlatır (zaten çalışıyorsa bir şey yapmaz).
    func start(reason: String) {
        guard runTask == nil else { return }
        runTask = Task { [weak self] in
            await self?.runOnce(deadline: nil)
            self?.runTask = nil
        }
    }

    func stop() {
        runTask?.cancel()
    }

    /// Bir yedekleme geçişi. `deadline` verilirse (arka plan görevi) o ana kadar çalışır.
    func runOnce(deadline: Date?) async {
        if isRunning { return }
        guard let client = Session.storedClient() else { status = "Giriş yapılmadı"; return }
        authorization = PhotoScanner.authorization
        guard authorization == .authorized || authorization == .limited else { status = "Fotoğraf erişimi gerekli"; return }
        observeLibrary()
        isRunning = true
        defer {
            isRunning = false
            currentName = nil
            currentProgress = 0
            refreshCounts()
            ResourceExporter.cleanup()
        }

        let battery = UIDevice.current.batteryState
        let charging = battery == .charging || battery == .full
        switch BackupPolicy.decide(online: NetworkMonitor.shared.isOnline, onWifi: NetworkMonitor.shared.isOnWifi, charging: charging, settings: settings) {
        case .offline: status = "İnternet bağlantısı yok"; return
        case .waitForWifi: status = "Wi-Fi bekleniyor"; return
        case .waitForCharging: status = "Şarj bekleniyor"; return
        case .run: break
        }

        status = "Fotoğraflar taranıyor…"
        scanLibrary()
        refreshCounts()

        var fatal: String?
        var progressed = false
        let queue = workQueue()
        for record in queue {
            if Task.isCancelled { break }
            if let deadline, Date() > deadline { break }
            status = "Yedekleniyor…"
            do {
                try await process(record, client: client)
                progressed = true
                refreshCounts()
            } catch let e as APIError where e.stopsBackup {
                fatal = e.localizedDescription
                record.lastError = fatal
                break
            } catch {
                record.attempts += 1
                record.lastError = error.localizedDescription
                record.state = record.attempts >= maxAttempts ? "failed" : "pending"
                record.updatedAt = Date()
            }
            try? context.save()
        }
        try? context.save()
        refreshCounts()
        lastError = fatal
        if fatal == nil && !Task.isCancelled {
            if progressed || counts.pending == 0 {
                lastBackupAt = Date()
                UserDefaults.standard.set(lastBackupAt, forKey: "lastBackupAt")
            }
            status = counts.pending == 0 ? "Tümü yedeklendi" : "Kısmen yedeklendi"
        } else if let fatal {
            status = fatal
        }
        await reportDevice(client: client)
    }

    private func workQueue() -> [BackupRecord] {
        let d = FetchDescriptor<BackupRecord>(predicate: #Predicate { $0.state == "pending" || $0.state == "uploading" })
        let items = (try? context.fetch(d)) ?? []
        return items.sorted {
            if ($0.state == "uploading") != ($1.state == "uploading") { return $0.state == "uploading" }
            if $0.localId == $1.localId { return $0.kindOrder < $1.kindOrder }
            return $0.assetDate > $1.assetDate
        }
    }

    private func scanLibrary() {
        let known: Set<String> = {
            let d = FetchDescriptor<BackupRecord>(predicate: #Predicate { $0.kindRaw == "original" })
            return Set(((try? context.fetch(d)) ?? []).map(\.localId))
        }()
        let since = UserDefaults.standard.object(forKey: "lastScanAt") as? Date
        let scanStart = Date()
        let result = PhotoScanner.scan(known: known, modifiedSince: since, settings: settings)
        let existing: [String: BackupRecord] = {
            let refs = Set(result.specs.map(\.sourceRef))
            guard !refs.isEmpty else { return [:] }
            let all = (try? context.fetch(FetchDescriptor<BackupRecord>())) ?? []
            return Dictionary(all.filter { refs.contains($0.sourceRef) }.map { ($0.sourceRef, $0) }, uniquingKeysWith: { a, _ in a })
        }()
        for spec in result.specs {
            if let rec = existing[spec.sourceRef] {
                // Sonradan düzenlenmiş: yeni hali yüklenecek (sunucu eskisini çöpe taşır).
                if spec.kind == .edited && result.changedEdits.contains(spec.localId) && rec.state == "done" {
                    rec.state = "pending"; rec.sha1 = nil; rec.uploadId = nil; rec.attempts = 0; rec.updatedAt = Date()
                }
                continue
            }
            context.insert(BackupRecord(sourceRef: spec.sourceRef, localId: spec.localId, kind: spec.kind, isVideo: spec.isVideo, assetDate: spec.assetDate))
        }
        // Başarısızları belirli aralıklarla yeniden dene.
        let failed = (try? context.fetch(FetchDescriptor<BackupRecord>(predicate: #Predicate { $0.state == "failed" }))) ?? []
        for r in failed where r.updatedAt < Date().addingTimeInterval(-6 * 3600) {
            r.state = "pending"; r.attempts = 0
        }
        try? context.save()
        UserDefaults.standard.set(scanStart, forKey: "lastScanAt")
    }

    // MARK: - Tek kaynak

    private func process(_ record: BackupRecord, client: APIClient) async throws {
        currentProgress = 0
        let exported = try await ResourceExporter.export(localId: record.localId, kind: record.kind)
        defer { try? FileManager.default.removeItem(at: exported.url) }
        currentName = exported.filename
        record.fileSize = exported.size
        if record.sha1 == nil {
            let url = exported.url
            record.sha1 = try await Task.detached(priority: .utility) { try FileHash.sha1Hex(of: url) }.value
        }
        guard let sha1 = record.sha1 else { return }

        var received: Int64 = 0
        var chunkSize: Int64 = 50 * 1024 * 1024
        if let uploadId = record.uploadId {
            do {
                let r: UploadReceived = try await client.send("GET", "uploads/\(uploadId)")
                received = r.received
            } catch let APIError.server(status, _, _, _) where status == 404 {
                record.uploadId = nil
            }
        }
        if record.uploadId == nil {
            struct CheckBody: Encodable { let sha1: [String] }
            let check: CheckResponse = try await client.send("POST", "assets/check", body: CheckBody(sha1: [sha1]))
            if !check.missing.contains(sha1) {
                finish(record, assetId: nil)
                return
            }
            let start: UploadStart = try await client.send("POST", "uploads", body: UploadStartRequest(filename: exported.filename, size: exported.size, sha1: sha1, meta: meta(for: record)))
            record.uploadId = start.id
            record.state = "uploading"
            chunkSize = start.chunkSize
            received = start.received
            try? context.save()
        }
        guard let uploadId = record.uploadId else { return }

        var ranges = ChunkPlan.ranges(size: exported.size, chunk: chunkSize, from: received)
        while let range = ranges.first {
            if Task.isCancelled { return }
            let single = range.lowerBound == 0 && range.upperBound == exported.size
            let file = try (single ? exported.url : ResourceExporter.writeChunk(of: exported.url, range: range))
            defer { if !single { try? FileManager.default.removeItem(at: file) } }
            var req = client.authorized(client.url("uploads/\(uploadId)", query: ["offset": String(range.lowerBound)]), method: "PUT")
            req.setValue("application/octet-stream", forHTTPHeaderField: "Content-Type")
            req.allowsCellularAccess = !settings.wifiOnly
            req.timeoutInterval = 600
            let result: (Data, URLResponse)
            do {
                result = try await BackgroundUploader.shared.upload(req, fromFile: file)
            } catch {
                throw APIError.unreachable(error.localizedDescription)
            }
            do {
                let r: UploadReceived = try APIClient.decode(data: result.0, response: result.1)
                received = r.received
            } catch let APIError.server(status, code, _, serverReceived) where status == 409 && code == "offset_mismatch" {
                received = serverReceived ?? received
            }
            currentProgress = exported.size > 0 ? Double(received) / Double(exported.size) : 1
            ranges = ChunkPlan.ranges(size: exported.size, chunk: chunkSize, from: received)
        }
        let done: UploadComplete = try await client.send("POST", "uploads/\(uploadId)/complete")
        finish(record, assetId: done.asset?.id)
    }

    private func finish(_ record: BackupRecord, assetId: String?) {
        record.state = "done"
        record.assetId = assetId
        record.uploadId = nil
        record.lastError = nil
        record.updatedAt = Date()
        currentProgress = 1
        try? context.save()
    }

    private func meta(for record: BackupRecord) -> UploadMeta {
        let asset = PhotoScanner.asset(record.localId)
        let date = asset?.creationDate ?? record.assetDate
        let original = SourceRef.make(localId: record.localId, kind: .original)
        return UploadMeta(
            takenAt: ServerDate.format(date),
            tzOffset: ServerDate.offsetMinutes(at: date),
            latitude: asset?.location?.coordinate.latitude,
            longitude: asset?.location?.coordinate.longitude,
            isFavorite: record.kind == .live ? nil : asset?.isFavorite,
            sourceRef: record.sourceRef,
            livePairSourceRef: record.kind == .live ? original : nil,
            editedOfSourceRef: record.kind == .edited ? original : nil
        )
    }

    // MARK: - Durum

    func refreshCounts() {
        func count(_ state: String) -> Int {
            (try? context.fetchCount(FetchDescriptor<BackupRecord>(predicate: #Predicate { $0.state == state }))) ?? 0
        }
        let done = count("done"), failed = count("failed")
        let pending = count("pending") + count("uploading")
        counts = Counts(total: done + failed + pending, done: done, pending: pending, failed: failed)
    }

    func retryFailed() {
        let failed = (try? context.fetch(FetchDescriptor<BackupRecord>(predicate: #Predicate { $0.state == "failed" }))) ?? []
        for r in failed { r.state = "pending"; r.attempts = 0 }
        try? context.save()
        refreshCounts()
        start(reason: "yeniden dene")
    }

    /// Çıkışta yerel kayıtları temizler (başka bir sunucuya bağlanılırsa her şey yeniden kontrol edilir).
    func resetAll() {
        stop()
        try? context.delete(model: BackupRecord.self)
        try? context.save()
        UserDefaults.standard.removeObject(forKey: "lastScanAt")
        UserDefaults.standard.removeObject(forKey: "lastBackupAt")
        lastBackupAt = nil
        refreshCounts()
    }

    func failedRecords() -> [BackupRecord] {
        (try? context.fetch(FetchDescriptor<BackupRecord>(predicate: #Predicate { $0.state == "failed" }))) ?? []
    }

    private func reportDevice(client: APIClient) async {
        let report = DeviceReport(
            deviceId: DeviceInfo.deviceId, name: DeviceInfo.name, model: DeviceInfo.model, osVersion: DeviceInfo.osVersion,
            appVersion: DeviceInfo.appVersion, pending: counts.pending, uploadedTotal: counts.done,
            lastBackupAt: lastBackupAt.map(ServerDate.format), lastError: lastError
        )
        _ = try? await client.send("POST", "devices/report", body: report, as: OkResponse.self)
    }
}
