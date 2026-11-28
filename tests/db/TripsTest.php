<?php
declare(strict_types=1);

/*
 * Geziler ve etkinlikler: saklama (trips, trip_assets), kararlı kimlikle yeniden hesaplama, yeniden adlandırma,
 * gizleme, albüme dönüştürme, API ve anılar. Yer verisi tests/fixtures/geo (PlacesTest ile aynı).
 */

/** İstanbul'da (Moda) yaşayan kullanıcı: 2024 boyunca her ay iki günde birer kare. */
function trips_home_istanbul(int $u, string $year = '2024', int $seed = 500): void
{
    for ($m = 1; $m <= 12; $m++) {
        places_asset($u, 40.9870, 29.0262, sprintf('%s-%02d-03 09:00:00', $year, $m), $seed + $m * 2);
        places_asset($u, 40.9875, 29.0265, sprintf('%s-%02d-21 15:00:00', $year, $m), $seed + $m * 2 + 1);
    }
}

/** Gün başına $n kare: [Y-m-d => [lat, lng]] (UTC 07:00'dan itibaren saatte bir). */
function trips_days(int $u, array $plan, int $n = 3, int $seed = 700): array
{
    $ids = [];
    foreach ($plan as $day => [$lat, $lng]) {
        for ($i = 0; $i < $n; $i++) {
            $ids[] = places_asset($u, $lat + $i * 0.0004, $lng, sprintf('%s %02d:00:00', $day, 7 + $i * 2), $seed++);
        }
    }
    return $ids;
}

const TRIPS_BOZCAADA = [39.8340, 26.0700];
const TRIPS_PARIS = [48.8606, 2.3376];
const TRIPS_NICE = [43.7102, 7.2620];

/** Ev + Bozcaada (12–15 Temmuz 2024) + Fransa (Paris 4–5, Nice 6–7 Mayıs 2024). */
function trips_fixture_library(): int
{
    places_fixture_import();
    return trips_fixture_library_for('ali@example.com');
}

function trips_fixture_library_for(string $email): int
{
    $u = user_create($email, 'parola12345', 'Ali');
    trips_home_istanbul($u);
    trips_days($u, ['2024-07-12' => TRIPS_BOZCAADA, '2024-07-13' => TRIPS_BOZCAADA, '2024-07-14' => TRIPS_BOZCAADA, '2024-07-15' => TRIPS_BOZCAADA], 3, 700);
    trips_days($u, ['2024-05-04' => TRIPS_PARIS, '2024-05-05' => TRIPS_PARIS, '2024-05-06' => TRIPS_NICE, '2024-05-07' => TRIPS_NICE], 3, 800);
    places_run_jobs();
    return $u;
}

function trips_api(int $u, string $method, string $path, array $body = [], array $q = []): array
{
    return api_route($method, $path, user_find($u), $body, $q)['json'];
}

dbtest('test_trips_recompute_creates_trips_and_is_idempotent', function () {
    $u = trips_fixture_library();
    $r = trips_recompute($u);
    assert_eq(2, $r['created']);
    $list = trips_api($u, 'GET', '/v1/trips')['trips'];
    assert_eq(['Bozcaada gezisi, 12–15 Temmuz 2024', 'Fransa: Paris ve Nice'], array_column($list, 'title'));
    $boz = $list[0];
    assert_eq('trip', $boz['kind']);
    assert_eq(12, $boz['count']);
    assert_eq('2024-07-12', $boz['start_day']);
    assert_eq('2024-07-15', $boz['end_day']);
    assert_eq(4, $boz['days']);
    assert_eq('12–15 Temmuz 2024', $boz['range_text']);
    assert_eq(['Bozcaada'], array_column($boz['places'], 'name'));
    assert_true(is_uuid((string) $boz['cover_asset_id']));
    assert_eq(null, $boz['album_id']);
    assert_eq(true, $boz['title_auto']);
    assert_eq(['Paris', 'Nice'], array_column($list[1]['places'], 'name'));
    $before = db_all('SELECT id, title, updated_at, fingerprint FROM trips ORDER BY id');
    // Yeniden çalıştırma: yinelenen kayıt, ad değişikliği ya da yazma yok
    $r = trips_recompute($u, ['force' => true]);
    assert_eq(['created' => 0, 'updated' => 0, 'deleted' => 0], array_intersect_key($r, array_flip(['created', 'updated', 'deleted'])));
    assert_eq($before, db_all('SELECT id, title, updated_at, fingerprint FROM trips ORDER BY id'));
    assert_eq(24, (int) db_val('SELECT COUNT(*) FROM trip_assets'));
    // Kütüphane değişmediyse hesaplama hiç yapılmaz
    assert_eq(true, trips_recompute($u)['skipped']);
});

