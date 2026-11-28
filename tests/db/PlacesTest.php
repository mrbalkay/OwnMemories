<?php
declare(strict_types=1);

/** Fikstür GeoNames verisini (tests/fixtures/geo, gerçek dosyalardan alınmış satırlar) yükler. */
function places_fixture_import(): array
{
    return geo_import(fixture('geo'));
}

/** Konumlu bir test fotoğrafı ekler. */
function places_asset(int $u, float $lat, float $lng, string $takenUtc = '2024-06-01 10:00:00', int $seed = 1, array $extra = []): string
{
    $d = temp_dir();
    $i = imagecreatetruecolor(30 + $seed % 11, 20 + $seed % 5);
    imagefilledrectangle($i, 0, 0, 5, 5, imagecolorallocate($i, $seed % 256, ($seed * 7) % 256, 90));
    imagejpeg($i, "$d/p$seed.jpg");
    return asset_ingest($u, "$d/p$seed.jpg", ['filename' => "p$seed.jpg", 'source' => 'web', 'taken_at_utc' => $takenUtc, 'tz_offset' => 180, 'lat' => $lat, 'lng' => $lng] + $extra)['asset_id'];
}

function places_run_jobs(): void
{
    jobs_run_for(30, 'test');
}

dbtest('test_geo_import_loads_places_countries_and_turkish_names', function () {
    $r = places_fixture_import();
    assert_true($r['changed']);
    assert_true($r['places'] >= 20, 'yer sayısı: ' . $r['places']);
    assert_eq(null, db_val("SELECT id FROM geo_places WHERE name LIKE 'Paris 0%'"), 'numaralı bölge alınmaz');
    assert_eq(null, db_val("SELECT id FROM geo_places WHERE feature_code = 'ADM1'"), 'yalnızca yerleşimler');
    assert_eq('İstanbul', db_val('SELECT name_tr FROM geo_places WHERE id = 745044'), 'Türkçe yazım alternatenames içinden');
    assert_eq('Roma', db_val('SELECT name_tr FROM geo_places WHERE id = 3169070'));
    assert_eq('Çanakkale', db_val('SELECT admin1 FROM geo_places WHERE id = 320700'), 'il adı TR.txt ADM1');
    assert_eq('Kadıköy', db_val('SELECT admin2 FROM geo_places WHERE id = 741510'), 'ilçe adı');
    assert_eq('Türkiye', db_val("SELECT name_tr FROM geo_countries WHERE code = 'TR'"));
    assert_eq('Fransa', db_val("SELECT name_tr FROM geo_countries WHERE code = 'FR'"));
    // Aynı dosyalar: yeniden içe aktarılmaz
    assert_eq(false, places_fixture_import()['changed']);
    assert_eq(1, (int) db_val("SELECT COUNT(*) FROM jobs WHERE type = 'geocode_backfill'"), 'toplu iş bir kez');
});

dbtest('test_geo_lookup_turkish_places', function () {
    places_fixture_import();
    $moda = geo_lookup(40.9870, 29.0262);
    assert_eq('Moda', $moda['name']);
    assert_eq('Kadıköy', $moda['district']);
    assert_eq('İstanbul', $moda['city'], 'büyük şehrin merkez ilçeleri şehir adını alır');
    assert_eq('İstanbul', $moda['admin1']);
    assert_eq('Türkiye', $moda['country']);
    assert_eq('TR', $moda['country_code']);

    $als = geo_lookup(38.4380, 27.1430);
    assert_eq('Alsancak', $als['name']);
    assert_eq('İzmir', $als['city']);

    $gor = geo_lookup(38.6431, 34.8289);
    assert_eq('Göreme', $gor['name']);
    assert_eq('Göreme', $gor['city']);
    assert_eq('Nevşehir', $gor['admin1']);
    assert_eq('Kapadokya', $gor['region']);

    $boz = geo_lookup(39.8340, 26.0700);
    assert_eq('Bozcaada', $boz['city']);
    assert_eq('Çanakkale', $boz['admin1']);
    assert_eq(null, $boz['region']);

    $ank = geo_lookup(39.9200, 32.8540);
    assert_eq('Ankara', $ank['city']);
});

