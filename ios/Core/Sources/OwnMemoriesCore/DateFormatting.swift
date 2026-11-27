import Foundation

enum ServerDate {
    private static let formatter: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f
    }()

    /// Sunucuya gönderilecek UTC zaman: "2026-01-01T10:00:00Z"
    static func format(_ date: Date) -> String {
        formatter.string(from: date)
    }

    static func parse(_ s: String) -> Date? {
        formatter.date(from: s)
    }

    /// Verilen saat diliminin o andaki ofseti (dakika).
    static func offsetMinutes(at date: Date, in tz: TimeZone = .current) -> Int {
        tz.secondsFromGMT(for: date) / 60
    }

    /// "2019-07-01T15:04:05+03:00" biçimindeki yerel zamanın tarih/saat kısmını, çekildiği yerin saatiyle gösterir.
    static func localComponents(_ takenLocal: String) -> DateComponents? {
        let parts = takenLocal.prefix(19).split(whereSeparator: { "-T:".contains($0) }).compactMap { Int($0) }
        guard parts.count == 6 else { return nil }
        return DateComponents(year: parts[0], month: parts[1], day: parts[2], hour: parts[3], minute: parts[4], second: parts[5])
    }
}