dbtest('test_trips_rename_persists_and_trip_keeps_id_when_it_grows', function () {
    $u = trips_fixture_library();
    trips_recompute($u);
    $id = (int) db_val("SELECT id FROM trips WHERE title LIKE 'Bozcaada%'");
    $t = trips_api($u, 'PATCH', "/v1/trips/$id", ['title' => '  Ada tatili  '])['trip'];
    assert_eq('Ada tatili', $t['title']);
    assert_eq(false, $t['title_auto']);
    // Gezi bir gün uzar: aynı kimlik, kullanıcının adı korunur
    trips_days($u, ['2024-07-16' => TRIPS_BOZCAADA], 2, 900);
    places_run_jobs();
    trips_recompute($u);
    $t = trips_api($u, 'GET', "/v1/trips/$id")['trip'];
    assert_eq('Ada tatili', $t['title']);
    assert_eq('2024-07-16', $t['end_day']);
    assert_eq(14, $t['count']);
    assert_eq(2, (int) db_val('SELECT COUNT(*) FROM trips'));
    // Boş ad: otomatik ada döner
    $t = trips_api($u, 'PATCH', "/v1/trips/$id", ['title' => ''])['trip'];
    assert_eq('Bozcaada gezisi, 12–16 Temmuz 2024', $t['title']);
    assert_eq(true, $t['title_auto']);
    assert_throws(ApiError::class, fn() => trips_api($u, 'PATCH', "/v1/trips/$id", ['title' => str_repeat('a', 300)]));
});

dbtest('test_trips_dismiss_hides_and_survives_recompute', function () {
    $u = trips_fixture_library();
    trips_recompute($u);
    $id = (int) db_val("SELECT id FROM trips WHERE title LIKE 'Fransa%'");
    assert_eq(true, trips_api($u, 'DELETE', "/v1/trips/$id")['ok']);
    assert_eq(['Bozcaada gezisi, 12–15 Temmuz 2024'], array_column(trips_api($u, 'GET', '/v1/trips')['trips'], 'title'));
    trips_recompute($u, ['force' => true]);
    assert_eq(1, count(trips_api($u, 'GET', '/v1/trips')['trips']), 'gizlenen gezi geri gelmez');
    $hidden = trips_api($u, 'GET', '/v1/trips', [], ['dismissed' => '1'])['trips'];
    assert_eq([$id], array_column($hidden, 'id'));
    assert_eq(true, $hidden[0]['dismissed']);
    trips_api($u, 'PATCH', "/v1/trips/$id", ['dismissed' => false]);
    assert_eq(2, count(trips_api($u, 'GET', '/v1/trips')['trips']));
});

dbtest('test_trips_convert_to_album_keeps_link_and_is_idempotent', function () {
    $u = trips_fixture_library();
    trips_recompute($u);
    $trip = trips_api($u, 'GET', '/v1/trips')['trips'][0];
    $r = api_route('POST', "/v1/trips/{$trip['id']}/album", user_find($u));
    assert_eq(201, $r['status']);
    $album = $r['json']['album'];
    assert_eq('Bozcaada gezisi, 12–15 Temmuz 2024', $album['title']);
    assert_eq(12, $album['count']);
    assert_eq($trip['cover_asset_id'], $album['cover_asset_id']);
    assert_eq($album['id'], $r['json']['trip']['album_id']);
    $again = api_route('POST', "/v1/trips/{$trip['id']}/album", user_find($u));
    assert_eq(200, $again['status']);
    assert_eq($album['id'], $again['json']['album']['id']);
    assert_eq(1, (int) db_val('SELECT COUNT(*) FROM albums'));
    trips_recompute($u, ['force' => true]);
    assert_eq($album['id'], trips_api($u, 'GET', "/v1/trips/{$trip['id']}")['trip']['album_id'], 'yeniden hesaplama bağı korur');
    // Albüm silinirse gezi yeniden dönüştürülebilir
    album_delete($album['id'], $u);
    assert_eq(null, trips_api($u, 'GET', "/v1/trips/{$trip['id']}")['trip']['album_id']);
    $third = api_route('POST', "/v1/trips/{$trip['id']}/album", user_find($u));
    assert_eq(201, $third['status']);
});

