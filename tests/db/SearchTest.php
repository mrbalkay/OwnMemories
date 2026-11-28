<?php
/**
 * Akıllı arama: GET /v1/search ve /v1/search/status (ML hizmeti ml_client_override ile taklit edilir).
 */
declare(strict_types=1);

/** Sözleşmedeki tablo (ML ajanı schema.sql'e ekleyene kadar test kurulumunda oluşturulur). */
function search_ensure_table(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS asset_embeddings (
        asset_id CHAR(36) NOT NULL PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        model VARCHAR(80) NOT NULL,
        vec MEDIUMBLOB NOT NULL,
        created_at DATETIME NOT NULL,
        KEY ix_emb_user (user_id),
        KEY ix_emb_model (model)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/**
 * ML hizmetini taklit eder. $ranked: /search sonucu (id listesi; puan sırayla azalır) ya da null (erişilemiyor).
 * Çağrılar $GLOBALS['__ml_calls'] içine yazılır.
 */
function search_stub(?array $ranked, ?array $status = null): void
{
    $GLOBALS['__ml_calls'] = [];
    ml_client_override(function (string $method, string $path, array $payload) use ($ranked, $status): ?array {
        $GLOBALS['__ml_calls'][] = [$method, $path, $payload];
        if ($path === '/search') {
            if ($ranked === null) {
                return null;
            }
            $out = [];
            foreach (array_values($ranked) as $i => $id) {
                $out[] = ['id' => $id, 'score' => round(0.9 - $i * 0.01, 4)];
            }
            return ['results' => $out, 'model' => 'test-model'];
        }
        if ($path === '/status') {
            return $status;
        }
        return null;
    });
}

function search_ids(array $user, array $q): array
{
    return array_column(call('GET', '/v1/search', $user, [], $q)['json']['assets'], 'id');
}

/** Testi çalıştırır, sonunda taklidi kaldırır. */
function search_test(string $name, callable $fn): void
{
    dbtest($name, function () use ($fn) {
        search_ensure_table();
        try {
            $fn();
        } finally {
            ml_client_override(null);
        }
    });
}

search_test('test_search_smart_preserves_rank_order', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2021-01-01 00:00:00', 2);
    $c = api_asset($u, '2022-01-01 00:00:00', 3);
    search_stub([$b, $c, $a], ['indexed' => 3, 'total' => 3, 'pending' => 0, 'model' => 'test-model', 'ready' => true]);
    $r = call('GET', '/v1/search', $u, [], ['q' => 'kumsalda köpek'])['json'];
    assert_eq('smart', $r['mode']);
    assert_eq([$b, $c, $a], array_column($r['assets'], 'id'));
    assert_eq(null, $r['next_offset']);
    assert_eq(3, $r['total']);
    assert_eq(['indexed' => 3, 'total' => 3, 'ready' => true], array_intersect_key($r['status'], ['indexed' => 1, 'total' => 1, 'ready' => 1]));
    $search = array_values(array_filter($GLOBALS['__ml_calls'], fn($c) => $c[1] === '/search'));
    assert_eq(1, count($search));
    assert_eq('POST', $search[0][0]);
    assert_eq((int) $u['id'], $search[0][2]['user_id']);
    assert_eq('kumsalda köpek', $search[0][2]['text']);
    assert_true($search[0][2]['limit'] <= 500 && $search[0][2]['limit'] >= 100, 'ML sınırı');
});

search_test('test_search_smart_applies_visibility_rules', function () {
    $u = api_user();
    $ok = api_asset($u, '2020-01-01 00:00:00', 1);
    $trashed = api_asset($u, '2020-01-02 00:00:00', 2);
    $archived = api_asset($u, '2020-01-03 00:00:00', 3, ['is_archived' => true]);
    $photo = api_asset($u, '2020-01-04 00:00:00', 4);
    $video = api_asset($u, '2020-01-04 00:00:00', 5);
    db_exec("UPDATE assets SET type='video', live_pair_id=? WHERE id=?", [$photo, $video]);
    db_exec('UPDATE assets SET live_pair_id=? WHERE id=?', [$video, $photo]);
    $orig = api_asset($u, '2020-01-05 00:00:00', 6);
    $edit = api_asset($u, '2020-01-05 00:00:00', 7);
    db_exec('UPDATE assets SET edited_of_id=? WHERE id=?', [$orig, $edit]);
    asset_trash((int) $u['id'], [$trashed]);
    search_stub([$trashed, $archived, $video, $orig, $ok, $photo, $edit, 'not-a-uuid', '00000000-0000-4000-8000-000000000000']);
    $r = call('GET', '/v1/search', $u, [], ['q' => 'deniz'])['json'];
    assert_eq('smart', $r['mode']);
    assert_eq([$ok, $photo, $edit], array_column($r['assets'], 'id'), 'çöp, arşiv, Live Photo videosu, düzenlenmiş orijinal gizli');
});

search_test('test_search_smart_never_leaks_other_users_assets', function () {
    $u = api_user('ali@example.com');
    $v = api_user('veli@example.com');
    $mine = api_asset($u, '2020-01-01 00:00:00', 1);
    $theirs = api_asset($v, '2020-01-02 00:00:00', 2);
    // Paylaşılan albümde olsa bile arama yalnızca kendi kütüphanesini döner.
    $alb = call('POST', '/v1/albums', $v, ['title' => 'Ortak', 'ids' => [$theirs]])['json']['album']['id'];
    call('POST', "/v1/albums/$alb/members", $v, ['email' => 'ali@example.com']);
    search_stub([$theirs, $mine]);
    $r = call('GET', '/v1/search', $u, [], ['q' => 'kar'])['json'];
    assert_eq([$mine], array_column($r['assets'], 'id'));
    assert_eq(1, $r['total']);
    // Sayfalama ve tarih sıralaması da sızdırmaz.
    assert_eq([$mine], search_ids($u, ['q' => 'kar', 'sort' => 'date']));
    assert_eq(401, call_err('GET', '/v1/search', null, [], ['q' => 'kar'])->status);
    assert_eq(401, call_err('GET', '/v1/search/status', null)->status);
});

search_test('test_search_smart_date_range_and_favorite', function () {
    $u = api_user();
    $a = api_asset($u, '2019-06-01 10:00:00', 1, ['is_favorite' => true]);
    $b = api_asset($u, '2020-06-01 10:00:00', 2);
    $c = api_asset($u, '2021-06-01 10:00:00', 3, ['is_favorite' => true]);
    search_stub([$c, $b, $a]);
    assert_eq([$c, $b], search_ids($u, ['q' => 'dağ', 'from' => '2020-01-01T00:00:00Z']));
    assert_eq([$b, $a], search_ids($u, ['q' => 'dağ', 'to' => '2020-12-31T23:59:59Z']));
    assert_eq([$b], search_ids($u, ['q' => 'dağ', 'from' => '2020-01-01T00:00:00Z', 'to' => '2020-12-31T23:59:59Z']));
    assert_eq([$c, $a], search_ids($u, ['q' => 'dağ', 'favorite' => '1']));
});

search_test('test_search_hybrid_boosts_filename_and_caption_matches', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2021-01-01 00:00:00', 2);
    $c = api_asset($u, '2022-01-01 00:00:00', 3);
    $d = api_asset($u, '2023-01-01 00:00:00', 4);
    db_exec("UPDATE assets SET description = 'Kumsal günü' WHERE id = ?", [$a]);
    db_exec("UPDATE assets SET original_filename = 'kumsal_2021.jpg' WHERE id = ?", [$d]);
    // $d akıllı sonuçlarda yok; $a sonda. İkisi de metinle eşleştiği için başa geçer (akıllı sıraları korunarak).
    search_stub([$b, $c, $a]);
    $r = call('GET', '/v1/search', $u, [], ['q' => 'kumsal'])['json'];
    assert_eq('smart', $r['mode']);
    assert_eq([$a, $d, $b, $c], array_column($r['assets'], 'id'));
    assert_eq(4, $r['total']);
});