dbtest('test_geo_lookup_world_places_and_nowhere', function () {
    places_fixture_import();
    $p = geo_lookup(48.8606, 2.3376);
    assert_eq('Paris', $p['name']);
    assert_eq('Paris', $p['city']);
    assert_eq('Fransa', $p['country']);
    assert_eq(null, $p['district'], 'ilçe yalnız Türkiye için');
    assert_eq('Roma', geo_lookup(41.8986, 12.4769)['city']);
    assert_eq('Vatikan', geo_lookup(41.9022, 12.4539)['city']);
    assert_eq('İtalya', geo_lookup(41.8925, 12.5080)['country']);
    $b = geo_lookup(13.7500, 100.4913);
    assert_eq('Bangkok', $b['city']);
    assert_eq('Tayland', $b['country']);
    assert_eq(null, geo_lookup(35.0, -40.0), 'okyanus ortası');
});

dbtest('test_neighbourhood_belongs_to_big_city', function () {
    places_fixture_import();
    // Nice'teki "Libération" semti (PPL, 7 bin) Nice'in (PPLA2, 342 bin) 0,9 km yakınında: şehir Nice olmalı.
    $g = geo_lookup(43.7102, 7.2620);
    assert_eq('Libération', $g['name']);
    assert_eq('Nice', $g['city']);
    assert_eq(2990440, $g['city_id']);
    assert_eq("Nice'te", tr_locative($g['city']));
});

dbtest('test_place_json_labels', function () {
    places_fixture_import();
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $id = places_asset($u, 40.9870, 29.0262);
    places_run_jobs();
    $j = asset_to_json(db_row('SELECT * FROM assets WHERE id = ?', [$id]));
    assert_eq('Moda, Kadıköy, İstanbul, Türkiye', $j['place']['label']);
    assert_eq('Moda, Kadıköy', $j['place']['short']);
    assert_eq(745044, $j['place']['id']);
    $b = places_asset($u, 39.8340, 26.0700, '2024-06-02 10:00:00', 2);
    $p = places_asset($u, 48.8606, 2.3376, '2024-06-03 10:00:00', 3);
    places_run_jobs();
    $rows = db_all('SELECT * FROM assets WHERE id IN (?, ?)', [$b, $p]);
    $by = array_column(assets_json($rows), 'place', 'id');
    assert_eq('Bozcaada, Çanakkale', $by[$b]['short']);
    assert_eq('Bozcaada, Çanakkale, Türkiye', $by[$b]['label']);
    assert_eq('Paris, Fransa', $by[$p]['short']);
    assert_eq('Paris, Île-de-France, Fransa', $by[$p]['label']);
});

dbtest('test_geocode_jobs_follow_location_changes_and_purge', function () {
    places_fixture_import();
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $user = user_find($u);
    $id = places_asset($u, 40.9870, 29.0262);
    assert_true((int) db_val("SELECT COUNT(*) FROM jobs WHERE type = 'geocode'") >= 1, 'yükleme geocode işi koyar');
    places_run_jobs();
    assert_eq('Moda', db_val('SELECT name FROM asset_places WHERE asset_id = ?', [$id]));
    // Konum değişti → yeniden bulunur
    $patched = api_route('PATCH', "/v1/assets/$id", $user, ['latitude' => 39.8340, 'longitude' => 26.0700])['json']['asset'];
    assert_eq(null, $patched['place'], 'iş çalışmadan eski yer (Moda) gösterilmez');
    places_run_jobs();
    assert_eq('Bozcaada, Çanakkale', api_route('GET', "/v1/assets/$id", $user)['json']['asset']['place']['short']);
    assert_eq('Bozcaada', db_val('SELECT city FROM asset_places WHERE asset_id = ?', [$id]));
    // Konum silindi → yer silinir
    api_route('PATCH', "/v1/assets/$id", $user, ['latitude' => null, 'longitude' => null]);
    places_run_jobs();
    assert_eq(null, db_val('SELECT asset_id FROM asset_places WHERE asset_id = ?', [$id]));
    $j = api_route('GET', "/v1/assets/$id", $user)['json']['asset'];
    assert_eq(null, $j['place']);
    // Kalıcı silme yer satırını da siler
    $id2 = places_asset($u, 41.8986, 12.4769, '2024-01-01 10:00:00', 9);
    places_run_jobs();
    asset_trash($u, [$id2]);
    asset_purge($u, [$id2]);
    assert_eq(null, db_val('SELECT asset_id FROM asset_places WHERE asset_id = ?', [$id2]));
});