dbtest('test_trips_assets_filter_and_access', function () {
    $u = trips_fixture_library();
    trips_recompute($u);
    $trip = trips_api($u, 'GET', '/v1/trips')['trips'][1]; // Fransa
    $assets = trips_api($u, 'GET', '/v1/assets', [], ['trip' => (string) $trip['id']])['assets'];
    assert_eq(12, count($assets));
    assert_eq(['FR'], array_values(array_unique(array_map(fn($a) => $a['place']['country_code'], $assets))));
    $months = trips_api($u, 'GET', '/v1/assets/summary', [], ['trip' => (string) $trip['id']])['months'];
    assert_eq([['month' => '2024-05', 'count' => 12]], $months);
    $detail = trips_api($u, 'GET', "/v1/trips/{$trip['id']}")['trip'];
    assert_true(count($detail['route']) >= 2, 'rota noktaları');
    assert_eq(2, count(array_filter($detail['places'], fn($p) => isset($p['lat'], $p['lng']))));
    $other = user_create('veli@example.com', 'parola12345', 'Veli');
    foreach ([fn() => trips_api($other, 'GET', "/v1/trips/{$trip['id']}"), fn() => trips_api($other, 'GET', '/v1/assets', [], ['trip' => (string) $trip['id']]),
        fn() => trips_api($other, 'POST', "/v1/trips/{$trip['id']}/album"), fn() => trips_api($other, 'PATCH', "/v1/trips/{$trip['id']}", ['title' => 'x']),
        fn() => trips_api($u, 'GET', '/v1/assets', [], ['trip' => 'abc'])] as $fn) {
        $e = assert_throws(ApiError::class, $fn);
        assert_eq(404, $e->status);
    }
});

dbtest('test_trips_trashed_photos_remove_untouched_trip_but_keep_renamed_while_assets_remain', function () {
    $u = trips_fixture_library();
    trips_recompute($u);
    $fr = (int) db_val("SELECT id FROM trips WHERE title LIKE 'Fransa%'");
    $boz = (int) db_val("SELECT id FROM trips WHERE title LIKE 'Bozcaada%'");
    trips_api($u, 'PATCH', "/v1/trips/$boz", ['title' => 'Ada']);
    asset_trash($u, array_column(db_all('SELECT asset_id FROM trip_assets WHERE trip_id = ?', [$fr]), 'asset_id'));
    // Bozcaada'nın 12 karesinden 10'u çöpe: kalan 2 kare gezi sayılmaz ama adlandırılmış gezi kalır
    $bozIds = array_column(db_all('SELECT asset_id FROM trip_assets WHERE trip_id = ? ORDER BY asset_id', [$boz]), 'asset_id');
    asset_trash($u, array_slice($bozIds, 0, 10));
    $r = trips_recompute($u);
    assert_eq(1, $r['deleted']);
    assert_eq(null, db_val('SELECT id FROM trips WHERE id = ?', [$fr]));
    assert_eq(2, (int) db_val('SELECT asset_count FROM trips WHERE id = ?', [$boz]));
    asset_trash($u, $bozIds);
    trips_recompute($u);
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM trips'), 'öğesi kalmayan gezi silinir');
});

dbtest('test_trips_job_is_registered_and_debounced', function () {
    $u = trips_fixture_library();
    assert_true(isset(job_handlers()['trips']));
    $n = (int) db_val("SELECT COUNT(*) FROM jobs WHERE type = 'trips'");
    assert_eq(1, $n, 'yükleme/yer bulma tek bir gecikmeli gezi işi koyar');
    trips_schedule($u);
    trips_schedule($u);
    assert_eq(1, (int) db_val("SELECT COUNT(*) FROM jobs WHERE type = 'trips' AND status = 'queued'"));
    db_exec("UPDATE jobs SET run_after = ? WHERE type = 'trips'", [now_utc()]);
    jobs_run_for(20, 'test');
    assert_eq('done', db_val("SELECT status FROM jobs WHERE type = 'trips'"));
    assert_eq(2, (int) db_val('SELECT COUNT(*) FROM trips WHERE user_id = ?', [$u]));
    // Gece bakımı: tüm kullanıcılar için kuyruğa
    db_exec('DELETE FROM jobs');
    trips_enqueue_all();
    assert_eq(1, (int) db_val("SELECT COUNT(*) FROM jobs WHERE type = 'trips'"));
});

dbtest('test_trips_first_list_computes_synchronously', function () {
    $u = trips_fixture_library();
    $r = trips_api($u, 'GET', '/v1/trips');
    assert_eq(2, count($r['trips']));
    assert_true($r['status']['computed_at'] !== null);
});

