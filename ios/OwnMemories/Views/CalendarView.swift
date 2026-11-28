import SwiftUI

/// GET /v1/assets/summary → ay başına öğe sayısı.
struct MonthSummary: Decodable {
    struct Month: Decodable { let month: String; let count: Int }
    let months: [Month]
}

/// GET /v1/assets/days?month=YYYY-MM → fotoğraflı günler (yerel gün), sayıları ve o güne atlama imleci.
struct DaysResponse: Decodable {
    let month: String
    let days: [DayCount]
}

struct DayCount: Decodable, Identifiable, Equatable {
    let day: String          // "2026-11-28"
    let count: Int
    let coverAssetId: String?
    let before: String       // bu günün en yeni öğesini listenin başına getiren imleç
    var id: String { day }
    var dayNumber: Int { Int(day.suffix(2)) ?? 0 }

    enum CodingKeys: String, CodingKey {
        case day, count, before
        case coverAssetId = "cover_asset_id"
    }
}

/// Kitaplık takvimi: ay ızgarası (pazartesiyle başlar), fotoğraflı günlerde kapak ve sayı rozeti.
/// Oklar yalnızca fotoğraf olan aylar arasında gezer; bir güne dokununca `onPick` çağrılır.
struct CalendarSheet: View {
    var initialMonth: String?
    var onPick: (DayCount) -> Void
    @EnvironmentObject private var session: Session
    @Environment(\.theme) private var theme
    @Environment(\.dismiss) private var dismiss

    @State private var months: [MonthSummary.Month] = []   // en yeniden eskiye
    @State private var month: String = ""
    @State private var cache: [String: [DayCount]] = [:]
    @State private var loadingMonth = false
    @State private var failed = false

    private static let weekdays = ["Pzt", "Sal", "Çar", "Per", "Cum", "Cmt", "Paz"]
    private let columns = Array(repeating: GridItem(.flexible(), spacing: 6), count: 7)

    private var index: Int? { months.firstIndex { $0.month == month } }
    private var newer: String? { index.flatMap { $0 > 0 ? months[$0 - 1].month : nil } }
    private var older: String? { index.flatMap { $0 + 1 < months.count ? months[$0 + 1].month : nil } }
    private var days: [DayCount] { cache[month] ?? [] }

    var body: some View {
        VStack(spacing: 14) {
            header
            HStack(spacing: 6) {
                ForEach(Self.weekdays, id: \.self) { w in
                    Text(w.uppercased(with: Locale(identifier: "tr_TR")))
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundStyle(theme.muted)
                        .frame(maxWidth: .infinity)
                }
            }
            grid
            if failed {
                Text("Takvim yüklenemedi. Sunucuya ulaşılamıyor olabilir.")
                    .font(.footnote).foregroundStyle(theme.muted)
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 18)
        .padding(.top, 20)
        .background(theme.canvas.ignoresSafeArea())
        .presentationDetents([.medium, .large])
        .presentationDragIndicator(.visible)
        .task { await loadMonths() }
        .onChange(of: month) { _, m in Task { await loadDays(m) } }
    }

    private var header: some View {
        HStack(spacing: 12) {
            navButton("chevron.left", label: "Önceki ay", target: older)
            VStack(spacing: 2) {
                Text(MonthTitle.text(for: month.isEmpty ? Self.currentMonth : month))
                    .font(theme.monthFont)
                    .foregroundStyle(theme.ink)
                if let m = months.first(where: { $0.month == month }) {
                    Text("\(m.count) öğe · \(days.count) gün")
                        .font(.system(size: 12))
                        .foregroundStyle(theme.muted)
                        .monospacedDigit()
                }
            }
            .frame(maxWidth: .infinity)
            navButton("chevron.right", label: "Sonraki ay", target: newer)
        }
    }

    private func navButton(_ icon: String, label: String, target: String?) -> some View {
        Button {
            if let target { month = target }
        } label: {
            Image(systemName: icon)
                .font(.system(size: 15, weight: .semibold))
                .frame(width: 36, height: 36)
                .background(theme.surface.opacity(theme.name == .galeri ? 1 : 0.8), in: Circle())
                .overlay(Circle().stroke(theme.hairline, lineWidth: 1))
        }
        .buttonStyle(.plain)
        .foregroundStyle(target == nil ? theme.muted.opacity(0.4) : theme.ink)
        .disabled(target == nil)
        .accessibilityLabel(label)
    }

    private var grid: some View {
        let lead = Self.leadingBlanks(month.isEmpty ? Self.currentMonth : month)
        let count = Self.daysIn(month.isEmpty ? Self.currentMonth : month)
        let byDay = Dictionary(uniqueKeysWithValues: days.map { ($0.dayNumber, $0) })
        let today = Self.todayKey
        return LazyVGrid(columns: columns, spacing: 6) {
            ForEach(0..<lead, id: \.self) { i in Color.clear.frame(height: 44).id("blank-\(i)") }
            ForEach(1...max(1, count), id: \.self) { n in
                let key = String(format: "%@-%02d", month, n)
                DayCell(number: n, info: byDay[n], isToday: key == today) { d in
                    onPick(d)
                    dismiss()
                }
            }
        }
        .overlay {
            if loadingMonth && days.isEmpty { ProgressView().tint(theme.muted) }
        }
    }

    // MARK: - Veri

    private func loadMonths() async {
        guard let client = session.client else { failed = true; return }
        do {
            let r: MonthSummary = try await client.send("GET", "assets/summary")
            months = r.months.filter { $0.count > 0 }
            let wanted = initialMonth.flatMap { m in months.contains { $0.month == m } ? m : nil }
            month = wanted ?? months.first?.month ?? Self.currentMonth
            await loadDays(month)
        } catch {
            failed = true
        }
    }

    private func loadDays(_ m: String) async {
        guard !m.isEmpty, cache[m] == nil, let client = session.client else { return }
        loadingMonth = true
        defer { loadingMonth = false }
        if let r: DaysResponse = try? await client.send("GET", "assets/days", query: ["month": m]) {
            cache[m] = r.days
            failed = false
        } else {
            failed = true
        }
    }

    // MARK: - Takvim hesapları (Gregoryen, pazartesi başlangıçlı)

    private static var gregorian: Calendar {
        var c = Calendar(identifier: .gregorian)
        c.timeZone = TimeZone(identifier: "UTC")!
        return c
    }

    private static func firstOfMonth(_ ym: String) -> Date? {
        let p = ym.split(separator: "-").compactMap { Int($0) }
        guard p.count == 2 else { return nil }
        return gregorian.date(from: DateComponents(year: p[0], month: p[1], day: 1))
    }

    static func leadingBlanks(_ ym: String) -> Int {
        guard let d = firstOfMonth(ym) else { return 0 }
        let weekday = gregorian.component(.weekday, from: d)   // 1 = pazar
        return (weekday + 5) % 7
    }

    static func daysIn(_ ym: String) -> Int {
        guard let d = firstOfMonth(ym), let r = gregorian.range(of: .day, in: .month, for: d) else { return 30 }
        return r.count
    }

    static var currentMonth: String { String(todayKey.prefix(7)) }

    static var todayKey: String {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return f.string(from: Date())
    }
}

/// Takvimde tek gün: fotoğraf varsa kapak görseli, kalın rakam ve sağ üstte sayı rozeti.
private struct DayCell: View {
    let number: Int
    let info: DayCount?
    let isToday: Bool
    var onTap: (DayCount) -> Void
    @Environment(\.theme) private var theme