dbtest('test_geocode_backfill_is_batched_and_resumable', function () {
    geo_ready(true);
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    // Veri yokken yüklenenler: iş koyulmaz, toplu iş veri gelince işler
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = places_asset($u, 40.9870 + $i * 0.0001, 29.0262, '2024-06-01 1' . $i . ':00:00', 20 + $i);
    }
    $noloc = mem_asset($u, '2024-06-01 09:00:00', 40);
    assert_eq(0, (int) db_val("SELECT COUNT(*) FROM jobs WHERE type = 'geocode'"));
    assert_eq(5, places_pending_count($u));
    places_fixture_import();
    // İmleçle yarıda kalan bir çalışma: ilk iki kayıt işlendi
    $sorted = $ids;
    sort($sorted);
    places_geocode_asset($sorted[0]);
    places_geocode_asset($sorted[1]);
    assert_eq(3, places_pending_count($u));
    db_exec("DELETE FROM jobs WHERE type IN ('render','geocode_backfill')");
    job_enqueue('geocode_backfill', ['after' => $sorted[1]], 8);
    places_run_jobs();
    assert_eq(0, places_pending_count($u));
    assert_eq(5, (int) db_val('SELECT COUNT(*) FROM asset_places WHERE user_id = ?', [$u]));
    assert_eq(null, db_val('SELECT asset_id FROM asset_places WHERE asset_id = ?', [$noloc]));
});

dbtest('test_api_places_list_and_place_filter', function () {
    places_fixture_import();
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $user = user_find($u);
    $m1 = places_asset($u, 40.9870, 29.0262, '2024-06-01 10:00:00', 1);
    $m2 = places_asset($u, 41.0400, 29.0090, '2024-06-02 10:00:00', 2); // Beşiktaş → İstanbul
    $b1 = places_asset($u, 39.8340, 26.0700, '2023-08-01 10:00:00', 3, ['is_favorite' => true]);
    $b2 = places_asset($u, 39.8350, 26.0710, '2023-08-02 10:00:00', 4);
    $t = places_asset($u, 39.8340, 26.0700, '2023-08-03 10:00:00', 5);
    $other = user_create('veli@example.com', 'parola12345', 'Veli');
    places_asset($other, 48.8606, 2.3376, '2023-01-01 10:00:00', 6);
    places_run_jobs();
    asset_trash($u, [$t]);
    $places = api_route('GET', '/v1/places', $user)['json']['places'];
    assert_eq(2, count($places), 'başka kullanıcının yeri görünmez');
    assert_eq('İstanbul', $places[0]['name']);
    assert_eq(2, $places[0]['count']);
    assert_eq('Bozcaada', $places[1]['name']);
    assert_eq(2, $places[1]['count'], 'çöptekiler sayılmaz');
    assert_eq($b1, $places[1]['cover_asset_id'], 'favori kapak');
    assert_eq('Çanakkale', $places[1]['admin1']);
    assert_eq('Türkiye', $places[1]['country']);
    $list = api_route('GET', '/v1/assets', $user, [], ['place' => (string) $places[1]['id']])['json']['assets'];
    assert_eq([$b2, $b1], array_column($list, 'id'));
    assert_eq('Bozcaada', $list[0]['place']['city']);
    $one = api_route('GET', '/v1/places/' . $places[1]['id'], $user)['json']['place'];
    assert_eq(2, $one['count']);
    assert_eq('Bozcaada', $one['name']);
    assert_throws(ApiError::class, fn() => api_route('GET', '/v1/places/2988507', $user));
    assert_eq([], api_route('GET', '/v1/assets', $user, [], ['place' => '2988507'])['json']['assets'], 'başka kullanıcının yerindeki fotoğraflar görünmez');
    assert_eq([], api_route('GET', '/v1/assets/map', $user, [], [])['json']['points'] ? array_values(array_filter(api_route('GET', '/v1/assets/map', $user)['json']['points'], fn($p) => ($p['city'] ?? '') === 'Paris')) : [], 'harita: yabancı yer adı yok');
    assert_throws(ApiError::class, fn() => api_route('GET', '/v1/assets', $user, [], ['place' => 'abc']));
    unset($m1, $m2);
});

dbtest('test_text_search_finds_place_names_turkish_case_insensitive', function () {
    places_fixture_import();
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $user = user_find($u);
    $moda = places_asset($u, 40.9870, 29.0262, '2024-06-01 10:00:00', 1);
    $izmir = places_asset($u, 38.4380, 27.1430, '2024-06-02 10:00:00', 2);
    $paris = places_asset($u, 48.8606, 2.3376, '2024-06-03 10:00:00', 3);
    places_run_jobs();
    $q = fn(string $s) => array_column(api_route('GET', '/v1/search', $user, [], ['q' => $s])['json']['assets'], 'id');
    assert_eq([$moda], $q('Kadıköy'));
    assert_eq([$moda], $q('KADIKÖY'));
    assert_eq([$moda], $q('kadikoy'));
    assert_eq([$izmir], $q('İZMİR'));
    assert_eq([$izmir], $q('izmir'));
    assert_eq([$izmir], $q('Alsancak'));
    assert_eq([$paris], $q('Fransa'));
    assert_eq([$paris], $q('France'));
    assert_eq([$izmir, $moda], $q('Türkiye'));
});