dbtest('test_trips_memories_use_stored_trips', function () {
    places_fixture_import();
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    trips_home_istanbul($u, '2025');
    trips_days($u, ['2025-11-25' => TRIPS_BOZCAADA, '2025-11-26' => TRIPS_BOZCAADA, '2025-11-27' => TRIPS_BOZCAADA, '2025-11-28' => TRIPS_BOZCAADA], 3, 700);
    places_run_jobs();
    // Gezi henüz hesaplanmadı: Yerler'in anlık gezi anısı
    $m = memories_for($u, '2026-11-27');
    assert_eq("Bozcaada'da 4 gün", $m[0]['title']);
    trips_recompute($u);
    $m = memories_for($u, '2026-11-27');
    assert_eq('trip', $m[0]['kind']);
    assert_eq('Bozcaada gezisi', $m[0]['title']);
    assert_eq('1 yıl önce bu hafta: Bozcaada gezisi', $m[0]['label']);
    assert_eq((int) db_val('SELECT id FROM trips'), $m[0]['trip_id']);
    assert_eq(null, $m[0]['album_id']);
    assert_eq(12, $m[0]['count']);
    // Kullanıcının verdiği ad anıda da görünür; gizlenen gezi anı olmaz
    $id = $m[0]['trip_id'];
    trips_api($u, 'PATCH', "/v1/trips/$id", ['title' => 'Ada kaçamağı']);
    assert_eq('Ada kaçamağı', memories_for($u, '2026-11-27')[0]['title']);
    trips_api($u, 'DELETE', "/v1/trips/$id");
    assert_eq([], array_values(array_filter(memories_for($u, '2026-11-27'), fn($x) => $x['kind'] === 'trip')));
});

dbtest('test_trips_event_label_from_ml', function () {
    places_fixture_import();
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    trips_home_istanbul($u, '2025');
    $party = [];
    for ($i = 0; $i < 14; $i++) {
        $party[] = places_asset($u, 40.9870, 29.0262, sprintf('2025-02-14 %02d:%02d:00', 16 + intdiv($i * 9, 60), ($i * 9) % 60), 900 + $i);
    }
    places_run_jobs();
    $asked = [];
    ml_client_override(function (string $method, string $path, array $p) use ($party, &$asked) {
        if ($path === '/health') {
            return ['ok' => true];
        }
        if ($path !== '/search') {
            return null;
        }
        $asked[] = $p['text'];
        $score = $p['text'] === 'doğum günü' ? 0.31 : 0.2;
        return ['results' => array_map(fn($id) => ['id' => $id, 'score' => $score], $p['text'] === 'kar' ? [] : $party)];
    });
    try {
        trips_recompute($u, ['ml' => true]);
    } finally {
        ml_client_override(null);
    }
    $ev = trips_api($u, 'GET', '/v1/trips', [], ['kind' => 'event'])['trips'];
    assert_eq(1, count($ev));
    assert_eq('Doğum günü, 14 Şubat 2025', $ev[0]['title']);
    assert_eq('birthday', $ev[0]['label']);
    assert_eq(14, $ev[0]['count']);
    assert_true(in_array('düğün', $asked, true));
    // Etiket bir kez hesaplanır: aynı etkinlik için ML yeniden sorulmaz
    $asked = [];
    ml_client_override(function (string $method, string $path) use (&$asked) {
        $asked[] = $path;
        return null;
    });
    try {
        trips_recompute($u, ['ml' => true, 'force' => true]);
    } finally {
        ml_client_override(null);
    }
    assert_eq('Doğum günü, 14 Şubat 2025', trips_api($u, 'GET', '/v1/trips', [], ['kind' => 'event'])['trips'][0]['title']);
    assert_eq([], array_values(array_filter($asked, fn($p) => $p === '/search')));
});

dbtest('test_trips_memory_replaces_weekly_memory_made_of_trip_photos', function () {
    places_fixture_import();
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    trips_home_istanbul($u, '2025');
    // Gezi 20–24 Kasım 2025; 27 Kasım 2026'nın ±3 gün penceresine (24–30) yalnız son günü düşer
    trips_days($u, ['2025-11-20' => TRIPS_PARIS, '2025-11-21' => TRIPS_PARIS, '2025-11-22' => TRIPS_PARIS, '2025-11-23' => TRIPS_PARIS, '2025-11-24' => TRIPS_PARIS], 2, 700);
    places_run_jobs();
    trips_recompute($u);
    $m = array_values(array_filter(memories_for($u, '2026-11-27'), fn($x) => $x['years_ago'] === 1));
    assert_eq(1, count($m), 'haftalık anı ile gezi anısı yinelenmez');
    assert_eq('trip', $m[0]['kind']);
    assert_eq('Paris gezisi', $m[0]['title']);
    assert_eq(10, $m[0]['count']);
});

