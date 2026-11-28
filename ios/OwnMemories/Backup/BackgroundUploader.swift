import Foundation

/// Arka plan URLSession'ı: parçalar dosyadan yüklenir; uygulama askıya alınsa da sistem yüklemeyi tamamlar.
/// Uygulama kapalıyken biten görevlerin sonucu kaybolabilir; yedekleme motoru `GET uploads/{id}` ile durumu
/// sunucudan okuyup kaldığı yerden devam eder.
final class BackgroundUploader: NSObject, URLSessionDataDelegate {
    static let shared = BackgroundUploader()
    static let identifier = "net.balkay.ownmemories.upload"

    /// Sistemin verdiği tamamlanma kapanışları (her uyandırmada bir tane; üst üste gelirse biri ezilmesin).
    private var backgroundCompletions: [() -> Void] = []
    /// Uygulama beklemeden teslim edilen (taskDescription = sourceRef) görevler bitince çağrılır.
    var onDetachedComplete: ((_ sourceRef: String, _ data: Data, _ response: URLResponse?, _ error: Error?) -> Void)?
    /// Arka plan olayları bitince (uygulama uyandırıldığında) çağrılır; işi bitince verilen kapanışı çağırmalı.
    var onEventsFinished: ((_ done: @escaping () -> Void) -> Void)?
    private var continuations: [Int: CheckedContinuation<(Data, URLResponse), Error>] = [:]
    private var buffers: [Int: Data] = [:]
    private let lock = NSLock()

    lazy var session: URLSession = {
        let c = URLSessionConfiguration.background(withIdentifier: Self.identifier)
        c.isDiscretionary = false
        c.sessionSendsLaunchEvents = true
        c.timeoutIntervalForRequest = 300
        return URLSession(configuration: c, delegate: self, delegateQueue: nil)
    }()

    /// Oturumu yeniden bağlar (uygulama arka planda uyandırıldığında).
    func reconnect() { _ = session }

    /// Uygulama uyandırıldığında sistemin verdiği tamamlanma kapanışını saklar.
    func addBackgroundCompletion(_ handler: @escaping () -> Void) {
        lock.lock()
        backgroundCompletions.append(handler)
        lock.unlock()
    }

    private func takeBackgroundCompletions() -> [() -> Void] {
        lock.lock(); defer { lock.unlock() }
        let list = backgroundCompletions
        backgroundCompletions = []
        return list
    }

    /// Dosyayı yükler ve sonucu bekler. Görev iptal edilirse yükleme de iptal edilir (sonuç, iptal hatasıyla
    /// tek bir kez temsilci üzerinden döner); böylece arka plana geçişte durdurulan geçiş gerçekten durur.
    func upload(_ request: URLRequest, fromFile file: URL) async throws -> (Data, URLResponse) {
        try Task.checkCancellation()
        let task = session.uploadTask(with: request, fromFile: file)
        let id = task.taskIdentifier
        return try await withTaskCancellationHandler {
            try await withCheckedThrowingContinuation { cont in
                lock.lock()
                continuations[id] = cont
                lock.unlock()
                task.resume()
                // İptal, devamlılık kaydedilmeden önce geldiyse onCancel görevi iptal etmemiş olabilir.
                if Task.isCancelled { task.cancel() }
            }
        } onCancel: {
            lock.lock()
            let registered = continuations[id] != nil
            lock.unlock()
            if registered { task.cancel() }
        }
    }

    /// Görevi teslim eder ve beklemez; sonuç `onDetachedComplete` ile gelir (uygulama kapalıyken bile, sonraki uyanışta).
    func enqueue(_ request: URLRequest, fromFile file: URL, sourceRef: String) {
        let task = session.uploadTask(with: request, fromFile: file)
        task.taskDescription = sourceRef
        task.resume()
    }

    /// Hâlâ sürmekte olan teslim edilmiş görevlerin sourceRef'leri.
    func inFlightRefs() async -> Set<String> {
        let tasks = await session.allTasks
        return Set(tasks.filter { $0.state == .running || $0.state == .suspended }.compactMap(\.taskDescription))
    }

    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive data: Data) {
        lock.lock()
        buffers[dataTask.taskIdentifier, default: Data()].append(data)
        lock.unlock()
    }

    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
        lock.lock()
        let cont = continuations.removeValue(forKey: task.taskIdentifier)
        let data = buffers.removeValue(forKey: task.taskIdentifier) ?? Data()
        lock.unlock()
        guard let cont else {
            if let ref = task.taskDescription { onDetachedComplete?(ref, data, task.response, error) }
            return
        }
        if let error {
            cont.resume(throwing: error)
        } else if let resp = task.response {
            cont.resume(returning: (data, resp))
        } else {
            cont.resume(throwing: URLError(.badServerResponse))
        }
    }

    func urlSessionDidFinishEvents(forBackgroundURLSession session: URLSession) {
        DispatchQueue.main.async {
            // Bu uyanışa ait kapanışlar şimdi alınır; sonra gelen bir uyanışınki kendi olay bitişinde çağrılır.
            let handlers = self.takeBackgroundCompletions()
            let finish = { handlers.forEach { $0() } }
            if let hook = self.onEventsFinished { hook(finish) } else { finish() }
        }
    }
}
