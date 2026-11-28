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

    func testDecodeServerBackupStatus() throws {
        let json = #"""
        {"backup":{"configured":true,"level":"warn","next_run_at":"2026-11-29T00:10:00Z","running":false,"requested":false,
         "last_run":{"started_at":"2026-11-28T00:10:00Z","finished_at":"2026-11-28T00:12:00Z","trigger":"scheduled","ok":false},
         "restore_test":{"at":"2026-11-27T00:12:00Z","ok":true,"files_checked":20,"db_ok":true,"message":"20/20"},
         "targets":[
          {"id":"disk","type":"local","label":"Harici disk","location":"/mnt/usb","last_status":"ok","last_success_at":"2026-11-28T00:11:00Z",
           "repo_size":123456789,"snapshot_count":12,"health":{"level":"ok","reason":"ok"}},
          {"id":"offsite","type":"s3","label":"Backblaze B2","last_status":"failed","error":"bağlantı yok",
           "health":{"level":"error","reason":"never_succeeded_failing"}}]}}
        """#
        let r = try JSONDecoder().decode(ServerBackupEnvelope.self, from: Data(json.utf8))
        let b = r.backup
        XCTAssertTrue(b.configured)
        XCTAssertEqual(b.level, .warn)
        XCTAssertEqual(b.targets.count, 2)
        XCTAssertEqual(b.targets[0].repoSize, 123456789)
        XCTAssertEqual(b.targets[0].health.level, .ok)
        XCTAssertNotNil(b.targets[0].lastSuccessDate)
        XCTAssertNil(b.targets[1].lastSuccessDate)
        XCTAssertEqual(b.targets[1].error, "bağlantı yok")
        XCTAssertEqual(b.restoreTest?.ok, true)
        XCTAssertEqual(b.nextRunDate, ISO8601DateFormatter().date(from: "2026-11-29T00:10:00Z"))
        XCTAssertEqual(b.headline, "Dikkat gerekiyor")

        let none = try JSONDecoder().decode(ServerBackupEnvelope.self, from: Data(#"{"backup":{"configured":false,"level":"unconfigured","targets":[]}}"#.utf8))
        XCTAssertFalse(none.backup.configured)
        XCTAssertEqual(none.backup.level, .unconfigured)
        XCTAssertEqual(none.backup.headline, "Yedekleme kurulmadı")
        // Bilinmeyen seviye çökmesin.
        let odd = try JSONDecoder().decode(ServerBackupEnvelope.self, from: Data(#"{"backup":{"configured":true,"level":"mor","targets":[]}}"#.utf8))
        XCTAssertEqual(odd.backup.level, .unknown)
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

final class PeopleModelTests: XCTestCase {
    func testDecodePeopleList() throws {
        let json = """
        {"people":[{"id":3,"name":"Ayşe","count":120,"cover_face_id":55,"cover_asset_id":"a1","hidden":false},
        {"id":9,"name":null,"count":4,"cover_face_id":71,"cover_asset_id":"a2","hidden":true},
        {"id":12,"name":"Ali","count":2,"cover_face_id":null,"cover_asset_id":null,"hidden":false}]}
        """
        let r = try JSONDecoder().decode(PeopleList.self, from: Data(json.utf8))
        XCTAssertEqual(r.people.count, 3)
        XCTAssertEqual(r.people[0].name, "Ayşe")
        XCTAssertEqual(r.people[0].coverFaceId, 55)
        XCTAssertTrue(r.people[0].isNamed)
        XCTAssertNil(r.people[1].name)
        XCTAssertFalse(r.people[1].isNamed)
        XCTAssertEqual(r.people[1].displayName, "Adsız")
        XCTAssertTrue(r.people[1].hidden)
        XCTAssertNil(r.people[2].coverFaceId)
        XCTAssertNil(r.people[2].coverAssetId)
        let env = try JSONDecoder().decode(PersonEnvelope.self, from: Data(#"{"person":{"id":3,"name":"Ayşe","count":1,"cover_face_id":null,"cover_asset_id":"x","hidden":false}}"#.utf8))
        XCTAssertEqual(env.person.id, 3)
        XCTAssertEqual(env.person.coverAssetId, "x")
    }

    func testDecodeAssetPeople() throws {
        let json = """
        {"people":[{"id":3,"name":"Ayşe","source":"manual"},{"id":9,"name":null,"source":"face"},{"id":4,"name":null,"source":"manual"}],
         "faces":[{"id":55,"x1":0.1,"y1":0.2,"x2":0.3,"y2":0.45,"person_id":3,"person_name":"Ayşe"},
                  {"id":56,"x1":0.5,"y1":0.2,"x2":0.6,"y2":0.4,"person_id":9,"person_name":null},
                  {"id":57,"x1":0.7,"y1":0.1,"x2":0.8,"y2":0.3,"person_id":null,"person_name":null}]}
        """
        let r = try JSONDecoder().decode(AssetPeople.self, from: Data(json.utf8))
        XCTAssertEqual(r.people.map(\.id), [3, 9, 4])
        XCTAssertEqual(r.people[0].source, "manual")
        XCTAssertEqual(r.faces.count, 3)
        XCTAssertEqual(r.faces[0].x2, 0.3, accuracy: 1e-9)
        XCTAssertEqual(r.face(of: 3)?.id, 55)
        XCTAssertTrue(r.faces[0].isNamed)
        XCTAssertFalse(r.faces[1].isNamed)
        XCTAssertNil(r.faces[2].personId)
        XCTAssertEqual(r.unnamedFaces.map(\.id), [56, 57])
        // Adsız küme (9) yüzüyle ayrıca gösterilir; yüzü olmayan adsız kişi (4) çip olarak kalır.
        XCTAssertEqual(r.chips.map(\.id), [3, 4])
        let empty = try JSONDecoder().decode(AssetPeople.self, from: Data("{}".utf8))
        XCTAssertTrue(empty.people.isEmpty)
        XCTAssertTrue(empty.faces.isEmpty)
    }

    func testDecodeFaceStatusAndConflict() throws {
        let s = try JSONDecoder().decode(FaceScanStatus.self, from: Data(#"{"available":true,"scanned":1240,"total":6500,"faces":3000,"people":40,"ready":false}"#.utf8))
        XCTAssertTrue(s.available)
        XCTAssertEqual(s.scanned, 1240)
        XCTAssertEqual(s.total, 6500)
        XCTAssertTrue(s.isScanning)
        let off = try JSONDecoder().decode(FaceScanStatus.self, from: Data(#"{"available":false}"#.utf8))
        XCTAssertFalse(off.available)
        XCTAssertFalse(off.isScanning)
        let done = FaceScanStatus(scanned: 10, total: 10, ready: true)
        XCTAssertFalse(done.isScanning)

        let conflict = try JSONDecoder().decode(PersonNameConflict.self, from: Data("""
        {"error":{"code":"name_exists","message":"Bu adda bir kişi zaten var.","person_id":7,
         "person":{"id":7,"name":"Ayşe","count":3,"cover_face_id":null,"cover_asset_id":null,"hidden":false}}}
        """.utf8))
        XCTAssertEqual(conflict.error.code, "name_exists")
        XCTAssertEqual(conflict.otherId, 7)
        XCTAssertEqual(conflict.error.person?.name, "Ayşe")
        let bare = try JSONDecoder().decode(PersonNameConflict.self, from: Data(#"{"error":{"code":"name_exists","message":"m","person_id":8}}"#.utf8))
        XCTAssertEqual(bare.otherId, 8)

        let bulk = try JSONDecoder().decode(BulkPeopleTagResponse.self, from: Data(#"{"count":2,"person":{"id":1,"name":"Ali","count":5,"cover_face_id":null,"cover_asset_id":null,"hidden":false}}"#.utf8))
        XCTAssertEqual(bulk.count, 2)
        XCTAssertEqual(bulk.person.name, "Ali")
    }
}
