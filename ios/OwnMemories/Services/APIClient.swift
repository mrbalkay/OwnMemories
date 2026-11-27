import Foundation

enum APIError: LocalizedError {
    case unreachable(String)
    case unauthorized
    case server(status: Int, code: String, message: String, received: Int64?)
    case invalidResponse

    var errorDescription: String? {
        switch self {
        case .unreachable: return "Sunucuya ulaşılamıyor. Tailscale bağlı mı?"
        case .unauthorized: return "Oturumun süresi doldu; yeniden giriş yapın."
        case let .server(_, _, message, _): return message
        case .invalidResponse: return "Sunucudan beklenmeyen yanıt."
        }
    }

    /// Bu hata sonraki öğeleri de etkiler (sunucuya ulaşılamıyor, oturum yok): geçiş durur.
    var stopsBackup: Bool {
        if case .unauthorized = self { return true }
        return isRetryable
    }

    var isRetryable: Bool {
        switch self {
        case .unreachable: return true
        case let .server(status, _, _, _): return status >= 500 || status == 429
        default: return false
        }
    }
}

/// OwnMemories REST API istemcisi (/api/v1). Bearer token ile.
final class APIClient {
    let baseURL: URL
    let token: String?
    private let session: URLSession

    init(baseURL: URL, token: String?, session: URLSession = .shared) {
        self.baseURL = baseURL
        self.token = token
        self.session = session
    }

    func url(_ path: String, query: [String: String] = [:]) -> URL {
        var c = URLComponents(url: baseURL.appendingPathComponent("api/v1/" + path), resolvingAgainstBaseURL: false)!
        if !query.isEmpty {
            c.queryItems = query.sorted { $0.key < $1.key }.map { URLQueryItem(name: $0.key, value: $0.value) }
        }
        return c.url!
    }

    func authorized(_ url: URL, method: String = "GET") -> URLRequest {
        var r = URLRequest(url: url)
        r.httpMethod = method
        r.timeoutInterval = 60
        if let token { r.setValue("Bearer " + token, forHTTPHeaderField: "Authorization") }
        r.setValue("application/json", forHTTPHeaderField: "Accept")
        return r
    }

    func send<T: Decodable>(_ method: String, _ path: String, query: [String: String] = [:], body: Encodable? = nil, as type: T.Type = T.self) async throws -> T {
        var req = authorized(url(path, query: query), method: method)
        if let body {
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
            req.httpBody = try JSONEncoder().encode(AnyEncodable(body))
        }
        return try await perform(req)
    }

    func perform<T: Decodable>(_ req: URLRequest) async throws -> T {
        let data: Data
        let resp: URLResponse
        do {
            (data, resp) = try await session.data(for: req)
        } catch {
            throw APIError.unreachable(error.localizedDescription)
        }
        return try Self.decode(data: data, response: resp)
    }

    static func decode<T: Decodable>(data: Data, response: URLResponse) throws -> T {
        guard let http = response as? HTTPURLResponse else { throw APIError.invalidResponse }
        if http.statusCode == 401 { throw APIError.unauthorized }
        guard (200..<300).contains(http.statusCode) else {
            if let e = try? JSONDecoder().decode(APIErrorEnvelope.self, from: data) {
                throw APIError.server(status: http.statusCode, code: e.error.code, message: e.error.message, received: e.error.received)
            }
            throw APIError.server(status: http.statusCode, code: "http_\(http.statusCode)", message: "Sunucu hatası (\(http.statusCode))", received: nil)
        }
        do {
            return try JSONDecoder().decode(T.self, from: data)
        } catch {
            throw APIError.invalidResponse
        }
    }
}

private struct AnyEncodable: Encodable {
    let value: Encodable
    init(_ v: Encodable) { value = v }
    func encode(to encoder: Encoder) throws { try value.encode(to: encoder) }
}

struct EmptyBody: Encodable {}
struct OkResponse: Decodable { let ok: Bool? }
