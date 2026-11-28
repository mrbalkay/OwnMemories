import Foundation
import UIKit

/// Tanılama günlüğü: arka plan olayları, yüklemeler, anı yenilemeleri (son 120 kayıt, cihazda saklanır).
enum DiagLog {
    private static let key = "diagLog"
    private static let lock = NSLock()

    struct Entry: Codable, Identifiable {
        var id: Date { date }
        let date: Date
        let text: String
    }

    static func add(_ text: String) {
        lock.lock(); defer { lock.unlock() }
        var list = load()
        list.append(Entry(date: Date(), text: text))
        if list.count > 120 { list.removeFirst(list.count - 120) }
        if let data = try? JSONEncoder().encode(list) { UserDefaults.standard.set(data, forKey: key) }
    }

    static func load() -> [Entry] {
        guard let data = UserDefaults.standard.data(forKey: key), let list = try? JSONDecoder().decode([Entry].self, from: data) else { return [] }
        return list
    }

    static func clear() { UserDefaults.standard.removeObject(forKey: key) }

    static func text() -> String {
        let f = DateFormatter()
        f.locale = Locale(identifier: "tr_TR")
        f.dateFormat = "dd.MM HH:mm:ss"
        return load().reversed().map { "\(f.string(from: $0.date))  \($0.text)" }.joined(separator: "\n")
    }
}