search_test('test_search_falls_back_to_text_when_ml_unavailable', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2021-01-01 00:00:00', 2);
    api_asset($u, '2022-01-01 00:00:00', 3);
    db_exec("UPDATE assets SET description = 'Doğum günü pastası' WHERE id IN (?, ?)", [$a, $b]);
    search_stub(null, null);
    $r = call('GET', '/v1/search', $u, [], ['q' => 'doğum günü'])['json'];
    assert_eq('text', $r['mode']);
    assert_eq([$b, $a], array_column($r['assets'], 'id'), 'metin modu: tarihe göre yeniden eskiye');
    assert_eq(null, $r['status']);
    assert_eq(['available' => false], call('GET', '/v1/search/status', $u)['json']);
    // Hizmet boş sonuç dönerse de metne düşer.
    search_stub([], ['indexed' => 0, 'total' => 3, 'pending' => 3, 'model' => 'm', 'ready' => false]);
    $r = call('GET', '/v1/search', $u, [], ['q' => 'doğum günü'])['json'];
    assert_eq('text', $r['mode']);
    assert_eq([$b, $a], array_column($r['assets'], 'id'));
    assert_eq(false, $r['status']['ready']);
    // Hiçbir şey eşleşmezse boş liste.
    search_stub(null);
    assert_eq([], search_ids($u, ['q' => 'zzzyok']));
});

