import Foundation

struct DaySection: Identifiable, Equatable {
    let id: String   // "2019-07-01"
    var assets: [Asset]
}

enum DayGrouping {
    /// Sıralı asset listesini (en yeni önce) yerel güne göre bölümlere ayırır; sıra korunur.
    static func group(_ assets: [Asset]) -> [DaySection] {
        var out: [DaySection] = []
        for a in assets {
            if let last = out.indices.last, out[last].id == a.dayKey {
                out[last].assets.append(a)
            } else {
                out.append(DaySection(id: a.dayKey, assets: [a]))
            }
        }
        return out
    }

    /// "1 Temmuz 2019 Pazartesi" — yıl bu yılsa gösterilmez.
    static func title(for key: String, now: Date = Date(), locale: Locale = Locale(identifier: "tr_TR")) -> String {
        let p = key.split(separator: "-").compactMap { Int($0) }
        guard p.count == 3 else { return key }
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = TimeZone(identifier: "UTC")!
        guard let date = cal.date(from: DateComponents(year: p[0], month: p[1], day: p[2], hour: 12)) else { return key }
        let thisYear = Calendar.current.component(.year, from: now)
        let f = DateFormatter()
        f.locale = locale
        f.timeZone = cal.timeZone
        f.setLocalizedDateFormatFromTemplate(p[0] == thisYear ? "dMMMMEEEE" : "dMMMMyyyyEEEE")
        return f.string(from: date)
    }
}
