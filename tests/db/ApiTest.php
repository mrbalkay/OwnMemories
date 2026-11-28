<?php
declare(strict_types=1);

function api_user(string $email = 'ali@example.com'): array
{
    return user_find(user_create($email, 'parola12345', 'Kullanıcı ' . $email));
}

/** Basit asset ekler (render işi çalıştırılmaz). */
function api_asset(array $u, string $taken, int $seed, array $extra = []): string
{
    $d = temp_dir();
    $i = imagecreatetruecolor(40 + $seed, 30);
    imagefilledrectangle($i, 0, 0, 10, 10, imagecolorallocate($i, $seed % 256, 50, 90));
    imagejpeg($i, "$d/p$seed.jpg");
    return asset_ingest((int) $u['id'], "$d/p$seed.jpg", ['filename' => "p$seed.jpg", 'source' => 'web', 'taken_at_utc' => $taken] + $extra)['asset_id'];
}

function call(string $method, string $path, ?array $user, array $body = [], array $query = [], $stream = null): array
{
    return api_route($method, $path, $user, $body, $query, $stream);
}

function call_err(string $method, string $path, ?array $user, array $body = [], array $query = []): ApiError
{
    return assert_throws(ApiError::class, fn() => call($method, $path, $user, $body, $query));
}

dbtest('test_login_returns_token', function () {
    $u = api_user();
    $r = call('POST', '/v1/auth/login', null, ['email' => 'ali@example.com', 'password' => 'parola12345', 'device_name' => 'iPhone', 'platform' => 'ios']);
    assert_eq(200, $r['status']);
    assert_eq(43, strlen($r['json']['token']));
    assert_eq((int) $u['id'], $r['json']['user']['id']);
    $e = call_err('POST', '/v1/auth/login', null, ['email' => 'ali@example.com', 'password' => 'yanlis']);
    assert_eq(401, $e->status);
    assert_eq('invalid_credentials', $e->errorCode);
    assert_eq(401, call_err('GET', '/v1/me', null)->status);
    assert_eq(['ok' => true, 'version' => OM_VERSION, 'schema' => SCHEMA_VERSION], call('GET', '/v1/ping', null)['json']);
});

dbtest('test_assets_pagination_keyset', function () {
    $u = api_user();
    $ids = [];
    $ids[] = api_asset($u, '2020-01-03 00:00:00', 1);
    foreach ([2, 3, 4] as $s) {
        $ids[] = api_asset($u, '2020-01-02 00:00:00', $s); // aynı an
    }
    $ids[] = api_asset($u, '2020-01-01 00:00:00', 5);
    $seen = [];
    $q = ['limit' => '2'];
    for ($page = 0; $page < 10; $page++) {
        $r = call('GET', '/v1/assets', $u, [], $q)['json'];
        foreach ($r['assets'] as $a) {
            $seen[] = $a['id'];
        }
        if ($r['next'] === null) {
            break;
        }
        $q = ['limit' => '2', 'before' => $r['next']['before'], 'before_id' => $r['next']['before_id']];
    }
    assert_eq(5, count($seen));
    assert_eq(5, count(array_unique($seen)), 'sayfa sınırında tekrar/kayıp yok');
    assert_eq($ids[0], $seen[0]);
    assert_eq($ids[4], $seen[4]);
});

dbtest('test_filters_favorite_trashed_album', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1, ['is_favorite' => true]);
    $b = api_asset($u, '2020-01-02 00:00:00', 2);
    $c = api_asset($u, '2020-01-03 00:00:00', 3, ['is_archived' => true]);
    asset_trash((int) $u['id'], [$b]);
    $ids = fn(array $q) => array_column(call('GET', '/v1/assets', $u, [], $q)['json']['assets'], 'id');
    assert_eq([$a], $ids([]), 'varsayılan: çöp ve arşiv hariç');
    assert_eq([$a], $ids(['favorite' => '1']));
    assert_eq([$b], $ids(['trashed' => '1']));
    assert_eq([$c], $ids(['archived' => '1']));
    $alb = call('POST', '/v1/albums', $u, ['title' => 'Deniz'])['json']['album']['id'];
    call('POST', "/v1/albums/$alb/assets", $u, ['ids' => [$a, $c]]);
    assert_eq([$c, $a], $ids(['album' => $alb]), 'albümde arşivliler de görünür');
    $summary = call('GET', '/v1/assets/summary', $u)['json'];
    assert_eq([['month' => '2020-01', 'count' => 1]], $summary['months']);
});

