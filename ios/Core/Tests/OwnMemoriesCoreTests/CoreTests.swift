import XCTest
@testable import OwnMemoriesCore

final class CoreTests: XCTestCase {
    let assetJSON = """
    {"id":"6f1c0f3e-1111-4222-8333-444455556666","type":"photo","filename":"IMG_1.HEIC","mime":"image/heic","size":2345678,
     "width":3024,"height":4032,"duration_ms":null,"taken_at":"2019-07-01T12:04:05Z","taken_local":"2019-07-01T15:04:05+03:00",
     "tz_offset":180,"date_source":"takeout","latitude":38.4192,"longitude":27.1287,"altitude":12.0,"camera_make":"Apple",
     "camera_model":"iPhone 8","lens":null,"description":"Kordon","is_favorite":true,"is_archived":false,"shared_from_others":false,
     "trashed_at":null,"source":"takeout","live_pair_id":null,"edited_of_id":null,"thumb_state":"ready",
     "updated_at":"2026-11-26T10:00:00Z","version":42}
    """

    func testDecodeAssetFromServerJSON() throws {
        let a = try JSONDecoder().decode(Asset.self, from: Data(assetJSON.utf8))
        XCTAssertEqual(a.filename, "IMG_1.HEIC")
        XCTAssertEqual(a.dayKey, "2019-07-01")
        XCTAssertEqual(a.caption, "Kordon")
        XCTAssertTrue(a.isFavorite)
        XCTAssertFalse(a.isVideo)
        XCTAssertEqual(a.tzOffset, 180)
    }

    func testDecodeUserTheme() throws {
        let with = #"{"id":1,"email":"a@b.c","name":"A","is_admin":false,"used_bytes":5,"quota_bytes":null,"theme":"galeri"}"#
        XCTAssertEqual(try JSONDecoder().decode(ServerUser.self, from: Data(with.utf8)).theme, "galeri")
        let without = #"{"id":1,"email":"a@b.c","name":"A","is_admin":false,"used_bytes":5,"quota_bytes":null}"#
        XCTAssertNil(try JSONDecoder().decode(ServerUser.self, from: Data(without.utf8)).theme)
    }

    func testDecodePageAndErrors() throws {
        let page = try JSONDecoder().decode(AssetPage.self, from: Data("{\"assets\":[\(assetJSON)],\"next\":{\"before\":\"2019-07-01T12:04:05Z\",\"before_id\":\"x\"}}".utf8))
        XCTAssertEqual(page.assets.count, 1)
        XCTAssertEqual(page.next?.beforeId, "x")
        let last = try JSONDecoder().decode(AssetPage.self, from: Data("{\"assets\":[],\"next\":null}".utf8))
        XCTAssertNil(last.next)
        let err = try JSONDecoder().decode(APIErrorEnvelope.self, from: Data("{\"error\":{\"code\":\"offset_mismatch\",\"message\":\"m\",\"received\":100}}".utf8))
        XCTAssertEqual(err.error.received, 100)
        let start = try JSONDecoder().decode(UploadStart.self, from: Data("{\"id\":\"u\",\"chunk_size\":52428800,\"received\":0}".utf8))
        XCTAssertEqual(start.chunkSize, 52_428_800)
    }

    func testDecodeSmartSearchResponse() throws {
        let json = "{\"mode\":\"smart\",\"assets\":[\(assetJSON)],\"next_offset\":60,\"total\":48,\"status\":{\"indexed\":12400,\"total\":20000,\"ready\":false}}"
        let r = try JSONDecoder().decode(SearchResponse.self, from: Data(json.utf8))
        XCTAssertTrue(r.isSmart)
        XCTAssertEqual(r.assets.count, 1)
        XCTAssertEqual(r.assets[0].filename, "IMG_1.HEIC")
        XCTAssertEqual(r.nextOffset, 60)
        XCTAssertEqual(r.total, 48)
        let st = try XCTUnwrap(r.status)
        XCTAssertEqual(st.indexed, 12_400)
        XCTAssertEqual(st.total, 20_000)
        XCTAssertFalse(st.ready)
        XCTAssertTrue(st.available)
        XCTAssertEqual(st.percent, 62)
        XCTAssertTrue(st.isIndexing)
    }

