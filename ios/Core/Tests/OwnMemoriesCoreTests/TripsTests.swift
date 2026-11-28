import XCTest
@testable import OwnMemoriesCore

/// Geziler ve etkinlikler: sunucu JSON'u (GET /v1/trips/{id}, /v1/memories) çözülür.
final class TripsTests: XCTestCase {
    let tripJSON = #"""
    {"trip":{"id":2,"kind":"trip","label":null,"label_text":null,"title":"İtalya: Roma, Floransa ve Venedik","short_title":"İtalya: Roma, Floransa ve Venedik","title_auto":true,"start_at":"2024-05-04T07:00:00Z","end_at":"2024-05-10T16:46:00Z","start_day":"2024-05-04","end_day":"2024-05-10","days":7,"range_text":"4–10 Mayıs 2024","places":[{"name":"Roma","country":"İtalya","country_code":"IT","city_id":3169070,"count":10,"lat":41.8989,"lng":12.4766},{"name":"Floransa","country":"İtalya","country_code":"IT","city_id":3176959,"count":6,"lat":43.7698,"lng":11.2557}],"countries":[{"cc":"IT","name":"İtalya","count":22}],"cover_asset_id":"00b5a17c-bb07-4e08-b9e0-c12ae70ca6cf","count":24,"photo_count":24,"video_count":0,"distance_km":1487.1,"dismissed":false,"album_id":null,"updated_at":"2026-11-28T17:42:51Z","route":[{"lat":41.89905,"lng":12.47654},{"lat":43.77185,"lng":11.254}]}}
    """#

    func testDecodeTrip() throws {
        let t = try JSONDecoder().decode(TripEnvelope.self, from: Data(tripJSON.utf8)).trip
        XCTAssertEqual(t.displayTitle, "İtalya: Roma, Floransa ve Venedik")
        XCTAssertEqual(t.kindText, "Gezi")
        XCTAssertEqual(t.daysText, "7 gün")
        XCTAssertEqual(t.year, "2024")
        XCTAssertEqual(t.places.map(\.name), ["Roma", "Floransa"])
        XCTAssertEqual(t.route?.count, 2)
        XCTAssertNil(t.albumId)
    }

    func testDecodeEventAndRenamedTitle() throws {
        let json = #"{"trips":[{"id":5,"kind":"event","label":"birthday","label_text":"Doğum günü","title":"Annemin doğum günü","short_title":"Annemin doğum günü","title_auto":false,"start_day":"2025-02-14","end_day":"2025-02-14","days":1,"range_text":"14 Şubat 2025","places":[],"cover_asset_id":null,"count":24,"distance_km":0.4,"dismissed":false,"album_id":"a1"}],"hidden":1}"#
        let r = try JSONDecoder().decode(TripsResponse.self, from: Data(json.utf8))
        XCTAssertEqual(r.hidden, 1)
        let e = r.trips[0]
        XCTAssertTrue(e.isEvent)
        XCTAssertEqual(e.kindText, "Doğum günü")
        XCTAssertEqual(e.daysText, "Tek gün")
        XCTAssertEqual(e.displayTitle, "Annemin doğum günü")
        XCTAssertEqual(e.albumId, "a1")
    }
}