dbtest('test_timeline_hides_live_video_and_edited_original', function () {
    $u = api_user();
    $photo = api_asset($u, '2020-01-01 00:00:00', 1);
    $video = api_asset($u, '2020-01-01 00:00:00', 2);
    db_exec("UPDATE assets SET type='video', live_pair_id=? WHERE id=?", [$photo, $video]);
    db_exec('UPDATE assets SET live_pair_id=? WHERE id=?', [$video, $photo]);
    $orig = api_asset($u, '2020-02-01 00:00:00', 3);
    $edit = api_asset($u, '2020-02-01 00:00:00', 4);
    db_exec('UPDATE assets SET edited_of_id=? WHERE id=?', [$orig, $edit]);
    $ids = array_column(call('GET', '/v1/assets', $u)['json']['assets'], 'id');
    assert_eq([$edit, $photo], $ids);
    $one = call('GET', "/v1/assets/$edit", $u)['json']['asset'];
    assert_eq($orig, $one['edited_of_id']);
});

dbtest('test_patch_bumps_version', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $v0 = (int) db_val('SELECT version FROM assets WHERE id=?', [$a]);
    $r = call('PATCH', "/v1/assets/$a", $u, ['is_favorite' => true, 'description' => 'Not', 'taken_at' => '2021-05-05T10:00:00Z', 'latitude' => 41.0, 'longitude' => 29.0])['json']['asset'];
    assert_eq(true, $r['is_favorite']);
    assert_eq('Not', $r['description']);
    assert_eq('2021-05-05T10:00:00Z', $r['taken_at']);
    assert_eq('user', $r['date_source']);
    assert_eq(41.0, $r['latitude']);
    assert_true($r['version'] > $v0);
    assert_eq(422, call_err('PATCH', "/v1/assets/$a", $u, ['latitude' => 100.0, 'longitude' => 0.0])->status);
});

dbtest('test_check_missing_and_deleted', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $sha = db_val('SELECT blob_sha1 FROM assets WHERE id=?', [$a]);
    $b = api_asset($u, '2020-01-02 00:00:00', 2);
    $shaB = db_val('SELECT blob_sha1 FROM assets WHERE id=?', [$b]);
    asset_purge((int) $u['id'], [$b]);
    $unknown = str_repeat('a', 40);
    $r = call('POST', '/v1/assets/check', $u, ['sha1' => [$sha, $shaB, $unknown, 'bozuk']])['json'];
    assert_eq([$unknown], $r['missing']);
    assert_eq(422, call_err('POST', '/v1/assets/check', $u, ['sha1' => array_fill(0, 501, $unknown)])->status);
});

dbtest('test_other_user_asset_404', function () {
    $u = api_user();
    $o = api_user('b@example.com');
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    assert_eq(404, call_err('GET', "/v1/assets/$a", $o)->status);
    assert_eq(404, call_err('PATCH', "/v1/assets/$a", $o, ['is_favorite' => true])->status);
    assert_eq(404, call_err('GET', "/v1/assets/$a/original", $o)->status);
    assert_eq(0, call('POST', '/v1/assets/trash', $o, ['ids' => [$a]])['json']['count']);
});

