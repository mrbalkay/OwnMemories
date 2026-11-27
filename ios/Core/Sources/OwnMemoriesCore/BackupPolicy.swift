import Foundation

struct BackupSettings: Codable, Equatable {
    var wifiOnly: Bool = true
    var includeVideos: Bool = true
    var chargingOnly: Bool = false
}

enum BackupPolicy {
    enum Decision: Equatable {
        case run
        case waitForWifi
        case waitForCharging
        case offline
    }

    static func decide(online: Bool, onWifi: Bool, charging: Bool, settings: BackupSettings) -> Decision {
        if !online { return .offline }
        if settings.wifiOnly && !onWifi { return .waitForWifi }
        if settings.chargingOnly && !charging { return .waitForCharging }
        return .run
    }

    /// Bir kaynağın yedeklenip yedeklenmeyeceği (video ayarı).
    static func includes(isVideo: Bool, settings: BackupSettings) -> Bool {
        settings.includeVideos || !isVideo
    }
}