search_test('test_search_pagination_by_offset', function () {
    $u = api_user();
    $ids = [];
    for ($i = 1; $i <= 5; $i++) {
        $ids[] = api_asset($u, sprintf('2020-01-%02d 00:00:00', $i), $i);
    }
    $ranked = array_reverse($ids);
    search_stub($ranked);
    $seen = [];
    $q = ['q' => 'şehir', 'limit' => '2'];
    $pages = 0;
    while (true) {
        $r = call('GET', '/v1/search', $u, [], $q)['json'];
        $pages++;
        $seen = array_merge($seen, array_column($r['assets'], 'id'));
        if ($r['next_offset'] === null) {
            break;
        }
        assert_true($pages < 10, 'sonsuz döngü');
        $q['offset'] = (string) $r['next_offset'];
    }
    assert_eq(3, $pages);
    assert_eq($ranked, $seen);
    // Metin modunda da offset çalışır.
    db_exec("UPDATE assets SET description = 'şehir'");
    search_stub(null);
    $r = call('GET', '/v1/search', $u, [], ['q' => 'şehir', 'limit' => '3'])['json'];
    assert_eq(3, count($r['assets']));
    assert_eq(3, $r['next_offset']);
    $r = call('GET', '/v1/search', $u, [], ['q' => 'şehir', 'limit' => '3', 'offset' => '3'])['json'];
    assert_eq([$ids[1], $ids[0]], array_column($r['assets'], 'id'));
    assert_eq(null, $r['next_offset']);
});

search_test('test_search_sort_by_date', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2021-01-01 00:00:00', 2);
    $c = api_asset($u, '2022-01-01 00:00:00', 3);
    search_stub([$a, $c, $b]);
    assert_eq([$a, $c, $b], search_ids($u, ['q' => 'yemek']));
    assert_eq([$c, $b, $a], search_ids($u, ['q' => 'yemek', 'sort' => 'date']));
});

search_test('test_search_empty_query_and_status_proxy', function () {
    $u = api_user();
    api_asset($u, '2020-01-01 00:00:00', 1);
    $st = ['indexed' => 12400, 'total' => 30000, 'pending' => 17600, 'model' => 'm', 'ready' => true];
    search_stub([], $st);
    $r = call('GET', '/v1/search', $u, [], ['q' => '   '])['json'];
    assert_eq([], $r['assets']);
    assert_eq(null, $r['next_offset']);
    assert_eq(0, count(array_filter($GLOBALS['__ml_calls'], fn($c) => $c[1] === '/search')), 'boş sorgu ML\'e gitmez');
    $s = call('GET', '/v1/search/status', $u)['json'];
    assert_eq(true, $s['available']);
    assert_eq(12400, $s['indexed']);
    assert_eq(30000, $s['total']);
    assert_eq(true, $s['ready']);
    $statusCall = array_values(array_filter($GLOBALS['__ml_calls'], fn($c) => $c[1] === '/status'));
    assert_eq((int) $u['id'], $statusCall[0][2]['user_id']);
});

search_test('test_purge_deletes_embedding', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2020-01-02 00:00:00', 2);
    foreach ([$a, $b] as $id) {
        db_exec('INSERT INTO asset_embeddings (asset_id, user_id, model, vec, created_at) VALUES (?, ?, ?, ?, ?)', [$id, $u['id'], 'm', str_repeat("\0", 16), now_utc()]);
    }
    asset_trash((int) $u['id'], [$a]);
    assert_eq(2, (int) db_val('SELECT COUNT(*) FROM asset_embeddings'), 'çöpe atmak gömmeyi silmez');
    asset_purge((int) $u['id'], [$a]);
    assert_eq([$b], array_column(db_all('SELECT asset_id FROM asset_embeddings'), 'asset_id'));
});