dbtest('test_shared_album_member_can_view_not_patch', function () {
    $u = api_user();
    $o = api_user('es@example.com');
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $alb = call('POST', '/v1/albums', $u, ['title' => 'Aile'])['json']['album']['id'];
    call('POST', "/v1/albums/$alb/assets", $u, ['ids' => [$a]]);
    call('POST', "/v1/albums/$alb/members", $u, ['email' => 'es@example.com', 'role' => 'viewer']);
    $list = call('GET', '/v1/albums', $o)['json']['albums'];
    assert_eq(1, count($list));
    assert_eq('viewer', $list[0]['role']);
    assert_eq('Kullanıcı ali@example.com', $list[0]['owner_name']);
    assert_eq([$a], array_column(call('GET', '/v1/assets', $o, [], ['album' => $alb])['json']['assets'], 'id'));
    assert_eq($a, call('GET', "/v1/assets/$a", $o)['json']['asset']['id']);
    $f = call('GET', "/v1/assets/$a/original", $o);
    assert_true(isset($f['file']));
    assert_eq(404, call_err('PATCH', "/v1/assets/$a", $o, ['is_favorite' => true])->status);
    assert_eq([], call('GET', '/v1/assets', $o)['json']['assets'], 'kendi zaman çizelgesinde görünmez');
    assert_eq(403, call_err('POST', "/v1/albums/$alb/assets", $o, ['ids' => [$a]])->status, 'viewer ekleyemez');
});

dbtest('test_contributor_add_own_remove_others_forbidden', function () {
    $u = api_user();
    $o = api_user('es@example.com');
    $mine = api_asset($u, '2020-01-01 00:00:00', 1);
    $hers = api_asset($o, '2020-01-02 00:00:00', 2);
    $alb = call('POST', '/v1/albums', $u, ['title' => 'Ortak'])['json']['album']['id'];
    call('POST', "/v1/albums/$alb/assets", $u, ['ids' => [$mine]]);
    call('POST', "/v1/albums/$alb/members", $u, ['email' => 'es@example.com', 'role' => 'contributor']);
    assert_eq(1, call('POST', "/v1/albums/$alb/assets", $o, ['ids' => [$hers, $mine]])['json']['added'], 'yalnız kendi asset\'i eklenir');
    assert_eq(403, call_err('DELETE', "/v1/albums/$alb/assets", $o, ['ids' => [$mine]])->status);
    assert_eq(1, call('DELETE', "/v1/albums/$alb/assets", $o, ['ids' => [$hers]])['json']['removed']);
    call('POST', "/v1/albums/$alb/assets", $o, ['ids' => [$hers]]);
    assert_eq(1, call('DELETE', "/v1/albums/$alb/assets", $u, ['ids' => [$hers]])['json']['removed'], 'sahip herkesinkini çıkarır');
    assert_eq(1, (int) db_val('SELECT COUNT(*) FROM assets WHERE id=?', [$hers]), 'albümden çıkarmak silmez');
    assert_eq(403, call_err('PATCH', "/v1/albums/$alb", $o, ['title' => 'X'])->status);
});

dbtest('test_users_lookup', function () {
    $u = api_user();
    api_user('es@example.com');
    assert_eq(['exists' => true, 'name' => 'Kullanıcı es@example.com'], call('GET', '/v1/users/lookup', $u, [], ['email' => 'ES@example.com'])['json']);
    assert_eq(['exists' => false, 'name' => null], call('GET', '/v1/users/lookup', $u, [], ['email' => 'yok@example.com'])['json']);
});

dbtest('test_thumb_pending_returns_202_placeholder', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $r = call('GET', "/v1/assets/$a/thumb", $u);
    assert_eq(202, $r['status']);
    assert_true(str_contains($r['svg'], '<svg'));
    jobs_run_for(10, 't');
    $r = call('GET', "/v1/assets/$a/thumb", $u);
    assert_eq(200, $r['status']);
    assert_true(is_file($r['file']['path']));
});

dbtest('test_map_points', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1, ['lat' => 38.4, 'lng' => 27.1]);
    api_asset($u, '2020-01-02 00:00:00', 2);
    $pts = call('GET', '/v1/assets/map', $u)['json']['points'];
    assert_eq([['id' => $a, 'lat' => 38.4, 'lng' => 27.1]], $pts);
    assert_eq([], call('GET', '/v1/assets/map', $u, [], ['bbox' => '0,0,1,1'])['json']['points']);
});

dbtest('test_unknown_route_404', function () {
    $u = api_user();
    assert_eq(404, call_err('GET', '/v1/yok', $u)->status);
});
