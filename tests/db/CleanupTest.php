<?php
/**
 * Temizlik: benzer gruplarda en iyiyi seçme, gizleme ("bunlar farklı"), çöpe taşıma (kalıcı silmeden),
 * bulanık/karanlık/ekran görüntüsü/kısa video listeleri, ML yokken davranış, kalıcı silmede temizlik.
 */
declare(strict_types=1);

/** ML çağrılarını yakalar; $GLOBALS['__cl_groups'] /cleanup/groups yanıtı olur (null = hizmet yok). */
function cl_test(string $name, callable $fn): void
{
    dbtest($name, function () use ($fn) {
        $GLOBALS['__cl_calls'] = [];
        $GLOBALS['__cl_groups'] = [];
        ml_client_override(function (string $method, string $path, array $payload): ?array {
            $GLOBALS['__cl_calls'][] = [$method, $path, $payload];
            if ($path === '/cleanup/groups') {
                $g = $GLOBALS['__cl_groups'];
                return $g === null ? null : ['groups' => array_map(fn($ids) => ['assets' => $ids, 'reason' => 'similar', 'min_sim' => 0.95, 'max_hamming' => 12], $g), 'analyzed' => 3, 'total' => 4];
            }
            return null;
        });
        try {
            $fn();
        } finally {
            ml_client_override(null);
        }
    });
}

/** Asset ekler ve alanlarını ayarlar. */
function cl_asset(array $u, int $seed, array $set = [], string $taken = '2024-05-01 10:00:00'): string
{
    $id = api_asset($u, $taken, $seed);
    $set += ['width' => 4000, 'height' => 3000, 'size' => 1000000];
    $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
    db_exec("UPDATE assets SET $cols WHERE id = ?", array_merge(array_values($set), [$id]));
    return $id;
}

function cl_quality(string $id, ?float $sharp = 500.0, ?float $bright = 120.0, int $shot = 0): void
{
    db_exec('INSERT INTO asset_quality (asset_id, user_id, phash, sharpness, brightness, is_screenshot, algo, computed_at)
        SELECT id, user_id, ?, ?, ?, ?, 1, ? FROM assets WHERE id = ?
        ON DUPLICATE KEY UPDATE sharpness = VALUES(sharpness), brightness = VALUES(brightness), is_screenshot = VALUES(is_screenshot)', ['18446744073709551615', $sharp, $bright, $shot, now_utc(), $id]);
}

function cl_get(array $u): array
{
    return call('GET', '/v1/cleanup', $u)['json'];
}

function cl_ids(array $list): array
{
    $ids = array_column($list, 'id');
    sort($ids);
    return $ids;
}

function cl_sorted(array $ids): array
{
    sort($ids);
    return $ids;
}

cl_test('test_cleanup_schema_tables', function () {
    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('asset_quality', $tables, true));
    assert_true(in_array('cleanup_dismissed', $tables, true));
    assert_true(SCHEMA_VERSION >= 9);
    assert_true(is_file(OM_WEB . '/install/migrations/009_cleanup.sql'));
    // asset_quality.phash 64 bit işaretsiz tamsayıyı tutar
    $u = api_user();
    $a = cl_asset($u, 1);
    cl_quality($a);
    assert_eq('18446744073709551615', (string) db_val('SELECT phash FROM asset_quality WHERE asset_id = ?', [$a]));
});

