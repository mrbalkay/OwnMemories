import CoreLocation
import Foundation
import Photos
import SwiftData
import UIKit

/// UIApplication arka plan görev kimliği için kutu (kapanışlarda güvenle değiştirilebilsin).
final class BackgroundTaskBox {
    var id: UIBackgroundTaskIdentifier = .invalid
    /// Süre dolunca iptal edilecek iş.
    var work: Task<Void, Never>?
    private let lock = NSLock()

    /// Görevi bitirir. Süre dolum kapanışından (ana iş parçacığı) çağrıldığında **hemen** bitirmelidir:
    /// iOS, kapanış dönene kadar görev bitmezse uygulamayı sonlandırır (kilit ekranında "çökme").
    func end() {
        lock.lock()
        let current = id
        id = .invalid
        lock.unlock()
        guard current != .invalid else { return }
        if Thread.isMainThread {
            UIApplication.shared.endBackgroundTask(current)
        } else {
            DispatchQueue.main.async { UIApplication.shared.endBackgroundTask(current) }
        }
    }
}

struct UploadFailed: LocalizedError {
    let message: String
    var errorDescription: String? { message }
}

/// Sunucunun parça yanıtı (`complete=1` ise son parçada tamamlanma sonucu da gelir).
private struct ChunkResponse: Decodable {
    let received: Int64
    let complete: UploadComplete?
}

/// Yedekleme motoru.
///
/// Küçük dosyalar (≤ parça boyutu, fotoğrafların neredeyse tamamı) arka plan URLSession'ına **toplu ve
/// beklemeden** teslim edilir: iOS onları uygulama askıdayken/kapalıyken de yükler, sunucu son parçada
/// yüklemeyi kendiliğinden tamamlar (`complete=1`). Uygulama açıkken aynı anda birkaç dosya yüklenir;
/// arka plana geçerken ve iOS uygulamayı her uyandırdığında (yükleme bitti, arka plan görevi) sıradaki
/// grup teslim edilir. Büyük videolar ön planda veya gece görevinde parça parça yüklenir.
@MainActor
final class BackupEngine: NSObject, ObservableObject, PHPhotoLibraryChangeObserver {
    static let shared = BackupEngine()

