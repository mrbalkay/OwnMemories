import Foundation

enum ChunkPlan {
    /// `from` baytından başlayarak dosyayı en fazla `chunk` baytlık aralıklara böler.
    static func ranges(size: Int64, chunk: Int64, from: Int64 = 0) -> [Range<Int64>] {
        guard size > 0, chunk > 0, from < size else { return [] }
        var out: [Range<Int64>] = []
        var start = max(0, from)
        while start < size {
            let end = min(size, start + chunk)
            out.append(start..<end)
            start = end
        }
        return out
    }
}