cl_test('test_cleanup_group_recommends_best_and_counts_bytes', function () {
    $u = api_user();
    $small = cl_asset($u, 1, ['width' => 1600, 'height' => 1200, 'size' => 300000]);
    $big = cl_asset($u, 2, ['width' => 4032, 'height' => 3024, 'size' => 3000000, 'latitude' => 41.0, 'longitude' => 29.0, 'camera_make' => 'Apple']);
    $blur = cl_asset($u, 3, ['width' => 4032, 'height' => 3024, 'size' => 2900000, 'camera_make' => 'Apple']);
    cl_quality($small, 900);
    cl_quality($big, 800);
    cl_quality($blur, 6);
    $GLOBALS['__cl_groups'] = [[$small, $big, $blur]];
    $r = cl_get($u);
    assert_eq(1, count($r['groups']));
    $g = $r['groups'][0];
    assert_eq($big, $g['keep_id']);
    assert_eq('similar', $g['reason']);
    assert_eq(16, strlen($g['id']));
    assert_eq(cl_sorted([$small, $big, $blur]), cl_ids($g['assets']));
    assert_true(in_array('resolution', $g['keep_reasons'], true), 'çözünürlük gerekçesi');
    assert_eq(3200000, $g['bytes']);
    assert_eq(3200000, $r['stats']['potential_bytes']);
    assert_eq(1, $r['stats']['groups']);
    assert_eq(3, $r['stats']['group_assets']);
    assert_true($r['ml']['available']);
    // Her öğede kalite bilgisi
    $byId = array_column($g['assets'], null, 'id');
    assert_eq(6.0, $byId[$blur]['quality']['sharpness']);
    // İstek ML'e kullanıcı kimliğiyle gider
    assert_eq(['POST', '/cleanup/groups', ['user_id' => (int) $u['id']]], $GLOBALS['__cl_calls'][0]);

    // Favori her zaman kazanır
    db_exec('UPDATE assets SET is_favorite = 1 WHERE id = ?', [$small]);
    $g = cl_get($u)['groups'][0];
    assert_eq($small, $g['keep_id']);
    assert_eq('favorite', $g['keep_reasons'][0]);
    // İkinci favori varsayılan olarak silinmez: kazanılabilir alana sayılmaz
    db_exec('UPDATE assets SET is_favorite = 1 WHERE id = ?', [$big]);
    $g = cl_get($u)['groups'][0];
    assert_eq(2900000, $g['bytes'], 'favori kazanılabilir alana sayılmaz');
    db_exec('UPDATE assets SET is_favorite = 0 WHERE id = ?', [$big]);

    // Eşit çözünürlükte daha net olan; düzenlenmiş kopya adı ("(1)") geride kalır
    db_exec('UPDATE assets SET is_favorite = 0 WHERE id = ?', [$small]);
    $c1 = cl_asset($u, 4, ['original_filename' => 'IMG_0001 (1).JPG', 'size' => 2000000]);
    $c2 = cl_asset($u, 5, ['original_filename' => 'IMG_0001.JPG', 'size' => 2000000]);
    cl_quality($c1, 700);
    cl_quality($c2, 690);
    $GLOBALS['__cl_groups'] = [[$c1, $c2]];
    assert_eq($c2, cl_get($u)['groups'][0]['keep_id']);
});

cl_test('test_cleanup_groups_filter_trashed_dismissed_and_reset', function () {
    $u = api_user();
    [$a, $b, $c, $d, $e] = [cl_asset($u, 1), cl_asset($u, 2), cl_asset($u, 3), cl_asset($u, 4), cl_asset($u, 5)];
    $other = cl_asset(api_user('veli@example.com'), 6);
    $GLOBALS['__cl_groups'] = [[$a, $b], [$c, $d], [$e, $other], ['bozuk', $a]];
    $r = cl_get($u);
    assert_eq(2, count($r['groups']), 'başka kullanıcının öğesi ve geçersiz id süzülür');
    // Çöpe giden üye grubu bozar
    call('POST', '/v1/assets/trash', $u, ['ids' => [$d]]);
    $r = cl_get($u);
    assert_eq(1, count($r['groups']));
    $gid = $r['groups'][0]['id'];
    // "Bunlar farklı": grup bir daha gelmez
    $res = call('POST', '/v1/cleanup/dismiss', $u, ['kind' => 'group', 'ids' => [$b, $a]])['json'];
    assert_eq(1, $res['dismissed']);
    assert_eq([], cl_get($u)['groups']);
    // Alt kümesi de gelmez; yeni bir üye eklenince grup yeniden görünür
    $f = cl_asset($u, 7);
    $GLOBALS['__cl_groups'] = [[$a, $b, $f]];
    assert_eq(1, count(cl_get($u)['groups']));
    $GLOBALS['__cl_groups'] = [[$a, $b]];
    assert_eq([], cl_get($u)['groups']);
    // Sıfırla: gizlenenler geri gelir
    assert_eq(1, call('POST', '/v1/cleanup/reset', $u)['json']['restored']);
    assert_eq(1, count(cl_get($u)['groups']));
    assert_true($gid !== '');
    // Geçersiz tür
    assert_eq(422, call_err('POST', '/v1/cleanup/dismiss', $u, ['kind' => 'xyz', 'ids' => [$a]])->status);
    assert_eq(422, call_err('POST', '/v1/cleanup/dismiss', $u, ['kind' => 'group', 'ids' => [$a]])->status);
});

