<?php
declare(strict_types=1);

function mem_asset(int $u, string $takenUtc, int $seed, array $extra = []): string
{
    $d = temp_dir();
    $i = imagecreatetruecolor(40 + $seed % 7, 30);
    imagefilledrectangle($i, 0, 0, 8, 8, imagecolorallocate($i, $seed % 256, ($seed * 3) % 256, 50));
    imagejpeg($i, "$d/m$seed.jpg");
    return asset_ingest($u, "$d/m$seed.jpg", ['filename' => "m$seed.jpg", 'source' => 'web', 'taken_at_utc' => $takenUtc, 'tz_offset' => 180] + $extra)['asset_id'];
}

dbtest('test_memories_on_this_day_grouped_by_year', function () {
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $a1 = mem_asset($u, '2025-07-01 09:00:00', 1);               // 1 yıl önce, yerel 12:00
    $a2 = mem_asset($u, '2025-07-01 15:00:00', 2, ['is_favorite' => true]);
    $b1 = mem_asset($u, '2023-07-01 07:00:00', 3);               // 3 yıl önce
    mem_asset($u, '2025-06-20 10:00:00', 4);                      // başka gün
    mem_asset($u, '2026-07-01 10:00:00', 5);                      // bu yıl: anı değil
    $late = mem_asset($u, '2024-06-30 22:30:00', 6);              // UTC 30 Haziran, yerel 1 Temmuz 01:30 → 2 yıl önce
    $m = memories_for($u, '2026-07-01');
    $otd = array_values(array_filter($m, fn($x) => $x['kind'] === 'on_this_day'));
    assert_eq([1, 2, 3], array_column($otd, 'years_ago'));
    assert_eq('1 yıl önce bugün', $otd[0]['title']);
    assert_eq([$a1, $a2], $otd[0]['asset_ids']);
    assert_eq($a2, $otd[0]['cover_asset_id'], 'favori kapak olur');
    assert_eq([$late], $otd[1]['asset_ids'], 'yerel saate göre gün');
    assert_eq([$b1], $otd[2]['asset_ids']);
    assert_eq(2, count($otd[0]['assets']));
});

dbtest('test_memories_fall_back_to_this_week', function () {
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $x = mem_asset($u, '2024-03-03 10:00:00', 1);   // 2 yıl önce, 2 gün sonra
    mem_asset($u, '2024-03-20 10:00:00', 2);        // pencere dışı
    $m = memories_for($u, '2026-03-01');
    assert_eq(1, count($m));
    assert_eq('this_week', $m[0]['kind']);
    assert_eq('2 yıl önce bu hafta', $m[0]['title']);
    assert_eq([$x], $m[0]['asset_ids']);
});

dbtest('test_memories_exclude_trashed_archived_and_hidden_pairs', function () {
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $ok = mem_asset($u, '2025-05-05 10:00:00', 1);
    $t = mem_asset($u, '2025-05-05 11:00:00', 2);
    asset_trash($u, [$t]);
    mem_asset($u, '2025-05-05 12:00:00', 3, ['is_archived' => true]);
    $orig = mem_asset($u, '2025-05-05 13:00:00', 4);
    $edit = mem_asset($u, '2025-05-05 13:00:01', 5);
    db_exec('UPDATE assets SET edited_of_id = ? WHERE id = ?', [$orig, $edit]);
    $m = memories_for($u, '2026-05-05');
    assert_eq([$ok, $edit], $m[0]['asset_ids']);
});

dbtest('test_memories_favorites_highlight_is_stable_per_day', function () {
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    for ($i = 0; $i < 6; $i++) {
        mem_asset($u, sprintf('2022-0%d-1%d 10:00:00', $i + 1, $i), 10 + $i, ['is_favorite' => true]);
    }
    $a = array_values(array_filter(memories_for($u, '2026-11-27'), fn($x) => $x['kind'] === 'favorites'));
    $b = array_values(array_filter(memories_for($u, '2026-11-27'), fn($x) => $x['kind'] === 'favorites'));
    assert_eq(1, count($a));
    assert_eq('Favorilerinizden', $a[0]['title']);
    assert_eq($a[0]['asset_ids'], $b[0]['asset_ids'], 'aynı gün aynı seçim');
    assert_eq(6, count($a[0]['asset_ids']));
});

