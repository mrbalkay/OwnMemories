import SwiftUI
import WidgetKit

/// Seçili görünüm teması. Sunucudaki kullanıcı kaydı esastır; App Group önbelleği açılışta
/// beklemeden doğru temayla başlamak ve widget'la paylaşmak içindir.
@MainActor
final class ThemeStore: ObservableObject {
    static let shared = ThemeStore()

    @Published private(set) var name: ThemeName = ThemeName.stored
    var theme: AppTheme { AppTheme.of(name) }

    /// Sunucuya henüz yazılamamış seçim (sunucu eski sürümse ya da ağ yoksa). Varken sunucudaki
    /// değer yerel seçimi ezmez; bir sonraki /me yenilemesinde yeniden gönderilir.
    private var unsynced: ThemeName? {
        get { UserDefaults.standard.string(forKey: "uiThemeUnsynced").flatMap(ThemeName.init(rawValue:)) }
        set { UserDefaults.standard.set(newValue?.rawValue, forKey: "uiThemeUnsynced") }
    }

    /// Sunucudan gelen kullanıcıyla eşitle (giriş, /me yenilemesi).
    func sync(from user: ServerUser?, session: Session? = nil) {
        if let pending = unsynced {
            if let session { Task { await push(pending, session: session) } }
            return
        }
        guard let raw = user?.theme, let t = ThemeName(rawValue: raw), t != name else { return }
        apply(t)
    }

    /// Kullanıcı seçimi: hemen uygular ve cihazda saklar; sunucuya yazılamazsa seçim korunur,
    /// sonra tekrar denenir.
    func select(_ t: ThemeName, session: Session) async {
        apply(t)
        unsynced = t
        await push(t, session: session)
    }

    private func push(_ t: ThemeName, session: Session) async {
        guard let client = session.client else { return }
        struct Body: Encodable { let theme: String }
        do {
            let r: MeResponse = try await client.send("PATCH", "me", body: Body(theme: t.rawValue))
            if unsynced == t { unsynced = nil }
            session.updateUser(r.user)
        } catch {
            DiagLog.add("Tema sunucuya kaydedilemedi (cihazda kullanılıyor): \(error.localizedDescription)")
        }
    }

    private func apply(_ t: ThemeName) {
        withAnimation(.easeInOut(duration: 0.25)) { name = t }
        ThemeName.store(t)
        WidgetCenter.shared.reloadAllTimelines()
    }
}