cl_test('test_cleanup_resolve_trashes_never_purges_and_remembers_kept', function () {
    $u = api_user();
    $usedBefore = (int) db_val('SELECT used_bytes FROM users WHERE id = ?', [(int) $u['id']]);
    [$a, $b, $c] = [cl_asset($u, 1), cl_asset($u, 2), cl_asset($u, 3, ['size' => 2500000])];
    $video = cl_asset($u, 4, ['type' => 'video', 'live_pair_id' => $c]);
    db_exec('UPDATE assets SET live_pair_id = ? WHERE id = ?', [$video, $c]);
    $foreign = cl_asset(api_user('veli@example.com'), 9);
    $r = call('POST', '/v1/cleanup/resolve', $u, ['keep' => [$a, $b], 'trash' => [$c, $foreign]])['json'];
    assert_eq(1, $r['trashed']);
    assert_eq(2500000, $r['freed_bytes']);
    assert_true(db_val('SELECT trashed_at FROM assets WHERE id = ?', [$c]) !== null, 'çöpte');
    assert_true(db_val('SELECT trashed_at FROM assets WHERE id = ?', [$video]) !== null, 'Live Photo videosu da çöpte');
    assert_eq(null, db_val('SELECT trashed_at FROM assets WHERE id = ?', [$a]));
    assert_eq(null, db_val('SELECT trashed_at FROM assets WHERE id = ?', [$foreign]), 'başkasının öğesine dokunulmaz');
    assert_eq(4, (int) db_val('SELECT COUNT(*) FROM assets WHERE user_id = ?', [(int) $u['id']]), 'kalıcı silinmedi');
    assert_true((int) db_val('SELECT used_bytes FROM users WHERE id = ?', [(int) $u['id']]) >= $usedBefore);
    // İkiden çok tutulan: grup olarak hatırlanır
    $GLOBALS['__cl_groups'] = [[$a, $b, $c]];
    assert_eq([], cl_get($u)['groups']);
    // Çakışan keep/trash reddedilir; toplu biçim
    assert_eq(422, call_err('POST', '/v1/cleanup/resolve', $u, ['keep' => [$a], 'trash' => [$a]])->status);
    $d = cl_asset($u, 5);
    $e = cl_asset($u, 6);
    $f = cl_asset($u, 7);
    $r = call('POST', '/v1/cleanup/resolve', $u, ['groups' => [['keep' => [$d], 'trash' => [$e]], ['keep' => [$a], 'trash' => [$f]]]])['json'];
    assert_eq(2, $r['trashed']);
    assert_eq(422, call_err('POST', '/v1/cleanup/resolve', $u, ['groups' => [['keep' => [$d], 'trash' => [$d]]]])->status);
    assert_eq(422, call_err('POST', '/v1/cleanup/resolve', $u, ['trash' => array_fill(0, 1001, $a)])->status);
    // Çöpteki öğe geri yüklenebilir
    call('POST', '/v1/assets/restore', $u, ['ids' => [$e]]);
    assert_eq(null, db_val('SELECT trashed_at FROM assets WHERE id = ?', [$e]));
});