    private var radius: CGFloat { theme.name == .galeri ? 2 : 10 }

    var body: some View {
        if let info {
            Button { onTap(info) } label: {
                ZStack {
                    if let cover = info.coverAssetId {
                        RemoteImage(assetId: cover, variant: "thumb")
                            .overlay(Color.black.opacity(theme.name == .galeri ? 0.28 : 0.42))
                    } else {
                        theme.surface
                    }
                    Text("\(number)")
                        .font(.system(size: 16, weight: .bold, design: theme.name == .galeri ? .serif : .default))
                        .foregroundStyle(.white)
                        .monospacedDigit()
                }
                .frame(height: 44)
                .frame(maxWidth: .infinity)
                .clipShape(RoundedRectangle(cornerRadius: radius, style: .continuous))
                .overlay(
                    RoundedRectangle(cornerRadius: radius, style: .continuous)
                        .stroke(isToday ? theme.ink : theme.hairline, lineWidth: isToday ? 1.5 : 1)
                )
                .overlay(alignment: .topTrailing) { badge(info.count).offset(x: 5, y: -6) }
            }
            .buttonStyle(PressableStyle())
            .accessibilityLabel("\(number). gün, \(info.count) öğe")
        } else {
            Text("\(number)")
                .font(.system(size: 15, design: theme.name == .galeri ? .serif : .default))
                .foregroundStyle(isToday ? theme.ink : theme.muted.opacity(0.55))
                .monospacedDigit()
                .frame(height: 44)
                .frame(maxWidth: .infinity)
                .overlay {
                    if isToday { Circle().stroke(theme.muted, lineWidth: 1).frame(width: 32, height: 32) }
                }
                .accessibilityHidden(true)
        }
    }

    private func badge(_ n: Int) -> some View {
        Text(n > 999 ? "999+" : "\(n)")
            .font(.system(size: 10, weight: .bold))
            .monospacedDigit()
            .foregroundStyle(badgeText)
            .padding(.horizontal, 5)
            .frame(minWidth: 18, minHeight: 18)
            .background(badgeFill, in: Capsule())
            .overlay(Capsule().stroke(theme.canvas, lineWidth: 1.5))
    }

    private var badgeFill: Color {
        switch theme.name {
        case .sinema: return Color(hex: 0xECEAE6)
        case .galeri: return Color(hex: 0x2C4A5A)
        case .yerel: return Color(hex: 0x0A7CFF)
        }
    }

    private var badgeText: Color { theme.name == .sinema ? Color(hex: 0x0A0B0E) : .white }
}
