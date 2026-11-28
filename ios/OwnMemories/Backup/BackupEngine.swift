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
    /// false: yedek veritabanı bu açılışta açılamadı (geçici bellek deposu kullanılıyor); yedekleme yapılmaz.
    private let storeAvailable: Bool
    private var context: ModelContext { container.mainContext }
    private var runTask: Task<Void, Never>?
    private var pumping = false
    /// Sahne etkin mi (uygulamadan bildirilir). Ön plan geçişi yalnız etkinken başlatılır.
    private var sceneActive = false
    private var observing = false
    private var debounce: Task<Void, Never>?
    private let maxAttempts = 5
    private let foregroundParallel = 4
    private let backgroundBatch = 40
    private var chunkSize: Int64 = 50 * 1024 * 1024

    private override init() {
        let opened = BackupEngine.openStore()   // (super.init öncesi: `Self` yerine tür adı)
        container = opened.container
        storeAvailable = opened.available
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
                guard let self else { done(); return }
                self.wakeHandOff(done)
            }
        }
        refreshCounts()
    }

    // MARK: - Veritabanı

    /// Yedek kayıtlarının deposu uygulamanın kendi Application Support klasöründe durur. Varsayılan
    /// yapılandırma, App Group yetkisi olduğu için depoyu paylaşılan kapsayıcıya koyuyordu; iOS, paylaşılan
    /// kapsayıcıda SQLite kilidi tutarken askıya alınan uygulamayı sonlandırır (0xdead10cc, kilit ekranında
    /// "çökme"). Widget bu depoyu okumaz.
    private static var storeURL: URL {
        let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        try? FileManager.default.createDirectory(at: base, withIntermediateDirectories: true)
        return base.appendingPathComponent("backup.store")
    }

    /// Eski konumdaki depoyu (önce App Group, yoksa uygulamanın varsayılan `default.store`'u) yeni adına taşır.
    /// Taşınamazsa eski konum döner; kayıtlar kaybolmasın.
    private static func migrateStore(to target: URL) -> URL {
        let fm = FileManager.default
        if fm.fileExists(atPath: target.path) { return target }
        let appSupport = fm.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        var candidates: [URL] = []
        if let group = fm.containerURL(forSecurityApplicationGroupIdentifier: "group.net.balkay.ownmemories") {
            candidates.append(group.appendingPathComponent("Library/Application Support/default.store"))
        }
        candidates.append(appSupport.appendingPathComponent("default.store"))
        guard let old = candidates.first(where: { fm.fileExists(atPath: $0.path) }) else { return target }
        do {
            for suffix in ["", "-wal", "-shm"] {
                let src = URL(fileURLWithPath: old.path + suffix)
                guard fm.fileExists(atPath: src.path) else { continue }
                try fm.moveItem(at: src, to: URL(fileURLWithPath: target.path + suffix))
            }
            DiagLog.add("Yedek veritabanı uygulama klasörüne taşındı")
            return target
        } catch {
            // Yarım taşınmayı geri al ve eski konumu kullan.
            for suffix in ["", "-wal", "-shm"] {
                let moved = URL(fileURLWithPath: target.path + suffix)
                let src = URL(fileURLWithPath: old.path + suffix)
                if fm.fileExists(atPath: moved.path) && !fm.fileExists(atPath: src.path) { try? fm.moveItem(at: moved, to: src) }
            }
            DiagLog.add("Yedek veritabanı taşınamadı: \(error.localizedDescription)")
            return old
        }
    }

    /// Depoyu açar; açılamazsa çökmek yerine günlüğe yazar ve geçici bir bellek deposuyla devam eder.
    private static func openStore() -> (container: ModelContainer, available: Bool) {
        let url = migrateStore(to: storeURL)
        do {
            let c = try ModelContainer(for: BackupRecord.self, configurations: ModelConfiguration(url: url))
            c.mainContext.autosaveEnabled = false   // kayıtlar açıkça kaydediliyor
            return (c, true)
        } catch {
            DiagLog.add("Yedek veritabanı açılamadı: \(error.localizedDescription)")
        }
        // Kilit açılmadan önceki arka plan açılışında dosyalar okunamaz: depoya dokunmadan bu açılışı atla.
        if UIApplication.shared.isProtectedDataAvailable {
            // Bozuk depo: kenara al (silme) ve yenisini oluştur; kayıtlar tarama ve sunucu kontrolüyle yeniden kurulur.
            let fm = FileManager.default
            let stamp = Int(Date().timeIntervalSince1970)
            for suffix in ["", "-wal", "-shm"] {
                let src = URL(fileURLWithPath: url.path + suffix)
                if fm.fileExists(atPath: src.path) { try? fm.moveItem(at: src, to: URL(fileURLWithPath: url.path + ".broken-\(stamp)" + suffix)) }
            }
            if let c = try? ModelContainer(for: BackupRecord.self, configurations: ModelConfiguration(url: url)) {
                c.mainContext.autosaveEnabled = false
                DiagLog.add("Yedek veritabanı yeniden oluşturuldu")
                return (c, true)
            }
        }
        // Son çare: bellek deposu (bu açılışta yedekleme yapılmaz).
        let memory = ModelConfiguration(isStoredInMemoryOnly: true)
        if let c = try? ModelContainer(for: BackupRecord.self, configurations: memory) {
            c.mainContext.autosaveEnabled = false
            return (c, false)
        }
        fatalError("SwiftData bellek deposu oluşturulamadı")
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
            // Arka planda kütüphane değişikliği yeni bir geçiş başlatmaz (arka plan görevi güvencesi olmadan
            // çalışırdı); değişiklikler sonraki taramada alınır.
            guard self.canRunForeground else { return }
            self.debounce?.cancel()
            self.debounce = Task {
                try? await Task.sleep(nanoseconds: 3_000_000_000)
                if !Task.isCancelled { self.start(reason: "kütüphane") }
            }
        }
    }

    // MARK: - Çalıştırma

    /// Uygulama sahne durumunu bildirir (etkin / etkin değil).
    func setSceneActive(_ active: Bool) {
        sceneActive = active
        if !active { debounce?.cancel() }
    }

    /// Ön plan geçişi yalnız uygulama gerçekten etkinken başlar: arka planda, arka plan görevi güvencesi
    /// olmadan çalışan bir geçiş iOS tarafından askıya alınır ya da sonlandırılır.
    private var canRunForeground: Bool {
        sceneActive || UIApplication.shared.applicationState == .active
    }

    /// Ön planda yedeklemeyi başlatır (zaten çalışıyorsa ya da uygulama etkin değilse bir şey yapmaz).
    func start(reason: String) {
        guard runTask == nil, canRunForeground else { return }
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
        setSceneActive(false)
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
        let started = Date()
        box.work = Task { @MainActor in
            // Durdurulan ön plan geçişinin bitmesini kısa süre bekle; yoksa `pumping` dolu kalır ve hiçbir
            // dosya arka plan oturumuna teslim edilmez.
            await self.waitForForegroundRun(upTo: 5)
            let left = budget - Date().timeIntervalSince(started)
            await backgroundHandOff(budget: max(2, left), reason: "arka plana geçiş")
            box.end()
        }
    }

    /// İptal edilen ön plan geçişinin (runOnce) bitmesini en fazla `seconds` saniye bekler.
    private func waitForForegroundRun(upTo seconds: TimeInterval) async {
        let until = Date().addingTimeInterval(seconds)
        while (isRunning || pumping) && Date() < until && !Task.isCancelled {
            try? await Task.sleep(nanoseconds: 100_000_000)
        }
    }

    /// Arka plan yüklemeleri uygulamayı uyandırdı: kendi arka plan görevi güvencesiyle kısa bir teslim yapar.
    /// Sistemin tamamlanma kapanışı (`done`) iş bitince ya da en geç sabit bir süre sonunda **mutlaka** çağrılır;
    /// geç çağrılırsa iOS uygulamayı sonlandırır ve sonraki uyandırmaları seyrekleştirir.
    private func wakeHandOff(_ done: @escaping () -> Void) {
        DiagLog.add("Arka plan yüklemeleri uygulamayı uyandırdı")
        let app = UIApplication.shared
        let box = BackgroundTaskBox()
        let state = WakeState()
        let finish: () -> Void = {
            state.timeout?.cancel()
            if state.claim() { done() }
            box.end()
        }
        box.id = app.beginBackgroundTask(withName: "om-wake") {
            box.work?.cancel()
            DiagLog.add("Uyanış süresi doldu; teslim yarıda bırakıldı")
            finish()
        }
        let remaining = app.backgroundTimeRemaining
        let hardCap = remaining.isFinite ? max(2, min(22, remaining - 4)) : 22
        let budget = max(2, min(12, hardCap - 8))
        let work = Task { @MainActor in
            await self.backgroundHandOff(budget: budget, reason: "uyanış")
            finish()
        }
        box.work = work
        state.timeout = Task { @MainActor in
            try? await Task.sleep(nanoseconds: UInt64(hardCap * 1_000_000_000))
            if Task.isCancelled { return }
            work.cancel()
            DiagLog.add("Uyanış teslimi süre sınırında kesildi")
            finish()
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
        guard storeAvailable else { status = "Yedek veritabanı açılamadı"; return nil }
        guard let client = Session.storedClient() else { status = "Giriş yapılmadı"; return nil }
        authorization = PhotoScanner.authorization
        guard authorization == .authorized || authorization == .limited else { status = "Fotoğraf erişimi gerekli"; return nil }
        return client
    }

    /// Arka plan grubu: tara, arka planda süren görevleri eşleştir, `budget` saniye içinde en fazla
    /// `backgroundBatch` küçük dosyayı teslim et.
    func backgroundHandOff(budget: TimeInterval, reason: String) async {
        guard !pumping else {
            DiagLog.add("Arka plan teslimi atlandı (\(reason)): başka bir geçiş sürüyor")
            return
        }
        guard let client = ready(), policyAllows() else {
            DiagLog.add("Arka plan teslimi atlandı (\(reason)): \(status)")
            return
        }
        let deadline = Date().addingTimeInterval(budget)
        observeLibrary()
        // Tam tarama ana iş parçacığında sürer; ön plan geçişi yakın zamanda taradıysa arka planda tekrarlanmaz.
        let lastScan = UserDefaults.standard.object(forKey: "lastScanAt") as? Date
        if lastScan.map({ Date().timeIntervalSince($0) > 300 }) ?? true { scanLibrary() }
        if Task.isCancelled || Date() > deadline { refreshCounts(); return }
        await reconcile(client: client)
        let n = await pump(client: client, target: backgroundBatch, deadline: deadline, allowLarge: false)
        refreshCounts()
        DiagLog.add("Arka plan teslimi (\(reason)): \(n) yeni dosya, sürmekte \(counts.inFlight), bekleyen \(counts.pending)")
        // Süre bittiyse cihaz raporunu atla (sonraki geçişte gönderilir).
        if Task.isCancelled || Date() > deadline { return }
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
            guard inFlight < target, let record = nextPending(includeUploading: allowLarge) else { break }
            do {
                let result = try await prepareAndSend(record, client: client, allowLarge: allowLarge)
                if result { handed += 1 }
            } catch let error where Task.isCancelled || Self.isCancellation(error) {
                // Geçiş durduruldu (arka plana geçiş, süre dolumu): öğe hatalı sayılmaz, sessizce dur.
                break
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
            // Askıya almadan dönen öğeler (ör. ertelenen büyük dosya) ana iş parçacığını kilitlemesin.
            await Task.yield()
        }
        // Döngüden `break` ile çıkıldıysa son değişiklikler (ör. lastError) de kaydedilsin.
        try? context.save()
        return handed
    }

    /// İptalden kaynaklanan hata mı (görev iptali, URLSession iptali; APIClient bunu "ulaşılamıyor" diye sarar).
    private static func isCancellation(_ error: Error) -> Bool {
        if error is CancellationError { return true }
        if (error as NSError).domain == NSURLErrorDomain && (error as NSError).code == NSURLErrorCancelled { return true }
        return false
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
    /// Tüm bekleyen kayıtları (binlerce) her öğede belleğe almamak için yalnız en yeni küçük bir grup çekilir.
    /// `includeUploading` false ise (büyük dosyaya izin olmayan arka plan teslimi) yarım kalan büyük yüklemeler
    /// atlanır; yoksa aynı kayıt her turda yeniden seçilir ve döngü süre bitene kadar boşa döner.
    private func nextPending(includeUploading: Bool) -> BackupRecord? {
        let order: (BackupRecord, BackupRecord) -> Bool = {
            if $0.localId == $1.localId { return $0.kindOrder < $1.kindOrder }
            return $0.assetDate > $1.assetDate
        }
        if includeUploading, let uploading = fetch(state: "uploading").min(by: order) { return uploading }
        let now = Date()
        let pending = "pending"
        var d = FetchDescriptor<BackupRecord>(
            predicate: #Predicate { $0.state == pending && $0.updatedAt <= now },
            sortBy: [SortDescriptor(\.assetDate, order: .reverse)]
        )
        d.fetchLimit = 24
        return ((try? context.fetch(d)) ?? []).min(by: order)
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
            var d = FetchDescriptor<BackupRecord>(predicate: #Predicate { $0.kindRaw == "original" })
            d.propertiesToFetch = [\.localId]
            return Set(((try? context.fetch(d)) ?? []).map(\.localId))
        }()
        let since = UserDefaults.standard.object(forKey: "lastScanAt") as? Date
        let scanStart = Date()
        let result = PhotoScanner.scan(known: known, modifiedSince: since, settings: settings)
        let existing: [String: BackupRecord] = {
            let refs = Array(Set(result.specs.map(\.sourceRef)))
            guard !refs.isEmpty else { return [:] }
            // SQLite değişken sınırına takılmamak için gruplar hâlinde sorgulanır; bir sorgu hata verirse tüm
            // tablo okunur (boş sonuç dönerse mevcut kayıtlar yeniden eklenip durumları sıfırlanırdı).
            var matching: [BackupRecord] = []
            var offset = 0
            while offset < refs.count {
                let part = Array(refs[offset..<min(offset + 500, refs.count)])
                offset += 500
                do {
                    matching += try context.fetch(FetchDescriptor<BackupRecord>(predicate: #Predicate { part.contains($0.sourceRef) }))
                } catch {
                    let all = (try? context.fetch(FetchDescriptor<BackupRecord>())) ?? []
                    let wanted = Set(refs)
                    matching = all.filter { wanted.contains($0.sourceRef) }
                    break
                }
            }
            return Dictionary(matching.map { ($0.sourceRef, $0) }, uniquingKeysWith: { a, _ in a })
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

/// Uyanış durumu: tamamlanma kapanışı yalnız bir kez çağrılsın; süre sınırı görevi iptal edilebilsin
/// (yalnız ana iş parçacığında kullanılır).
private final class WakeState {
    var timeout: Task<Void, Never>?
    private var used = false
    func claim() -> Bool {
        if used { return false }
        used = true
        return true
    }
}
