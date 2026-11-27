import Foundation

/// Sunucuya gönderilen kaynak kimliği: `<localIdentifier>`, `<localIdentifier>#live`, `<localIdentifier>#edited`.
enum ResourceKind: String, Codable, CaseIterable {
    case original
    case live
    case edited
}

enum SourceRef {
    static func make(localId: String, kind: ResourceKind) -> String {
        switch kind {
        case .original: return localId
        case .live: return localId + "#live"
        case .edited: return localId + "#edited"
        }
    }

    static func parse(_ ref: String) -> (localId: String, kind: ResourceKind) {
        if ref.hasSuffix("#live") { return (String(ref.dropLast(5)), .live) }
        if ref.hasSuffix("#edited") { return (String(ref.dropLast(7)), .edited) }
        return (ref, .original)
    }
}
