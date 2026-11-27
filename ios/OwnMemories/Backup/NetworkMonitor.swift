import Foundation
import Network

/// Ağ durumu (çevrimiçi mi, Wi-Fi mı).
final class NetworkMonitor {
    static let shared = NetworkMonitor()
    private let monitor = NWPathMonitor()
    private let lock = NSLock()
    private var path: NWPath?
    var onChange: (() -> Void)?

    private init() {
        monitor.pathUpdateHandler = { [weak self] p in
            guard let self else { return }
            self.lock.lock()
            self.path = p
            self.lock.unlock()
            self.onChange?()
        }
        monitor.start(queue: DispatchQueue(label: "net.balkay.ownmemories.network"))
    }

    var isOnline: Bool {
        lock.lock(); defer { lock.unlock() }
        return path?.status == .satisfied
    }

    /// Hücresel değil ve "pahalı" (kişisel erişim noktası) değil. Tailscale tüneli açıkken de altta yatan bağlantıya bakılır.
    var isOnWifi: Bool {
        lock.lock(); defer { lock.unlock() }
        guard let p = path, p.status == .satisfied else { return false }
        return !p.isExpensive && !p.usesInterfaceType(.cellular)
    }
}
