import SwiftUI
import UIKit

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
        // Gece, Perde, Kadife; metin Gümüş, ikincil Sis; durum yalnızca Yeşil.
        background: Color(hex: 0x0A0B0E), surface: Color(hex: 0x14161B), surfaceElevated: Color(hex: 0x1E2129),
        ink: Color(hex: 0xECEAE6), muted: Color(hex: 0x9298A3), hairline: Color.white.opacity(0.08),
        accent: Color(hex: 0xECEAE6), success: Color(hex: 0x8FD3B0),
        titleDesign: .serif, titleWeight: .regular, bodyDesign: .default,
        cornerRadius: 20, tileRadius: 0, gridSpacing: 2, memoryLayout: .hero, usesGlass: true, ambientGlow: true)

    static let galeri = AppTheme(
        name: .galeri, colorScheme: .light,
        // Taş zemin, Duvar yüzey, Kılcal çizgi, Grafit, Mürekkep; tek vurgu Müze mavisi.
        background: Color(hex: 0xF3F3F0), surface: .white, surfaceElevated: .white,
        ink: Color(hex: 0x1B1C1E), muted: Color(hex: 0x6E7075), hairline: Color(hex: 0xE2E1DC),
        accent: Color(hex: 0x2C4A5A), success: Color(hex: 0x2C4A5A),
        titleDesign: .serif, titleWeight: .regular, bodyDesign: .default,
        cornerRadius: 14, tileRadius: 0, gridSpacing: 2, memoryLayout: .wall, usesGlass: false, ambientGlow: false)

    static let yerel = AppTheme(
        name: .yerel, colorScheme: nil,
        // Sis, Kâğıt, Grafit, Duman, Gök mavisi, Yaprak (koyu görünümde sistem karşılıkları).
        background: Color(uiColorLight: 0xF2F2F7, dark: 0x000000), surface: Color(uiColorLight: 0xFFFFFF, dark: 0x1C1C1E),
        surfaceElevated: Color(uiColorLight: 0xFFFFFF, dark: 0x2C2C2E),
        ink: Color(uiColorLight: 0x1C1C1E, dark: 0xF5F5F7), muted: Color(uiColorLight: 0x8A8A8F, dark: 0x98989F),
        hairline: Color.dynamic(UIColor(red: 60 / 255, green: 60 / 255, blue: 67 / 255, alpha: 0.16),
                                UIColor(red: 84 / 255, green: 84 / 255, blue: 88 / 255, alpha: 0.6)),
        accent: Color(uiColorLight: 0x0A7CFF, dark: 0x2E91FF), success: Color(uiColorLight: 0x30C25A, dark: 0x32D25F),
        titleDesign: .default, titleWeight: .bold, bodyDesign: .default,
        cornerRadius: 22, tileRadius: 0, gridSpacing: 2, memoryLayout: .cards, usesGlass: true, ambientGlow: false)
}

// MARK: - Ekran düzeyinde türetilmiş belirteçler (tasarım taslaklarından)

extension AppTheme {
    var isSerif: Bool { titleDesign == .serif }

    /// Kitaplık zemini: Sinema'da Gece, Galeri'de beyaz Duvar, Yerel'de Kâğıt (koyuda saf siyah).
    var canvas: Color {
        switch name {
        case .sinema: return background
        case .galeri: return .white
        case .yerel: return Color(uiColorLight: 0xFFFFFF, dark: 0x000000)
        }
    }

    /// Gruplanmış liste / kart yüzeyi (Yedekleme, Ayarlar).
    var groupSurface: Color {
        switch name {
        case .sinema: return Color(hex: 0x1E2129, opacity: 0.62)
        case .galeri, .yerel: return surface
        }
    }

    /// Görsel yüklenirken hücre zemini.
    var placeholder: Color {
        switch name {
        case .sinema: return Color(hex: 0x1E2129)
        case .galeri: return background
        case .yerel: return Color(uiColorLight: 0xE5E5EA, dark: 0x1C1C1E)
        }
    }

    /// Anahtarların açık rengi: Sinema'da Yeşil, Galeri'de Müze mavisi, Yerel'de Yaprak.
    var toggleTint: Color {
        switch name {
        case .sinema: return success
        case .galeri: return accent
        case .yerel: return success
        }
    }

    /// İlerleme halkası/çubuğu dolgusu.
    var progressTint: Color {
        switch name {
        case .sinema: return ink
        case .galeri: return ink
        case .yerel: return accent
        }
    }

    /// Kare ızgara sütun sayısı (taslak: Sinema 4, diğerleri 3).
    var gridColumns: Int { name == .sinema ? 4 : 3 }

    /// Anı kartı köşesi.
    var memoryRadius: CGFloat {
        switch name {
        case .sinema: return 14
        case .galeri: return 2
        case .yerel: return 20
        }
    }

    /// Ekran başlığı ("Fotoğraflar", "Yedekleme"): serif 34 normal ya da SF 34 kalın.
    var largeTitle: Font { .system(size: 34, weight: titleWeight, design: titleDesign) }

    /// Ay başlığı ("Kasım 2026").
    var monthFont: Font {
        switch name {
        case .sinema: return .system(size: 24, weight: .regular, design: .serif)
        case .galeri: return .system(size: 22, weight: .regular, design: .serif)
        case .yerel: return .system(size: 20, weight: .bold)
        }
    }

    /// Gün başlığı ("Bugün").
    var dayFont: Font {
        switch name {
        case .sinema: return .system(size: 14, weight: .semibold)
        case .galeri: return .system(size: 13, weight: .semibold)
        case .yerel: return .system(size: 15, weight: .semibold)
        }
    }

    /// Bölüm başlığı ("Anılar", "Koşullar").
    var sectionFont: Font {
        switch name {
        case .sinema: return .system(size: 20, weight: .regular, design: .serif)
        case .galeri: return .system(size: 13, weight: .regular)
        case .yerel: return .system(size: 20, weight: .bold)
        }
    }

    /// Sekme çubuğu renkleri.
    var tabSelected: Color {
        switch name {
        case .sinema: return .white
        case .galeri: return ink
        case .yerel: return accent
        }
    }

    var tabNormal: Color {
        switch name {
        case .sinema: return Color(hex: 0xA2A7B1)
        case .galeri: return muted
        case .yerel: return ink
        }
    }

    /// UIKit için serif/sistem yazı tipi (gezinme çubuğu başlıkları).
    func uiTitleFont(_ size: CGFloat) -> UIFont {
        let w: UIFont.Weight
        if titleWeight == .bold { w = .bold }
        else if titleWeight == .semibold { w = .semibold }
        else if titleWeight == .medium { w = .medium }
        else { w = .regular }
        let base = UIFont.systemFont(ofSize: size, weight: w)
        if isSerif, let d = base.fontDescriptor.withDesign(.serif) { return UIFont(descriptor: d, size: size) }
        return base
    }
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

    /// Açık/koyu moda göre değişen, saydamlık içeren renk.
    static func dynamic(_ light: UIColor, _ dark: UIColor) -> Color {
        Color(UIColor { $0.userInterfaceStyle == .dark ? dark : light })
    }
}

private struct ThemeKey: EnvironmentKey { static let defaultValue = AppTheme.of(.default) }

extension EnvironmentValues {
    var theme: AppTheme {
        get { self[ThemeKey.self] }
        set { self[ThemeKey.self] = newValue }
    }
}
