<?php
declare(strict_types=1);

// GET /v1/assets/days — takvim: bir aydaki fotoğraflı günler (yerel gün), sayı, kapak ve atlama imleci.

dbtest('test_days_groups_by_local_day_with_cover_and_cursor', function () {
    $u = api_user();
    // 2026-11-03 22:30 UTC, +03:00 → yerel 2026-11-04 01:30
    $a = api_asset($u, '2026-11-03 22:30:00', 1);
    db_exec('UPDATE assets SET taken_tz_offset = 180 WHERE id = ?', [$a]);
    $b = api_asset($u, '2026-11-04 09:00:00', 2);
    db_exec('UPDATE assets SET taken_tz_offset = 180 WHERE id = ?', [$b]);
    // Dengeleme bilgisi yok → UTC günü
    $c = api_asset($u, '2026-11-03 10:00:00', 3);
    db_exec('UPDATE assets SET taken_tz_offset = NULL WHERE id = ?', [$c]);
    // Başka ay ve başka kullanıcı sayılmaz
    api_asset($u, '2026-10-31 12:00:00', 4);
    $other = api_user('b@example.com');
    api_asset($other, '2026-11-04 09:00:00', 5);

    $r = call('GET', '/v1/assets/days', $u, [], ['month' => '2026-11'])['json'];
    assert_eq('2026-11', $r['month']);
    assert_eq(['2026-11-04', '2026-11-03'], array_column($r['days'], 'day'), 'yeni gün önce');
    assert_eq([2, 1], array_column($r['days'], 'count'));
    assert_eq($b, $r['days'][0]['cover_asset_id'], 'kapak: günün en yeni öğesi');
    assert_eq($c, $r['days'][1]['cover_asset_id']);
    // before: günün en yeni öğesini de kapsayan imleç (en yeni taken_at + 1 sn)
    assert_eq('2026-11-04T09:00:01Z', $r['days'][0]['before']);
    $list = call('GET', '/v1/assets', $u, [], ['before' => $r['days'][0]['before'], 'limit' => 1])['json']['assets'];
    assert_eq($b, $list[0]['id']);
});

dbtest('test_days_respects_filters', function () {
    $u = api_user();
    $a = api_asset($u, '2026-11-10 12:00:00', 1);
    $b = api_asset($u, '2026-11-11 12:00:00', 2);
    $t = api_asset($u, '2026-11-12 12:00:00', 3);
    call('PATCH', "/v1/assets/$a", $u, ['is_favorite' => true]);
    call('PATCH', "/v1/assets/$b", $u, ['is_archived' => true]);
    call('POST', '/v1/assets/trash', $u, ['ids' => [$t]]);
    $days = fn(array $q) => array_column(call('GET', '/v1/assets/days', $u, [], ['month' => '2026-11'] + $q)['json']['days'], 'day');
    assert_eq(['2026-11-10'], $days([]), 'arşiv ve çöp ana çizelgede yok');
    assert_eq(['2026-11-10'], $days(['favorite' => '1']));
    assert_eq(['2026-11-11'], $days(['archived' => '1']));
    assert_eq(['2026-11-12'], $days(['trashed' => '1']));
    $alb = call('POST', '/v1/albums', $u, ['title' => 'Deniz'])['json']['album']['id'];
    call('POST', "/v1/albums/$alb/assets", $u, ['ids' => [$b]]);
    assert_eq(['2026-11-11'], $days(['album' => $alb]));
});

dbtest('test_days_validates_month', function () {
    $u = api_user();
    foreach (['', '2026-13', '2026-1', 'abc', '2026-00', '1899-01'] as $bad) {
        assert_eq(422, call_err('GET', '/v1/assets/days', $u, [], ['month' => $bad])->status, "ay: '$bad'");
    }
    assert_eq(401, call_err('GET', '/v1/assets/days', null, [], ['month' => '2026-11'])->status);
    assert_eq(422, call_err('GET', '/v1/assets/days', $u, [], ['month' => ['2026-11']])->status, 'dizi parametre');
    assert_eq([], call('GET', '/v1/assets/days', $u, [], ['month' => '2026-02'])['json']['days']);
    assert_eq([], call('GET', '/v1/assets/days', $u, [], ['month' => '9999-12'])['json']['days'], 'son ay: taşma yok');
});

dbtest('test_days_month_edges_follow_local_day', function () {
    $u = api_user();
    // 2026-12-01 03:00 UTC, -05:00 → yerel 2026-11-30 22:00 (Kasım'a sayılır)
    $a = api_asset($u, '2026-12-01 03:00:00', 1);
    db_exec('UPDATE assets SET taken_tz_offset = -300 WHERE id = ?', [$a]);
    // 2026-12-31 23:00 UTC, +02:00 → yerel 2027-01-01 01:00 (Aralık'a sayılmaz)
    $b = api_asset($u, '2026-12-31 23:00:00', 2);
    db_exec('UPDATE assets SET taken_tz_offset = 120 WHERE id = ?', [$b]);
    // 2026-12-31 20:00 UTC, UTC → Aralık'ın son günü
    $c = api_asset($u, '2026-12-31 20:00:00', 3);
    db_exec('UPDATE assets SET taken_tz_offset = NULL WHERE id = ?', [$c]);
    $days = fn(string $m) => array_map(fn($d) => [$d['day'], $d['count']], call('GET', '/v1/assets/days', $u, [], ['month' => $m])['json']['days']);
    assert_eq([['2026-11-30', 1]], $days('2026-11'));
    assert_eq([['2026-12-31', 1]], $days('2026-12'));
    assert_eq([['2027-01-01', 1]], $days('2027-01'));
    // Kasım'ın son gününe atlama imleci o öğeyi listenin başına getirir.
    $before = call('GET', '/v1/assets/days', $u, [], ['month' => '2026-11'])['json']['days'][0]['before'];
    assert_eq($a, call('GET', '/v1/assets', $u, [], ['before' => $before, 'limit' => 1])['json']['assets'][0]['id']);
});

dbtest('test_summary_months_use_local_day', function () {
    $u = api_user();
    // 2026-10-31 22:30 UTC, +03:00 → yerel Kasım
    $a = api_asset($u, '2026-10-31 22:30:00', 1);
    db_exec('UPDATE assets SET taken_tz_offset = 180 WHERE id = ?', [$a]);
    $m = call('GET', '/v1/assets/summary', $u)['json']['months'];
    assert_eq([['month' => '2026-11', 'count' => 1]], $m, 'ay başlıkları yerel günle gruplandığı için özet de öyle');
});