dbtest('test_trips_first_list_defers_large_library_to_job', function () {
    $u = trips_fixture_library();
    $GLOBALS['__om_trips_sync_max'] = 10; // fixture: 48 kare > 10 → web isteğinde hesaplanmaz
    try {
        $r = trips_api($u, 'GET', '/v1/trips');
    } finally {
        unset($GLOBALS['__om_trips_sync_max']);
    }
    assert_eq([], $r['trips']);
    assert_eq(null, $r['status']['computed_at']);
    assert_eq(true, $r['status']['pending']);
    assert_eq(1, (int) db_val("SELECT COUNT(*) FROM jobs WHERE type = 'trips' AND status = 'queued'"));
    jobs_run_for(30, 'test');
    assert_eq(2, count(trips_api($u, 'GET', '/v1/trips')['trips']));
});

dbtest('test_trips_purge_removes_trip_links_and_cover', function () {
    $u = trips_fixture_library();
    trips_recompute($u);
    $t = db_row("SELECT id, cover_asset_id, asset_count FROM trips WHERE title LIKE 'Bozcaada%'");
    asset_trash($u, [$t['cover_asset_id']]);
    asset_purge($u, [$t['cover_asset_id']]);
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM trip_assets WHERE asset_id = ?', [$t['cover_asset_id']]));
    $after = db_row('SELECT cover_asset_id, asset_count FROM trips WHERE id = ?', [(int) $t['id']]);
    assert_true($after['cover_asset_id'] !== $t['cover_asset_id']);
    assert_eq((int) $t['asset_count'] - 1, (int) $after['asset_count']);
});

dbtest('test_trips_ignore_photos_shared_by_others_and_other_users_trips', function () {
    places_fixture_import();
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    trips_home_istanbul($u);
    // Başkasının paylaştığı albümden gelen (Takeout "from_shared") Paris kareleri kullanıcının gezisi değildir
    foreach (['2024-05-04', '2024-05-05', '2024-05-06'] as $k => $day) {
        for ($i = 0; $i < 3; $i++) {
            places_asset($u, TRIPS_PARIS[0], TRIPS_PARIS[1], sprintf('%s %02d:00:00', $day, 8 + $i), 900 + $k * 3 + $i, ['shared_from_others' => true]);
        }
    }
    places_run_jobs();
    trips_recompute($u);
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM trips'));
    // Başka kullanıcının gezisi bu kullanıcının listesinde, gizleme ya da albüm işlemlerinde görünmez
    $v = trips_fixture_library_for('veli@example.com');
    trips_recompute($v);
    $vt = (int) db_val('SELECT id FROM trips WHERE user_id = ? LIMIT 1', [$v]);
    assert_eq([], trips_api($u, 'GET', '/v1/trips')['trips']);
    foreach ([fn() => trips_api($u, 'DELETE', "/v1/trips/$vt"), fn() => trips_api($u, 'PATCH', "/v1/trips/$vt", ['dismissed' => true])] as $fn) {
        assert_eq(404, assert_throws(ApiError::class, $fn)->status);
    }
    assert_eq(0, (int) db_val('SELECT dismissed FROM trips WHERE id = ?', [$vt]));
});

dbtest('test_trips_titles_use_user_locale_not_request_locale', function () {
    $u = trips_fixture_library();
    $prev = locale();
    try {
        locale('en'); // ör. iPhone'u İngilizce olan kullanıcının ilk isteği
        trips_recompute($u);
        locale('tr'); // gece işi (CLI)
        $r = trips_recompute($u);
    } finally {
        locale($prev);
    }
    assert_eq(true, $r['skipped'], 'dil değişince yeniden hesaplanıp başlıklar değişmez');
    assert_eq(['Bozcaada gezisi, 12–15 Temmuz 2024', 'Fransa: Paris ve Nice'], array_column(db_all('SELECT title FROM trips ORDER BY start_at DESC'), 'title'));
});

dbtest('test_trips_patch_rejects_non_string_values', function () {
    $u = trips_fixture_library();
    trips_recompute($u);
    $id = (int) db_val('SELECT id FROM trips LIMIT 1');
    foreach ([['title' => ['x']], ['cover_asset_id' => ['x']], ['dismissed' => ['x']]] as $b) {
        assert_eq(422, assert_throws(ApiError::class, fn() => trips_api($u, 'PATCH', "/v1/trips/$id", $b))->status);
    }
    assert_eq(1, (int) db_val('SELECT title_auto FROM trips WHERE id = ?', [$id]));
    assert_eq(404, assert_throws(ApiError::class, fn() => trips_api($u, 'GET', '/v1/assets', [], ['trip' => ['1']]))->status);
});