    struct Counts: Equatable {
        var total = 0, done = 0, pending = 0, failed = 0, inFlight = 0
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
    private var pumping = false
    private var observing = false
    private var debounce: Task<Void, Never>?
    private let maxAttempts = 5
    private let foregroundParallel = 4
    private let backgroundBatch = 40
    private var chunkSize: Int64 = 50 * 1024 * 1024

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
        BackgroundUploader.shared.onDetachedComplete = { [weak self] ref, data, response, error in
            Task { @MainActor in self?.detachedFinished(ref: ref, data: data, response: response, error: error) }
        }
        BackgroundUploader.shared.onEventsFinished = { [weak self] done in
            Task { @MainActor in
                DiagLog.add("Arka plan yüklemeleri uygulamayı uyandırdı")
                await self?.backgroundHandOff(budget: 20, reason: "uyanış")
                done()
            }
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

    /// Uygulama arka plana geçerken: ön plan işini durdur, büyük bir grubu arka plan oturumuna teslim et.
    func enterBackground() {
        stop()
        let app = UIApplication.shared
        let box = BackgroundTaskBox()
        box.id = app.beginBackgroundTask(withName: "om-handoff") {
            // Süre doldu: işi durdur ve görevi bu kapanış içinde bitir.
            box.work?.cancel()
            DiagLog.add("Arka plana geçiş süresi doldu; teslim yarıda bırakıldı")
            box.end()
        }
        guard box.id != .invalid else { return }
        // iOS'un verdiği süreden pay bırak (genelde ~30 sn; kalan süre daha azsa ona göre).
        let remaining = app.backgroundTimeRemaining
        let budget = remaining.isFinite ? max(3, min(20, remaining - 8)) : 20
        box.work = Task { @MainActor in
            await backgroundHandOff(budget: budget, reason: "arka plana geçiş")
            box.end()
        }
    }

    private func policyAllows() -> Bool {
        let battery = UIDevice.current.batteryState
        let charging = battery == .charging || battery == .full
        switch BackupPolicy.decide(online: NetworkMonitor.shared.isOnline, onWifi: NetworkMonitor.shared.isOnWifi, charging: charging, settings: settings) {
        case .offline: status = "İnternet bağlantısı yok"; return false
        case .waitForWifi: status = "Wi-Fi bekleniyor"; return false
        case .waitForCharging: status = "Şarj bekleniyor"; return false
        case .run: return true
        }
    }

    private func ready() -> APIClient? {
        guard let client = Session.storedClient() else { status = "Giriş yapılmadı"; return nil }
        authorization = PhotoScanner.authorization
        guard authorization == .authorized || authorization == .limited else { status = "Fotoğraf erişimi gerekli"; return nil }
        return client
    }

    /// Arka plan grubu: tara, arka planda süren görevleri eşleştir, `budget` saniye içinde en fazla
    /// `backgroundBatch` küçük dosyayı teslim et.
    func backgroundHandOff(budget: TimeInterval, reason: String) async {
        guard let client = ready(), policyAllows() else {
            DiagLog.add("Arka plan teslimi atlandı (\(reason)): \(status)")
            return
        }
        let deadline = Date().addingTimeInterval(budget)
        observeLibrary()
        scanLibrary()
        await reconcile(client: client)
        let n = await pump(client: client, target: backgroundBatch, deadline: deadline, allowLarge: false)
        refreshCounts()
        DiagLog.add("Arka plan teslimi (\(reason)): \(n) yeni dosya, sürmekte \(counts.inFlight), bekleyen \(counts.pending)")
        await reportDevice(client: client)
    }

    /// Bir yedekleme geçişi (ön plan veya gece görevi). `deadline` verilirse o ana kadar çalışır.
    func runOnce(deadline: Date?) async {
        if isRunning { return }
        guard let client = ready() else { return }
        observeLibrary()
        isRunning = true
        lastError = nil
        defer {
            isRunning = false
            currentName = nil
            currentProgress = 0
            refreshCounts()
            ResourceExporter.cleanup()
        }
        guard policyAllows() else { return }

        status = "Fotoğraflar taranıyor…"
        scanLibrary()
        await reconcile(client: client)
        refreshCounts()
        status = "Yedekleniyor…"

        // Küçük dosyalar paralel (arka plan oturumu), büyükler burada sırayla.
        _ = await pump(client: client, target: foregroundParallel, deadline: deadline, allowLarge: true)
        if let fatal = lastError {
            status = fatal
        } else if !Task.isCancelled {
            status = counts.pending + counts.inFlight == 0 ? "Tümü yedeklendi" : "Yedekleniyor…"
        }
        await reportDevice(client: client)
    }

    /// Sıradaki kayıtları işler: küçük dosyaları arka plan oturumuna teslim eder (en fazla `target` sürmekte
    /// olacak kadar); `allowLarge` ise büyük dosyaları parça parça kendisi yükler. Teslim edilen sayısı döner.
    @discardableResult
    private func pump(client: APIClient, target: Int, deadline: Date?, allowLarge: Bool) async -> Int {
        if pumping { return 0 }
        pumping = true
        defer { pumping = false }
        var handed = 0
        while !Task.isCancelled {
            if let deadline, Date() > deadline { break }
            let inFlight = countState("queued")
            guard inFlight < target, let record = nextPending() else { break }
            do {
                let result = try await prepareAndSend(record, client: client, allowLarge: allowLarge)
                if result { handed += 1 }
            } catch let e as APIError where e.stopsBackup {
                lastError = e.localizedDescription
                record.lastError = lastError
                DiagLog.add("Yedekleme durdu: \(lastError ?? "")")
                break
            } catch {
                markFailed(record, error.localizedDescription)
            }
            try? context.save()
            refreshCounts()
        }
        return handed
    }

    /// Bir kaydı hazırlar. Sunucuda varsa tamamlar; küçükse teslim eder (true); büyükse ve izin varsa
    /// parça parça yükler, izin yoksa sonraya bırakır.
    private func prepareAndSend(_ record: BackupRecord, client: APIClient, allowLarge: Bool) async throws -> Bool {
        if !allowLarge && record.fileSize > chunkSize {
            record.updatedAt = Date().addingTimeInterval(60)   // büyük dosya: ön plana/gece görevine kalsın
            return false
        }
        let exported = try await ResourceExporter.export(localId: record.localId, kind: record.kind, into: ResourceExporter.stagedDir)
        var keepFile = false
        defer { if !keepFile { try? FileManager.default.removeItem(at: exported.url) } }
        record.fileSize = exported.size
        if record.sha1 == nil {
            let url = exported.url
            record.sha1 = try await Task.detached(priority: .utility) { try FileHash.sha1Hex(of: url) }.value
        }
        guard let sha1 = record.sha1 else { return false }

        struct CheckBody: Encodable { let sha1: [String] }
        let check: CheckResponse = try await client.send("POST", "assets/check", body: CheckBody(sha1: [sha1]))
        if !check.missing.contains(sha1) {
            finish(record, assetId: nil)
            return false
        }
        if exported.size > chunkSize {
            guard allowLarge else {
                record.state = "pending"   // gece görevine/ön plana kalsın
                record.lastError = nil
                record.updatedAt = Date().addingTimeInterval(60)
                return false
            }
            currentName = exported.filename
            try await uploadLarge(record, exported: exported, sha1: sha1, client: client)
            return false
        }
        // Küçük dosya: oturumu aç, tek PUT ile teslim et, sunucu son parçada tamamlasın.
        let start: UploadStart = try await client.send("POST", "uploads", body: UploadStartRequest(filename: exported.filename, size: exported.size, sha1: sha1, meta: meta(for: record)))
        chunkSize = start.chunkSize
        var req = client.authorized(client.url("uploads/\(start.id)", query: ["offset": "0", "complete": "1"]), method: "PUT")
        req.setValue("application/octet-stream", forHTTPHeaderField: "Content-Type")
        req.allowsCellularAccess = !settings.wifiOnly
        req.timeoutInterval = 600
        BackgroundUploader.shared.enqueue(req, fromFile: exported.url, sourceRef: record.sourceRef)
        record.uploadId = start.id
        record.stagedPath = exported.url.path
        record.state = "queued"
        record.updatedAt = Date()
        keepFile = true
        return true
    }

    /// Büyük dosyayı parça parça (ön planda) yükler; kaldığı yerden devam eder.
    private func uploadLarge(_ record: BackupRecord, exported: ResourceExporter.Exported, sha1: String, client: APIClient) async throws {
        var received: Int64 = 0
        if let uploadId = record.uploadId {
            do {
                let r: UploadReceived = try await client.send("GET", "uploads/\(uploadId)")
                received = r.received
            } catch let APIError.server(status, _, _, _) where status == 404 {
                record.uploadId = nil
            }
        }
        if record.uploadId == nil {
            let start: UploadStart = try await client.send("POST", "uploads", body: UploadStartRequest(filename: exported.filename, size: exported.size, sha1: sha1, meta: meta(for: record)))
            record.uploadId = start.id
            chunkSize = start.chunkSize
            received = start.received
        }
        record.state = "uploading"
        try? context.save()
        guard let uploadId = record.uploadId else { return }
        var ranges = ChunkPlan.ranges(size: exported.size, chunk: chunkSize, from: received)
        while let range = ranges.first {
            if Task.isCancelled { return }
            let single = range.lowerBound == 0 && range.upperBound == exported.size
            let file = try (single ? exported.url : ResourceExporter.writeChunk(of: exported.url, range: range))
            defer { if !single { try? FileManager.default.removeItem(at: file) } }
            var req = client.authorized(client.url("uploads/\(uploadId)", query: ["offset": String(range.lowerBound), "complete": "1"]), method: "PUT")
            req.setValue("application/octet-stream", forHTTPHeaderField: "Content-Type")
            req.allowsCellularAccess = !settings.wifiOnly
            req.timeoutInterval = 900
            let result = try await uploadWithRetry(req, file: file, client: client)
            do {
                let r: ChunkResponse = try APIClient.decode(data: result.0, response: result.1)
                received = r.received
                if let done = r.complete {
                    finish(record, assetId: done.asset?.id)
                    return
                }
            } catch let APIError.server(status, code, _, serverReceived) where status == 409 && code == "offset_mismatch" {
                received = serverReceived ?? received
            }
            currentProgress = exported.size > 0 ? Double(received) / Double(exported.size) : 1
            ranges = ChunkPlan.ranges(size: exported.size, chunk: chunkSize, from: received)
        }
        let done: UploadComplete = try await client.send("POST", "uploads/\(uploadId)/complete")
        finish(record, assetId: done.asset?.id)
    }

    /// Parçayı yükler; geçici hatalarda 3 kez yeniden dener. Sunucuya ulaşılabiliyorsa yalnız bu öğe hatalı sayılır.
    private func uploadWithRetry(_ req: URLRequest, file: URL, client: APIClient) async throws -> (Data, URLResponse) {
        var lastErr: Error?
        for attempt in 0..<3 {
            if attempt > 0 { try? await Task.sleep(nanoseconds: UInt64(attempt) * 3_000_000_000) }
            if Task.isCancelled { throw CancellationError() }
            do {
                return try await BackgroundUploader.shared.upload(req, fromFile: file)
            } catch {
                lastErr = error
            }
        }
        struct Ping: Decodable { let ok: Bool }
        if (try? await client.send("GET", "ping", as: Ping.self)) != nil {
            throw UploadFailed(message: "Yükleme başarısız: \(lastErr?.localizedDescription ?? "bilinmeyen hata")")
        }
        throw APIError.unreachable(lastErr?.localizedDescription ?? "")
    }

    // MARK: - Arka plan görev sonuçları

    /// Teslim edilmiş bir görev bitti (uygulama açıkken, askıdayken ya da yeniden başlatıldıktan sonra).
    private func detachedFinished(ref: String, data: Data, response: URLResponse?, error: Error?) {
        guard let record = record(for: ref) else { return }
        if let path = record.stagedPath { try? FileManager.default.removeItem(atPath: path) }
        record.stagedPath = nil
        if let error {
            record.state = "pending"
            record.lastError = error.localizedDescription
            record.updatedAt = Date()
            if (error as NSError).code == NSURLErrorCancelled {
                // Uygulama kaydırılarak kapatılınca iOS arka plan yüklemelerini iptal eder; dosya kuyruğa döner.
                DiagLog.add("Arka plan yüklemesi iptal edildi (uygulama kapatılmış olabilir); yeniden denenecek")
            } else {
                DiagLog.add("Arka plan yüklemesi hata: \(error.localizedDescription)")
            }
        } else if let response, let r: ChunkResponse = try? APIClient.decode(data: data, response: response), let done = r.complete {
            finish(record, assetId: done.asset?.id)
            lastBackupAt = Date()
            UserDefaults.standard.set(lastBackupAt, forKey: "lastBackupAt")
        } else {
            // Yanıt okunamadı: durum bir sonraki eşleştirmede sunucudan öğrenilir.
            record.state = "pending"
            record.updatedAt = Date()
        }
        try? context.save()
        refreshCounts()
        // Uygulama açıksa boşalan yere yenisini koy.
        if UIApplication.shared.applicationState == .active, let client = Session.storedClient(), !pumping {
            Task { await self.pump(client: client, target: self.foregroundParallel, deadline: nil, allowLarge: false); self.refreshCounts() }
        }
    }

    /// "queued" kayıtları gerçek durumla eşleştirir: hâlâ süren görevler bırakılır; bitmiş olanlar sunucuya
    /// sorulur (SHA-1 sunucudaysa tamamlandı, değilse yeniden kuyruğa).
    private func reconcile(client: APIClient) async {
        let queued = fetch(state: "queued")
        guard !queued.isEmpty else { return }
        let live = await BackgroundUploader.shared.inFlightRefs()
        let stale = queued.filter { !live.contains($0.sourceRef) }
        guard !stale.isEmpty else { return }
        let shas = stale.compactMap(\.sha1)
        struct CheckBody: Encodable { let sha1: [String] }
        var missing = Set<String>()
        if !shas.isEmpty {
            guard let r: CheckResponse = try? await client.send("POST", "assets/check", body: CheckBody(sha1: Array(shas.prefix(500)))) else { return }
            missing = Set(r.missing)
        }
        for rec in stale {
            if let path = rec.stagedPath { try? FileManager.default.removeItem(atPath: path) }
            rec.stagedPath = nil
            if let sha = rec.sha1, !missing.contains(sha) {
                finish(rec, assetId: rec.assetId)
            } else {
                rec.state = "pending"
                rec.uploadId = nil
            }
        }
        try? context.save()
    }

    // MARK: - Kayıtlar

    private func fetch(state: String) -> [BackupRecord] {
        (try? context.fetch(FetchDescriptor<BackupRecord>(predicate: #Predicate { $0.state == state }))) ?? []
    }

    private func countState(_ state: String) -> Int {
        (try? context.fetchCount(FetchDescriptor<BackupRecord>(predicate: #Predicate { $0.state == state }))) ?? 0
    }

    private func record(for ref: String) -> BackupRecord? {
        var d = FetchDescriptor<BackupRecord>(predicate: #Predicate { $0.sourceRef == ref })
        d.fetchLimit = 1
        return try? context.fetch(d).first
    }

    /// Sıradaki kayıt: önce yarım kalan büyük yüklemeler, sonra yeni çekilenler; aynı varlıkta orijinal önce.
    private func nextPending() -> BackupRecord? {
        let now = Date()
        let items = fetch(state: "pending").filter { $0.updatedAt <= now } + fetch(state: "uploading")
        return items.min {
            if ($0.state == "uploading") != ($1.state == "uploading") { return $0.state == "uploading" }
            if $0.localId == $1.localId { return $0.kindOrder < $1.kindOrder }
            return $0.assetDate > $1.assetDate
        }
    }

    private func markFailed(_ record: BackupRecord, _ message: String) {
        record.attempts += 1
        record.lastError = message
        record.state = record.attempts >= maxAttempts ? "failed" : "pending"
        record.updatedAt = Date().addingTimeInterval(TimeInterval(30 * record.attempts))
        DiagLog.add("Öğe hatası (\(record.attempts). deneme): \(message)")
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
                if spec.kind == .edited && result.changedEdits.contains(spec.localId) && rec.state == "done" {
                    rec.state = "pending"; rec.sha1 = nil; rec.uploadId = nil; rec.attempts = 0; rec.updatedAt = Date()
                }
                continue
            }
            context.insert(BackupRecord(sourceRef: spec.sourceRef, localId: spec.localId, kind: spec.kind, isVideo: spec.isVideo, assetDate: spec.assetDate))
        }
        for r in fetch(state: "failed") where r.updatedAt < Date().addingTimeInterval(-6 * 3600) {
            r.state = "pending"; r.attempts = 0
        }
        try? context.save()
        UserDefaults.standard.set(scanStart, forKey: "lastScanAt")
    }

    private func finish(_ record: BackupRecord, assetId: String?) {
        record.state = "done"
        record.assetId = assetId
        record.uploadId = nil
        record.lastError = nil
        record.updatedAt = Date()
        if let path = record.stagedPath { try? FileManager.default.removeItem(atPath: path) }
        record.stagedPath = nil
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
        let done = countState("done"), failed = countState("failed"), queued = countState("queued")
        let pending = countState("pending") + countState("uploading")
        counts = Counts(total: done + failed + pending + queued, done: done, pending: pending, failed: failed, inFlight: queued)
        if !isRunning && queued > 0 && lastError == nil { status = "Yükleniyor (\(queued) dosya)" }
        if !isRunning && queued == 0 && pending == 0 && done > 0 && lastError == nil { status = "Tümü yedeklendi" }
    }

    func retryFailed() {
        for r in fetch(state: "failed") { r.state = "pending"; r.attempts = 0; r.updatedAt = Date() }
        try? context.save()
        refreshCounts()
        start(reason: "yeniden dene")
    }

    /// Çıkışta yerel kayıtları temizler.
    func resetAll() {
        stop()
        try? context.delete(model: BackupRecord.self)
        try? context.save()
        try? FileManager.default.removeItem(at: ResourceExporter.stagedDir)
        UserDefaults.standard.removeObject(forKey: "lastScanAt")
        UserDefaults.standard.removeObject(forKey: "lastBackupAt")
        lastBackupAt = nil
        refreshCounts()
    }

    func failedRecords() -> [BackupRecord] { fetch(state: "failed") }

    private func reportDevice(client: APIClient) async {
        if counts.pending + counts.inFlight == 0 && counts.done > 0 {
            lastBackupAt = Date()
            UserDefaults.standard.set(lastBackupAt, forKey: "lastBackupAt")
        }
        let report = DeviceReport(
            deviceId: DeviceInfo.deviceId, name: DeviceInfo.name, model: DeviceInfo.model, osVersion: DeviceInfo.osVersion,
            appVersion: DeviceInfo.appVersion, pending: counts.pending + counts.inFlight, uploadedTotal: counts.done,
            lastBackupAt: lastBackupAt.map(ServerDate.format), lastError: lastError
        )
        _ = try? await client.send("POST", "devices/report", body: report, as: OkResponse.self)
    }
}
