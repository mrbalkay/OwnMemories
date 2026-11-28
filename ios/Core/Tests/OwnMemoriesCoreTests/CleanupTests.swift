import XCTest
@testable import OwnMemoriesCore

final class CleanupTests: XCTestCase {
    private func asset(_ id: String, size: Int = 1000, extra: String = "", fav: Bool = false) -> String {
        """
        {"id":"\(id)","type":"photo","filename":"\(id).jpg","mime":"image/jpeg","size":\(size),"width":4032,"height":3024,
         "duration_ms":null,"taken_at":"2024-03-07T08:00:00Z","taken_local":null,"tz_offset":null,"date_source":"exif",
         "latitude":null,"longitude":null,"altitude":null,"camera_make":"Apple","camera_model":"iPhone 13","lens":null,
         "description":null,"is_favorite":\(fav),"is_archived":false,"shared_from_others":false,"trashed_at":null,"source":"ios",
         "live_pair_id":null,"edited_of_id":null,"thumb_state":"ready","updated_at":"2026-11-28T10:00:00Z","version":3,
         "quality":{"sharpness":\(id == "c" ? "6.5" : "812.3"),"brightness":120.5}\(extra)}
        """
    }

    private var json: String {
        """
        {"ml":{"available":true,"indexed":3},"analysis":{"analyzed":9,"total":12},
         "groups":[{"id":"0123456789abcdef","reason":"similar","keep_id":"a","keep_reasons":["resolution","sharpest"],
                    "bytes":2500,"taken_at":"2024-03-07T08:00:00Z","assets":[\(asset("a")),\(asset("b", size: 1500)),\(asset("c"))]}],
         "blurry":[],"dark":[\(asset("d", extra: ",\"why\":\"bright\""))],"screenshots":[],"short_videos":[],
         "stats":{"potential_bytes":2500,"groups":1,"group_assets":3,"blurry":0,"blurry_bytes":0,"dark":1,"dark_bytes":1000,
                  "screenshots":0,"screenshots_bytes":0,"short_videos":0,"short_videos_bytes":0}}
        """
    }

    func testDecodeCleanupResponse() throws {
        let r = try JSONDecoder().decode(CleanupResponse.self, from: Data(json.utf8))
        XCTAssertTrue(r.ml.available)
        XCTAssertTrue(r.analysis.isRunning)
        XCTAssertEqual(r.groups.count, 1)
        let g = r.groups[0]
        XCTAssertEqual(g.keepId, "a")
        XCTAssertEqual(g.keepReasons, ["resolution", "sharpest"])
        XCTAssertFalse(g.isDuplicate)
        XCTAssertEqual(g.suggestedTrash, ["b", "c"])
        XCTAssertEqual(g.bytes(trashing: ["b", "c"]), 2500)
        XCTAssertEqual(g.assets[2].sharpnessLevel, .blurry)
        XCTAssertEqual(g.assets[0].sharpnessLevel, .sharp)
        XCTAssertEqual(g.assets[1].asset.size, 1500)
        XCTAssertEqual(r.dark.first?.why, "bright")
        XCTAssertEqual(r.stats.potentialBytes, 2500)
        XCTAssertEqual(CleanupCategory.dark.items(in: r).count, 1)
        XCTAssertEqual(CleanupCategory.dark.count(in: r.stats), 1)
        XCTAssertEqual(CleanupCategory.screenshots.dismissKind, "screenshot")
        XCTAssertFalse(CleanupCategory.screenshots.preselects)
        XCTAssertFalse(r.isClean)
    }

    func testResolvePartAndBatches() throws {
        let r = try JSONDecoder().decode(CleanupResponse.self, from: Data(json.utf8))
        let p = CleanupResolveRequest.part(for: r.groups[0], trash: ["c"])
        XCTAssertEqual(p.keep, ["a", "b"])
        XCTAssertEqual(p.trash, ["c"])
        // Sınır: istek başına en çok 900 kimlik; tek başına büyük parça dilimlenir
        let parts = (0..<5).map { i in CleanupResolveRequest.Part(keep: ["k\(i)"], trash: (0..<300).map { "t\(i)-\($0)" }) }
        let b = CleanupResolveRequest.batches(parts)
        XCTAssertEqual(b.count, 3)
        XCTAssertTrue(b.allSatisfy { req in req.groups.reduce(0) { sum, part in sum + part.keep.count + part.trash.count } <= 900 })
        XCTAssertEqual(b.flatMap { req in req.groups.flatMap { $0.trash } }.count, 1500)
        let big = CleanupResolveRequest.batches([.init(keep: [], trash: (0..<2000).map { "s\($0)" })])
        XCTAssertEqual(big.count, 3)
        XCTAssertEqual(big.map { $0.groups[0].trash.count }, [900, 900, 200])
        // Silinecek yok ve tek tutulan: istek gerekmez; iki tutulan "farklı" olarak hatırlanır
        XCTAssertTrue(CleanupResolveRequest.batches([.init(keep: ["a"], trash: [])]).isEmpty)
        XCTAssertEqual(CleanupResolveRequest.batches([.init(keep: ["a", "b"], trash: [])]).count, 1)
        let body = try JSONEncoder().encode(CleanupResolveRequest(groups: [p]))
        let obj = try JSONSerialization.jsonObject(with: body) as? [String: Any]
        XCTAssertNotNil(obj?["groups"] as? [[String: Any]])
    }

    func testFavoriteIsNeverSuggestedForTrash() throws {
        let j = """
        {"id":"0123456789abcdef","reason":"duplicate","keep_id":"a","keep_reasons":[],"bytes":2000,
         "taken_at":"2024-03-07T08:00:00Z","assets":[\(asset("a")),\(asset("b", fav: true)),\(asset("c"))]}
        """
        let g = try JSONDecoder().decode(CleanupGroup.self, from: Data(j.utf8))
        XCTAssertTrue(g.assets[1].asset.isFavorite)
        XCTAssertEqual(g.suggestedTrash, ["c"])
    }

    func testDecodeResolveResponse() throws {
        let r = try JSONDecoder().decode(CleanupResolveResponse.self, from: Data(#"{"trashed":2,"freed_bytes":4096}"#.utf8))
        XCTAssertEqual(r, CleanupResolveResponse(trashed: 2, freedBytes: 4096))
    }
}
