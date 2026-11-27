import Foundation
import SwiftUI
import UIKit

/// Oturum: sunucu adresi (UserDefaults) ve token (Keychain).
@MainActor
final class Session: ObservableObject {
    static let shared = Session()

    @Published private(set) var serverURL: URL?
    @Published private(set) var token: String?
    @Published private(set) var user: ServerUser?

    var isLoggedIn: Bool { serverURL != nil && token != nil }

    private init() {
        if let s = UserDefaults.standard.string(forKey: "serverURL"), let u = URL(string: s) { serverURL = u }
        token = Keychain.get("token")
        if let data = UserDefaults.standard.data(forKey: "user") { user = try? JSONDecoder().decode(ServerUser.self, from: data) }
    }

    var client: APIClient? {
        guard let serverURL else { return nil }
        return APIClient(baseURL: serverURL, token: token)
    }

    /// Nonisolated erişim (arka plan görevleri için): kayıtlı değerlerden istemci üretir.
    nonisolated static func storedClient() -> APIClient? {
        guard let s = UserDefaults.standard.string(forKey: "serverURL"), let u = URL(string: s), let t = Keychain.get("token") else { return nil }
        return APIClient(baseURL: u, token: t)
    }

    static func normalize(_ raw: String) -> URL? {
        var s = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        while s.hasSuffix("/") { s.removeLast() }
        if !s.lowercased().hasPrefix("http://") && !s.lowercased().hasPrefix("https://") { s = "https://" + s }
        guard let u = URL(string: s), u.host != nil else { return nil }
        return u
    }

    func login(server: String, email: String, password: String) async throws {
        guard let url = Self.normalize(server) else { throw APIError.server(status: 0, code: "bad_url", message: "Geçerli bir sunucu adresi girin.", received: nil) }
        let client = APIClient(baseURL: url, token: nil)
        struct Body: Encodable { let email: String; let password: String; let device_name: String; let platform: String }
        let r: LoginResponse = try await client.send("POST", "auth/login", body: Body(email: email, password: password, device_name: DeviceInfo.name, platform: "ios"))
        UserDefaults.standard.set(url.absoluteString, forKey: "serverURL")
        Keychain.set(r.token, for: "token")
        UserDefaults.standard.set(try? JSONEncoder().encode(r.user), forKey: "user")
        serverURL = url
        token = r.token
        user = r.user
    }

    func refreshUser() async {
        guard let client else { return }
        if let me: MeResponse = try? await client.send("GET", "me") {
            user = me.user
            UserDefaults.standard.set(try? JSONEncoder().encode(me.user), forKey: "user")
        }
    }

    func logout() async {
        if let client { _ = try? await client.send("POST", "auth/logout", as: OkResponse.self) }
        Keychain.delete("token")
        UserDefaults.standard.removeObject(forKey: "user")
        token = nil
        user = nil
    }
}

enum DeviceInfo {
    static var name: String { UIDevice.current.name }
    static var osVersion: String { UIDevice.current.systemVersion }
    static var appVersion: String {
        let v = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "?"
        let b = Bundle.main.infoDictionary?["CFBundleVersion"] as? String ?? "?"
        return "\(v) (\(b))"
    }
    static var model: String {
        var sys = utsname()
        uname(&sys)
        return withUnsafeBytes(of: &sys.machine) { raw in
            String(decoding: raw.prefix(while: { $0 != 0 }), as: UTF8.self)
        }
    }
    static var deviceId: String {
        if let id = UserDefaults.standard.string(forKey: "deviceId") { return id }
        let id = UIDevice.current.identifierForVendor?.uuidString ?? UUID().uuidString
        UserDefaults.standard.set(id, forKey: "deviceId")
        return id
    }
}