dbtest('test_map_points_carry_place_names', function () {
    places_fixture_import();
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    places_asset($u, 39.8340, 26.0700);
    places_run_jobs();
    $pts = api_route('GET', '/v1/assets/map', user_find($u))['json']['points'];
    assert_eq('Bozcaada', $pts[0]['city']);
});

/** Evi İstanbul olan kullanıcı: 6 farklı günde İstanbul fotoğrafı. */
function places_home_istanbul(int $u, int $seed = 100): void
{
    for ($i = 0; $i < 6; $i++) {
        places_asset($u, 40.9870, 29.0262, sprintf('2025-0%d-10 10:00:00', $i + 1), $seed + $i);
    }
}

dbtest('test_memories_titles_use_place_names', function () {
    places_fixture_import();
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    places_home_istanbul($u);
    // 1 yıl önce bugün, tek gün Bozcaada (bir günlük gezi)
    places_asset($u, 39.8340, 26.0700, '2025-07-01 09:00:00', 1);
    places_asset($u, 39.8345, 26.0702, '2025-07-01 11:00:00', 2);
    places_asset($u, 39.8350, 26.0705, '2025-07-01 13:00:00', 3);
    // 2 yıl önce bugün, evde (İstanbul): başlık değişmez
    places_asset($u, 40.9870, 29.0262, '2024-07-01 10:00:00', 4);
    places_run_jobs();
    $m = memories_for($u, '2026-07-01');
    $titles = array_column($m, 'title', 'years_ago');
    assert_eq("Bozcaada'da bir gün", $titles[1]);
    assert_eq('trip', $m[0]['kind']);
    assert_eq('Bozcaada', $m[0]['place']);
    assert_eq('2 yıl önce bugün', $titles[2]);
});

dbtest('test_memories_on_this_day_title_with_place_when_not_a_trip', function () {
    places_fixture_import();
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    places_home_istanbul($u);
    places_asset($u, 41.8986, 12.4769, '2024-03-05 10:00:00', 1); // tek fotoğraf: gezi sayılmaz (en az 3)
    places_run_jobs();
    $m = memories_for($u, '2026-03-05');
    assert_eq("2 yıl önce bugün Roma'da", $m[0]['title']);
    assert_eq('on_this_day', $m[0]['kind']);
});

dbtest('test_memories_trip_titles_week_weekend_and_long', function () {
    places_fixture_import();
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    places_home_istanbul($u);
    // Bozcaada: 7 gün (2023-08-01 Salı .. 07 Pazartesi)
    for ($d = 1; $d <= 7; $d++) {
        places_asset($u, 39.8340, 26.0700, sprintf('2023-08-%02d 10:00:00', $d), 10 + $d);
    }
    // Göreme + Ürgüp (Kapadokya) 12 gün Kasım 2022
    for ($d = 10; $d <= 21; $d++) {
        places_asset($u, $d % 2 ? 38.6431 : 38.6320, $d % 2 ? 34.8289 : 34.9110, sprintf('2022-11-%02d 10:00:00', $d), 30 + $d);
    }
    // Paris hafta sonu: 2021-05-15 Cumartesi, 16 Pazar
    places_asset($u, 48.8606, 2.3376, '2021-05-15 10:00:00', 60);
    places_asset($u, 48.8606, 2.3376, '2021-05-15 12:00:00', 61);
    places_asset($u, 48.8610, 2.3380, '2021-05-16 10:00:00', 62);
    places_run_jobs();
    $m = memories_for($u, '2026-08-04');
    assert_eq("Bozcaada'da bir hafta", $m[0]['title']);
    assert_eq(3, $m[0]['years_ago']);
    assert_eq(7, $m[0]['count']);
    $m = memories_for($u, '2026-11-15');
    assert_eq('Kapadokya, Kasım 2022', $m[0]['title']);
    assert_eq(12, $m[0]['count']);
    $m = memories_for($u, '2026-05-16');
    assert_eq("Paris'te bir hafta sonu", $m[0]['title']);
    // Gezi yıldönümü penceresi dışında: gezi anısı yok
    assert_eq([], array_values(array_filter(memories_for($u, '2026-02-01'), fn($x) => $x['kind'] === 'trip')));
});