cl_test('test_cleanup_lists_blurry_dark_screenshots_short_videos', function () {
    $u = api_user();
    $sharp = cl_asset($u, 1);
    cl_quality($sharp, 800, 120);
    $blurry = cl_asset($u, 2, ['size' => 1500000]);
    cl_quality($blurry, 4, 130);
    $tinyBlur = cl_asset($u, 3, ['width' => 120, 'height' => 90]);
    cl_quality($tinyBlur, 2, 130);
    $favBlur = cl_asset($u, 4, ['is_favorite' => 1]);
    cl_quality($favBlur, 3, 130);
    $dark = cl_asset($u, 5);
    cl_quality($dark, 9, 6);
    $bright = cl_asset($u, 6);
    cl_quality($bright, 300, 245);
    $shotName = cl_asset($u, 7, ['original_filename' => 'Ekran Resmi 2024-05-01 10.00.00.png', 'mime' => 'image/png', 'width' => 2880, 'height' => 1800]);
    $shotPng = cl_asset($u, 8, ['original_filename' => 'IMG_4300.PNG', 'mime' => 'image/png', 'width' => 1170, 'height' => 2532]);
    $shotFlag = cl_asset($u, 9, ['original_filename' => 'IMG_1.JPG']);
    cl_quality($shotFlag, 500, 200, 1);
    $camPng = cl_asset($u, 10, ['original_filename' => 'DSC_1.PNG', 'mime' => 'image/png', 'camera_make' => 'NIKON']);
    $short = cl_asset($u, 11, ['type' => 'video', 'duration_ms' => 400]);
    $long = cl_asset($u, 12, ['type' => 'video', 'duration_ms' => 40000]);
    $livePhoto = cl_asset($u, 13);
    $liveVid = cl_asset($u, 14, ['type' => 'video', 'duration_ms' => 300, 'live_pair_id' => $livePhoto]);
    $pocket = cl_asset($u, 15, ['type' => 'video', 'duration_ms' => 20000]);
    cl_quality($pocket, 1, 4);
    $archivedBlur = cl_asset($u, 16, ['is_archived' => 1]);
    cl_quality($archivedBlur, 2, 120);
    $groupBlur = cl_asset($u, 17);
    cl_quality($groupBlur, 2, 120);
    $GLOBALS['__cl_groups'] = [[$sharp, $groupBlur]];

    $r = cl_get($u);
    assert_eq([$blurry], cl_ids($r['blurry']), 'küçük, favori, arşiv ve gruptakiler hariç');
    assert_eq(cl_sorted([$dark, $bright, $pocket]), cl_ids($r['dark']));
    $why = array_column($r['dark'], 'why', 'id');
    assert_eq('dark', $why[$dark]);
    assert_eq('bright', $why[$bright]);
    assert_eq('dark', $why[$pocket]);
    assert_eq(cl_sorted([$shotName, $shotPng, $shotFlag]), cl_ids($r['screenshots']));
    assert_eq([$short], cl_ids($r['short_videos']), 'Live Photo videosu ve uzun video hariç');
    assert_eq(1, $r['stats']['blurry']);
    assert_eq(1500000, $r['stats']['blurry_bytes']);
    assert_eq(3, $r['stats']['screenshots']);
    assert_eq(1, $r['stats']['short_videos']);
    // "Bu iyi": tek öğe gizlenir
    call('POST', '/v1/cleanup/dismiss', $u, ['kind' => 'blurry', 'ids' => [$blurry]]);
    call('POST', '/v1/cleanup/dismiss', $u, ['kind' => 'screenshot', 'ids' => [$shotPng]]);
    $r = cl_get($u);
    assert_eq([], $r['blurry']);
    assert_eq(cl_sorted([$shotName, $shotFlag]), cl_ids($r['screenshots']));
    // Analiz durumu
    assert_true($r['analysis']['total'] >= 10);
    assert_true($r['analysis']['analyzed'] >= 8);
});

