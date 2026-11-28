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

    /// Günün baskın yeri: tek şehirse en sık kısa ad ("Moda, Kadıköy"), birden çok şehirse en çok iki şehir ("İzmir, Çeşme").
    static func place(for assets: [Asset]) -> String? {
        var cities: [String: Int] = [:]
        var shorts: [String: Int] = [:]
        var order: [String] = []
        for a in assets {
            guard let p = a.place else { continue }
            if cities[p.city] == nil { order.append(p.city) }
            cities[p.city, default: 0] += 1
            shorts[p.short, default: 0] += 1
        }
        guard !cities.isEmpty else { return nil }
        if cities.count == 1 {
            return shorts.sorted { $0.value != $1.value ? $0.value > $1.value : $0.key < $1.key }.first?.key
        }
        let ranked = order.enumerated().sorted { a, b in
            let (ca, cb) = (cities[a.element]!, cities[b.element]!)
            return ca != cb ? ca > cb : a.offset < b.offset
        }
        return ranked.prefix(2).map(\.element).joined(separator: ", ")
    }
}