    func testDecodeTextSearchResponseWithoutStatus() throws {
        let json = #"{"mode":"text","assets":[],"next_offset":null,"status":null}"#
        let r = try JSONDecoder().decode(SearchResponse.self, from: Data(json.utf8))
        XCTAssertFalse(r.isSmart)
        XCTAssertTrue(r.assets.isEmpty)
        XCTAssertNil(r.nextOffset)
        XCTAssertNil(r.status)
        let missing = try JSONDecoder().decode(SearchResponse.self, from: Data(#"{"mode":"text","assets":[]}"#.utf8))
        XCTAssertNil(missing.nextOffset)
        XCTAssertNil(missing.status)
        XCTAssertNil(missing.total)
    }

    func testDecodeSearchStatus() throws {
        let full = #"{"indexed":30000,"total":30000,"pending":0,"model":"ViT-B-16-SigLIP2__webli","ready":true}"#
        let s = try JSONDecoder().decode(SearchStatus.self, from: Data(full.utf8))
        XCTAssertTrue(s.ready)
        XCTAssertEqual(s.pending, 0)
        XCTAssertEqual(s.model, "ViT-B-16-SigLIP2__webli")
        XCTAssertEqual(s.percent, 100)
        XCTAssertFalse(s.isIndexing)
        let off = try JSONDecoder().decode(SearchStatus.self, from: Data(#"{"available":false}"#.utf8))
        XCTAssertFalse(off.available)
        XCTAssertFalse(off.ready)
        XCTAssertEqual(off.total, 0)
        XCTAssertFalse(off.isIndexing)
        XCTAssertEqual(SearchStatus(indexed: 1, total: 3, ready: false).percent, 33)
    }

    func testUploadMetaEncodesSnakeCase() throws {
        let m = UploadMeta(takenAt: "2026-01-01T10:00:00Z", tzOffset: 180, latitude: nil, longitude: nil, isFavorite: true,
                           sourceRef: "ABC/L0/001#live", livePairSourceRef: "ABC/L0/001", editedOfSourceRef: nil)
        let json = String(decoding: try JSONEncoder().encode(m), as: UTF8.self)
        XCTAssertTrue(json.contains("\"source_ref\":\"ABC\\/L0\\/001#live\""))
        XCTAssertTrue(json.contains("\"live_pair_source_ref\""))
        XCTAssertTrue(json.contains("\"tz_offset\":180"))
    }

    func testSourceRefRoundTrip() {
        for kind in ResourceKind.allCases {
            let ref = SourceRef.make(localId: "ABC/L0/001", kind: kind)
            let p = SourceRef.parse(ref)
            XCTAssertEqual(p.localId, "ABC/L0/001")
            XCTAssertEqual(p.kind, kind)
        }
        XCTAssertEqual(SourceRef.make(localId: "X", kind: .edited), "X#edited")
    }

    func testChunkRanges() {
        XCTAssertEqual(ChunkPlan.ranges(size: 10, chunk: 4), [0..<4, 4..<8, 8..<10])
        XCTAssertEqual(ChunkPlan.ranges(size: 8, chunk: 4), [0..<4, 4..<8])
        XCTAssertEqual(ChunkPlan.ranges(size: 10, chunk: 4, from: 6), [6..<10])
        XCTAssertEqual(ChunkPlan.ranges(size: 10, chunk: 4, from: 10), [])
        XCTAssertEqual(ChunkPlan.ranges(size: 0, chunk: 4), [])
        let big: Int64 = 5_000_000_000
        let r = ChunkPlan.ranges(size: big, chunk: 52_428_800)
        XCTAssertEqual(r.count, 96)
        XCTAssertEqual(r.last?.upperBound, big)
    }

    func testBackupPolicy() {
        var s = BackupSettings()
        XCTAssertEqual(BackupPolicy.decide(online: false, onWifi: true, charging: true, settings: s), .offline)
        XCTAssertEqual(BackupPolicy.decide(online: true, onWifi: false, charging: true, settings: s), .waitForWifi)
        XCTAssertEqual(BackupPolicy.decide(online: true, onWifi: true, charging: false, settings: s), .run)
        s.chargingOnly = true
        XCTAssertEqual(BackupPolicy.decide(online: true, onWifi: true, charging: false, settings: s), .waitForCharging)
        s.wifiOnly = false
        XCTAssertEqual(BackupPolicy.decide(online: true, onWifi: false, charging: true, settings: s), .run)
        s.includeVideos = false
        XCTAssertFalse(BackupPolicy.includes(isVideo: true, settings: s))
        XCTAssertTrue(BackupPolicy.includes(isVideo: false, settings: s))
    }

    func testDayGroupingKeepsOrder() throws {
        let a = try JSONDecoder().decode(Asset.self, from: Data(assetJSON.utf8))
        func with(_ id: String, _ local: String) throws -> Asset {
            let j = assetJSON.replacingOccurrences(of: "6f1c0f3e-1111-4222-8333-444455556666", with: id)
                .replacingOccurrences(of: "2019-07-01T15:04:05+03:00", with: local)
            return try JSONDecoder().decode(Asset.self, from: Data(j.utf8))
        }
        let list = [try with("a", "2020-01-02T10:00:00+03:00"), try with("b", "2020-01-02T09:00:00+03:00"), a]
        let g = DayGrouping.group(list)
        XCTAssertEqual(g.map(\.id), ["2020-01-02", "2019-07-01"])
        XCTAssertEqual(g[0].assets.map(\.id), ["a", "b"])
    }

    func testDayTitleTurkish() {
        let now = ServerDate.parse("2026-11-27T10:00:00Z")!
        XCTAssertEqual(DayGrouping.title(for: "2019-07-01", now: now), "1 Temmuz 2019 Pazartesi")
    }

    func testServerDateFormat() {
        let d = ServerDate.parse("2026-01-01T10:00:00Z")!
        XCTAssertEqual(ServerDate.format(d), "2026-01-01T10:00:00Z")
        XCTAssertEqual(ServerDate.offsetMinutes(at: d, in: TimeZone(identifier: "Europe/Istanbul")!), 180)
    }

    func testSHA1OfFile() throws {
        let url = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        try Data("abc".utf8).write(to: url)
        defer { try? FileManager.default.removeItem(at: url) }
        XCTAssertEqual(try FileHash.sha1Hex(of: url), "a9993e364706816aba3e25717850c26c9cd0d89d")
    }
}

final class MemoryModelTests: XCTestCase {
    func testDecodeMemories() throws {
        let json = """
        {"date":"2026-11-27","memories":[{"id":"on_this_day:2025-11-27","kind":"on_this_day","title":"1 yıl önce bugün","years_ago":1,
        "date":"2025-11-27","count":0,"cover_asset_id":"c","asset_ids":[],"assets":[]},
        {"id":"favorites:2026-11-27","kind":"favorites","title":"Favorilerinizden","years_ago":null,"date":"2026-11-27","count":0,
        "cover_asset_id":"d","asset_ids":[],"assets":[]}]}
        """
        let r = try JSONDecoder().decode(MemoriesResponse.self, from: Data(json.utf8))
        XCTAssertEqual(r.memories.count, 2)
        XCTAssertEqual(r.memories[0].yearsAgo, 1)
        XCTAssertNil(r.memories[1].yearsAgo)
        XCTAssertEqual(r.memories[0].coverAssetId, "c")
    }
}
