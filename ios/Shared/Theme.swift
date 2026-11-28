import SwiftUI

/// Görünüm temaları. Seçim kullanıcının hesabında saklanır (PATCH /v1/me) ve App Group'ta
/// önbelleğe alınır; böylece widget da aynı temayı kullanır. Varsayılan: Sinema.
enum ThemeName: String, CaseIterable, Identifiable, Codable {
    case sinema, galeri, yerel
    var id: String { rawValue }
    static let `default`: ThemeName = .sinema

    var title: String {
        switch self {
        case .sinema: return "Sinema"
        case .galeri: return "Galeri"
        case .yerel: return "Yerel iOS"
        }
    }

    var subtitle: String {
        switch self {
        case .sinema: return "Karanlık, kenardan kenara fotoğraflar; günün anısı büyük bir afişle karşılar."
        case .galeri: return "Müze kataloğu sakinliği: bol beyaz alan, ince çizgiler, zarif serif başlıklar."
        case .yerel: return "Apple uygulamaları kadar tanıdık: büyük kalın başlıklar, cam yüzeyler."
        }
    }

    /// App Group'taki son seçim (uygulama ve widget ortak okur).
    static var stored: ThemeName {
        UserDefaults(suiteName: AppGroup.id)?.string(forKey: "uiTheme").flatMap(ThemeName.init(rawValue:)) ?? .default
    }

    static func store(_ t: ThemeName) {
        UserDefaults(suiteName: AppGroup.id)?.set(t.rawValue, forKey: "uiTheme")
    }
}

/// Bir temanın tasarım belirteçleri. Görünümler sabit renk/yazı yerine bunları kullanır;
/// yapısal farklar (anı düzeni, ızgara aralığı vb.) da burada tanımlıdır.
struct AppTheme {
    enum MemoryLayout { case hero, wall, cards }   // Sinema: büyük afiş; Galeri: sergi duvarı; Yerel: kapaklı kartlar

    let name: ThemeName
    let colorScheme: ColorScheme?        // nil: sistemi izle
    // Renkler
    let background: Color
    let surface: Color
    let surfaceElevated: Color
    let ink: Color
    let muted: Color
    let hairline: Color
    let accent: Color
    let success: Color
    // Yazı
    let titleDesign: Font.Design         // büyük başlıklar (serif / default)
    let titleWeight: Font.Weight
    let bodyDesign: Font.Design
    // Biçim
    let cornerRadius: CGFloat            // kart köşesi
    let tileRadius: CGFloat              // ızgara hücresi köşesi
    let gridSpacing: CGFloat
    let memoryLayout: MemoryLayout
    let usesGlass: Bool                  // .ultraThinMaterial yüzeyler
    let ambientGlow: Bool                // arka planda görünen fotoğrafın bulanık rengi

    func title(_ size: CGFloat) -> Font { .system(size: size, weight: titleWeight, design: titleDesign) }
    func body(_ size: CGFloat, weight: Font.Weight = .regular) -> Font { .system(size: size, weight: weight, design: bodyDesign) }

    static func of(_ n: ThemeName) -> AppTheme {
        switch n {
        case .sinema: return .sinema
        case .galeri: return .galeri
        case .yerel: return .yerel
        }
    }

    static let sinema = AppTheme(
        name: .sinema, colorScheme: .dark,
        background: Color(hex: 0x0B0B0E), surface: Color(hex: 0x16151B), surfaceElevated: Color(hex: 0x1F1E26),
        ink: Color(hex: 0xF4F1EA), muted: Color(hex: 0xA19DA8), hairline: Color.white.opacity(0.08),
        accent: Color(hex: 0xE8C27A), success: Color(hex: 0x7BD88F),
        titleDesign: .serif, titleWeight: .semibold, bodyDesign: .default,
        cornerRadius: 18, tileRadius: 4, gridSpacing: 2, memoryLayout: .hero, usesGlass: true, ambientGlow: true)

    static let galeri = AppTheme(
        name: .galeri, colorScheme: .light,
        background: Color(hex: 0xF6F4F0), surface: .white, surfaceElevated: .white,
        ink: Color(hex: 0x1D1B18), muted: Color(hex: 0x7A746B), hairline: Color(hex: 0x1D1B18).opacity(0.12),
        accent: Color(hex: 0x2C4A5A), success: Color(hex: 0x4F7A5A),
        titleDesign: .serif, titleWeight: .regular, bodyDesign: .default,
        cornerRadius: 2, tileRadius: 0, gridSpacing: 6, memoryLayout: .wall, usesGlass: false, ambientGlow: false)

    static let yerel = AppTheme(
        name: .yerel, colorScheme: nil,
        background: Color(uiColorLight: 0xF2F2F7, dark: 0x000000), surface: Color(uiColorLight: 0xFFFFFF, dark: 0x1C1C1E),
        surfaceElevated: Color(uiColorLight: 0xFFFFFF, dark: 0x2C2C2E),
        ink: Color(uiColorLight: 0x1C1C1E, dark: 0xFFFFFF), muted: Color(hex: 0x8A8A8E), hairline: Color.primary.opacity(0.1),
        accent: Color(hex: 0x0A7CFF), success: Color(hex: 0x30C25A),
        titleDesign: .default, titleWeight: .bold, bodyDesign: .default,
        cornerRadius: 22, tileRadius: 0, gridSpacing: 2, memoryLayout: .cards, usesGlass: true, ambientGlow: false)
}

extension Color {
    init(hex: UInt32, opacity: Double = 1) {
        self.init(.sRGB, red: Double((hex >> 16) & 0xFF) / 255, green: Double((hex >> 8) & 0xFF) / 255, blue: Double(hex & 0xFF) / 255, opacity: opacity)
    }

    /// Açık/koyu moda göre değişen renk.
    init(uiColorLight light: UInt32, dark: UInt32) {
        func ui(_ h: UInt32) -> UIColor {
            UIColor(red: CGFloat((h >> 16) & 0xFF) / 255, green: CGFloat((h >> 8) & 0xFF) / 255, blue: CGFloat(h & 0xFF) / 255, alpha: 1)
        }
        self.init(UIColor { $0.userInterfaceStyle == .dark ? ui(dark) : ui(light) })
    }
}

private struct ThemeKey: EnvironmentKey { static let defaultValue = AppTheme.of(.default) }

extension EnvironmentValues {
    var theme: AppTheme {
        get { self[ThemeKey.self] }
        set { self[ThemeKey.self] = newValue }
    }
}