cl_test('test_cleanup_without_ml_still_lists', function () {
    $u = api_user();
    $b = cl_asset($u, 1);
    cl_quality($b, 3, 120);
    $GLOBALS['__cl_groups'] = null;
    $r = cl_get($u);
    assert_eq(false, $r['ml']['available']);
    assert_eq([], $r['groups']);
    assert_eq([$b], cl_ids($r['blurry']));
    assert_eq(0, $r['stats']['potential_bytes']);
    assert_eq(401, call_err('GET', '/v1/cleanup', null)->status);
});

cl_test('test_cleanup_purge_removes_quality_row', function () {
    $u = api_user();
    $a = cl_asset($u, 1);
    cl_quality($a);
    call('POST', '/v1/assets/trash', $u, ['ids' => [$a]]);
    call('POST', '/v1/assets/purge', $u, ['ids' => [$a]]);
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM asset_quality'));
});

test('test_cleanup_is_screenshot_rule', function () {
    $s = fn($name, $mime, $make, $w, $h) => cleanup_is_screenshot(['original_filename' => $name, 'mime' => $mime, 'camera_make' => $make, 'width' => $w, 'height' => $h]);
    assert_true($s('Screenshot 2024-01-02 at 10.00.00.png', 'image/png', null, 2880, 1800));
    assert_true($s('Ekran görüntüsü 2024.jpg', 'image/jpeg', null, 100, 100));
    assert_true($s('IMG_0001.PNG', 'image/png', null, 1170, 2532));
    assert_true($s('IMG_0002.HEIC', 'image/heic', null, 2532, 1170));
    assert_true(!$s('IMG_0003.HEIC', 'image/heic', 'Apple', 1170, 2532));
    assert_true(!$s('IMG_0004.JPG', 'image/jpeg', null, 4032, 3024));
    assert_true(!$s('p1.jpg', 'image/jpeg', null, null, null));
});

dbtest('test_cleanup_migration_009_upgrades_existing_install', function () {
    db()->exec('DROP TABLE asset_quality');
    db()->exec('DROP TABLE cleanup_dismissed');
    site_setting_set('schema_version', '6');
    site_setting_cache_clear();
    assert_eq(SCHEMA_VERSION, migrations_run());
    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('asset_quality', $tables, true) && in_array('cleanup_dismissed', $tables, true));
    // Tekrar çalıştırılabilir
    site_setting_set('schema_version', '6');
    site_setting_cache_clear();
    assert_eq(SCHEMA_VERSION, migrations_run());
});

cl_test('test_cleanup_resolve_never_trashes_whole_group_from_stale_client', function () {
    $u = api_user();
    [$a, $b, $c] = [cl_asset($u, 1), cl_asset($u, 2), cl_asset($u, 3)];
    // Başka cihazda "en iyisi" ($a) çöpe atılmış; eski sayfa hâlâ {keep:[a], trash:[b]} gönderiyor.
    call('POST', '/v1/assets/trash', $u, ['ids' => [$a]]);
    $r = call('POST', '/v1/cleanup/resolve', $u, ['groups' => [['keep' => [$a], 'trash' => [$b]]]])['json'];
    assert_eq(0, $r['trashed'], 'tutulacak kare artık yoksa grubun geri kalanı çöpe gitmez');
    assert_eq(1, $r['skipped']);
    assert_eq(null, db_val('SELECT trashed_at FROM assets WHERE id = ?', [$b]));
    // Bir grupta tutulan, başka bir grupta çöpe gönderilemez (tek istekte)
    assert_eq(422, call_err('POST', '/v1/cleanup/resolve', $u, ['groups' => [['keep' => [$b], 'trash' => [$c]], ['keep' => [$c], 'trash' => [$b]]]])->status);
    assert_eq(null, db_val('SELECT trashed_at FROM assets WHERE id = ?', [$c]));
    // Liste biçimi (keep boş) etkilenmez
    assert_eq(1, call('POST', '/v1/cleanup/resolve', $u, ['keep' => [], 'trash' => [$c]])['json']['trashed']);
});