dbtest('test_memories_api_and_isolation', function () {
    $u = user_find(user_create('ali@example.com', 'parola12345', 'Ali'));
    $o = user_find(user_create('b@example.com', 'parola12345', 'B'));
    mem_asset((int) $u['id'], '2025-01-10 10:00:00', 1);
    $r = api_route('GET', '/v1/memories', $u, [], ['date' => '2026-01-10'])['json'];
    assert_eq('2026-01-10', $r['date']);
    assert_eq(1, count($r['memories']));
    assert_eq([], api_route('GET', '/v1/memories', $o, [], ['date' => '2026-01-10'])['json']['memories']);
    assert_eq(422, assert_throws(ApiError::class, fn() => api_route('GET', '/v1/memories', $u, [], ['date' => 'bozuk']))->status);
    $today = api_route('GET', '/v1/memories', $u)['json']['date'];
    assert_true((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $today));
});

dbtest('test_random_assets_endpoint', function () {
    $u = user_find(user_create('ali@example.com', 'parola12345', 'Ali'));
    $ids = [];
    for ($i = 0; $i < 8; $i++) {
        $ids[] = mem_asset((int) $u['id'], sprintf('2024-0%d-10 10:00:00', $i + 1), 30 + $i);
    }
    $recent = mem_asset((int) $u['id'], gmdate('Y-m-d H:i:s', time() - 86400), 50);
    asset_trash((int) $u['id'], [$ids[0]]);
    $r = api_route('GET', '/v1/assets/random', $u, [], ['count' => '5'])['json']['assets'];
    assert_eq(5, count($r));
    $got = array_column($r, 'id');
    assert_true(!in_array($ids[0], $got, true), 'çöptekiler yok');
    assert_true(!in_array($recent, $got, true), 'son 30 gün yok');
    assert_eq(7, count(api_route('GET', '/v1/assets/random', $u, [], ['count' => '50'])['json']['assets']));
});

dbtest('test_this_week_across_new_year_uses_window_year', function () {
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $jan = mem_asset($u, '2026-01-02 09:00:00', 1);   // 2025-12-30 penceresine düşer (yy=2025) → 1 yıl önce
    $dec = mem_asset($u, '2024-12-28 09:00:00', 2);   // yy=2024 → 2 yıl önce
    $m = memories_for($u, '2026-12-30');
    assert_eq(['this_week', 'this_week'], array_column($m, 'kind'));
    assert_eq([1, 2], array_column($m, 'years_ago'));
    assert_eq([$jan], $m[0]['asset_ids']);
    assert_eq('2025-12-30', $m[0]['date']);
    assert_eq([$dec], $m[1]['asset_ids']);
});

dbtest('test_feb29_request_clamps_window_and_label', function () {
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $x = mem_asset($u, '2023-02-27 09:00:00', 1);
    $m = memories_for($u, '2024-02-29');
    assert_eq(1, count($m));
    assert_eq('2023-02-28', $m[0]['date'], 'artık yıl olmayan yılda 28 Şubat');
    assert_eq([$x], $m[0]['asset_ids']);
});

dbtest('test_memories_rejects_far_dates', function () {
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    assert_throws(ValidationError::class, fn() => memories_for($u, '9999-06-15'));
    assert_throws(ValidationError::class, fn() => memories_for($u, '1850-01-01'));
});

dbtest('test_favorites_need_one_full_year', function () {
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    foreach (['2025-12-20', '2025-12-25', '2025-12-28'] as $i => $d) {
        mem_asset($u, "$d 10:00:00", 60 + $i, ['is_favorite' => true]);
    }
    $fav = array_filter(memories_for($u, '2026-01-02'), fn($x) => $x['kind'] === 'favorites');
    assert_eq([], array_values($fav), 'birkaç haftalık favoriler anı değil');
});
