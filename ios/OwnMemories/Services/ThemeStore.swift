import SwiftUI
import WidgetKit

/// Seçili görünüm teması. Sunucudaki kullanıcı kaydı esastır; App Group önbelleği açılışta
/// beklemeden doğru temayla başlamak ve widget'la paylaşmak içindir.
@MainActor
final class ThemeStore: ObservableObject {
    static let shared = ThemeStore()

    @Published private(set) var name: ThemeName = ThemeName.stored
    var theme: AppTheme { AppTheme.of(name) }

    /// Sunucudan gelen kullanıcıyla eşitle (giriş, /me yenilemesi).
    func sync(from user: ServerUser?) {
        guard let raw = user?.theme, let t = ThemeName(rawValue: raw), t != name else { return }
        apply(t)
    }

    /// Kullanıcı seçimi: hemen uygular, sunucuya yazar; sunucu reddederse geri alır.
    func select(_ t: ThemeName, session: Session) async {
        let previous = name
        apply(t)
        guard let client = session.client else { return }
        struct Body: Encodable { let theme: String }
        do {
            let r: MeResponse = try await client.send("PATCH", "me", body: Body(theme: t.rawValue))
            session.updateUser(r.user)
        } catch {
            DiagLog.add("Tema kaydedilemedi: \(error.localizedDescription)")
            apply(previous)
        }
    }

    private func apply(_ t: ThemeName) {
        withAnimation(.easeInOut(duration: 0.25)) { name = t }
        ThemeName.store(t)
        WidgetCenter.shared.reloadAllTimelines()
    }
}
