<?php
declare(strict_types=1);

/* Bağlantıyla paylaşım (web/includes/shares.php + public uç web/s/index.php). */

/** GPS etiketli küçük bir JPEG (exiftool yoksa test atlanır). */
function sh_gps_photo(string $dir, string $name, int $seed = 3, string $date = '2025:08:14 10:00:00'): string
{
    $bin = tools_detect(true)['exiftool'];
    if (!$bin) {
        skip('exiftool yok');
    }
    $p = mk_photo($dir, $name, $date, $seed, 96, 64);
    tool_run([$bin, '-q', '-overwrite_original', '-GPSLatitude=41.0082', '-GPSLatitudeRef=N', '-GPSLongitude=28.9784', '-GPSLongitudeRef=E', '-XMP:City=İstanbul', $p]);
    return $p;
}

/** Bir kullanıcıya N fotoğraf ekler; asset id listesi döner (türevler üretilir). */
function sh_photos(int $uid, int $n, bool $gps = false): array
{
    $dir = temp_dir();
    $ids = [];
    for ($i = 1; $i <= $n; $i++) {
        $src = $gps ? sh_gps_photo($dir, "IMG_$i.jpg", $i + $uid * 10, sprintf('2025:08:%02d 10:00:00', 10 + $i)) : mk_photo($dir, "IMG_$i.jpg", sprintf('2025:08:%02d 10:00:00', 10 + $i), $i + $uid * 10);
        $r = asset_ingest($uid, $src, ['filename' => "IMG_$i.jpg", 'source' => 'web']);
        job_render(['asset_id' => $r['asset_id']]);
        $ids[] = $r['asset_id'];
    }
    return $ids;
}

/** Public isteği: share_http() ile (çerezler ve uzak adres verilebilir). */
function sh_req(string $method, string $path, array $opts = []): array
{
    return share_http([
        'method' => $method, 'path' => $path, 'cookies' => $opts['cookies'] ?? [], 'post' => $opts['post'] ?? [],
        'ip' => $opts['ip'] ?? '203.0.113.7', 'https' => true, 'host' => 'paylas.example.net', 'range' => $opts['range'] ?? null,
    ]);
}

/** Akış yanıtını (zip, GPS'i silinmiş orijinal) belleğe toplar. */
function sh_body(array $res): string
{
    if (isset($res['body'])) {
        return (string) $res['body'];
    }
    if (isset($res['file'])) {
        return (string) file_get_contents($res['file']['path']);
    }
    if (isset($res['stream'])) {
        $buf = '';
        ($res['stream'])(function (string $c) use (&$buf) { $buf .= $c; });
        return $buf;
    }
    return '';
}

/** Galeri sayfasındaki JSON'dan öğe anahtarları. */
function sh_keys(string $html): array
{
    if (!preg_match('#<script type="application/json" id="share-data">(.*?)</script>#s', $html, $m)) {
        return [];
    }
    $d = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    return array_column($d['items'] ?? [], 'k');
}

function sh_cookie_jar(array $res, array $jar = []): array
{
    foreach ($res['cookies'] ?? [] as $c) {
        $jar[$c['name']] = $c['value'];
    }
    return $jar;
}

dbtest('test_share_create_album_link_stores_only_hash', function () {
    $u = user_create('mehmet@example.com', 'parola12345', 'Mehmet Balkay');
    $ids = sh_photos($u, 3);
    $al = album_create($u, 'Bozcaada 2025');
    album_add_assets($al, $u, $ids);
    $r = share_create($u, ['kind' => 'album', 'album_id' => $al, 'expires' => '1w', 'allow_download' => true]);
    assert_true((bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $r['token']), 'belirteç 256 bit base64url');
    assert_true(str_ends_with($r['url'], '/s/' . $r['token']));
    $row = db_row('SELECT * FROM share_links WHERE id = ?', [$r['link']['id']]);
    assert_eq(hash('sha256', $r['token']), $row['token_hash']);
    foreach ($row as $v) {
        assert_true(!is_string($v) || !str_contains($v, $r['token']), 'düz belirteç veritabanında olmamalı');
    }
    assert_eq('Bozcaada 2025', $r['link']['title'], 'başlık albümden');
    assert_eq(3, $r['link']['count']);
    assert_true($r['link']['expires_at'] !== null);
    assert_eq(true, $r['link']['allow_download']);
    assert_eq(false, $r['link']['show_location'], 'konum varsayılan gizli');
    assert_eq(null, $r['link']['url'] ?? null, 'liste öğesinde belirteç yok');
    $b = share_create($u, ['kind' => 'album', 'album_id' => $al]);
    assert_true($b['token'] !== $r['token']);
});

dbtest('test_share_selection_only_own_assets_and_album_owner_only', function () {
    $u = user_create('a@example.com', 'parola12345', 'Ali');
    $o = user_create('b@example.com', 'parola12345', 'Banu');
    $mine = sh_photos($u, 2);
    $theirs = sh_photos($o, 1);
    $r = share_create($u, ['kind' => 'selection', 'ids' => array_merge($mine, $theirs, ['../../etc/passwd', 'x']), 'title' => 'Seçim']);
    assert_eq(2, $r['link']['count'], 'başkasının öğesi eklenmez');
    assert_throws(ValidationError::class, fn() => share_create($u, ['kind' => 'selection', 'ids' => $theirs]), 'yalnız başkasının öğeleri');
    assert_throws(ValidationError::class, fn() => share_create($u, ['kind' => 'bogus', 'ids' => $mine]));
    $al = album_create($o, 'Banu albüm');
    album_add_assets($al, $o, $theirs);
    assert_throws(ApiError::class, fn() => share_create($u, ['kind' => 'album', 'album_id' => $al]), 'başkasının albümü');
    album_share($al, $o, 'a@example.com', 'contributor');
    assert_throws(ApiError::class, fn() => share_create($u, ['kind' => 'album', 'album_id' => $al]), 'katkıda bulunan paylaşamaz');
    assert_throws(ValidationError::class, fn() => share_create($u, ['kind' => 'selection', 'ids' => $mine, 'password' => 'abc']), 'kısa parola');
});

dbtest('test_share_owner_endpoints_idor_and_lifecycle', function () {
    $u = user_create('a@example.com', 'parola12345', 'Ali');
    $o = user_create('b@example.com', 'parola12345', 'Banu');
    $ids = sh_photos($u, 1);
    $r = share_create($u, ['kind' => 'selection', 'ids' => $ids, 'title' => 'Doğum günü']);
    $id = (int) $r['link']['id'];
    $ou = user_find($o);
    $uu = user_find($u);
    assert_eq(0, count(api_route('GET', '/v1/shares', $ou)['json']['links']), 'başkasının listesi boş');
    assert_eq(1, count(api_route('GET', '/v1/shares', $uu)['json']['links']));
    foreach ([['PATCH', "/v1/shares/$id", ['title' => 'x']], ['POST', "/v1/shares/$id/revoke", []], ['POST', "/v1/shares/$id/rotate", []], ['DELETE', "/v1/shares/$id", []]] as [$m, $p, $b]) {
        $e = assert_throws(ApiError::class, fn() => api_route($m, $p, $ou, $b), "$m $p başkası");
        assert_eq(404, $e->status);
    }
    assert_throws(ApiError::class, fn() => api_route('GET', '/v1/shares', null), 'girişsiz');
    $p = api_route('PATCH', "/v1/shares/$id", $uu, ['title' => 'Doğum günü 🎂', 'password' => 'gizli1234', 'show_location' => true, 'expires' => '1d'])['json']['link'];
    assert_eq('Doğum günü 🎂', $p['title']);
    assert_eq(true, $p['has_password']);
    assert_eq(true, $p['show_location']);
    $rot = api_route('POST', "/v1/shares/$id/rotate", $uu)['json'];
    assert_true($rot['token'] !== $r['token']);
    assert_eq('not_found', share_resolve($r['token'])['state'], 'eski belirteç geçersiz');
    assert_eq('ok', share_resolve($rot['token'])['state']);
    api_route('POST', "/v1/shares/$id/revoke", $uu);
    assert_eq('revoked', share_resolve($rot['token'])['state']);
    assert_eq('revoked', api_route('GET', '/v1/shares', $uu)['json']['links'][0]['status']);
    api_route('DELETE', "/v1/shares/$id", $uu);
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM share_links'));
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM share_link_assets'));
});

dbtest('test_share_resolve_states', function () {
    $u = user_create('a@example.com', 'parola12345', 'Ali');
    $ids = sh_photos($u, 1);
    $al = album_create($u, 'Yaz');
    album_add_assets($al, $u, $ids);
    $r = share_create($u, ['kind' => 'album', 'album_id' => $al]);
    assert_eq('ok', share_resolve($r['token'])['state']);
    assert_eq('not_found', share_resolve(substr($r['token'], 0, 42) . ($r['token'][42] === 'A' ? 'B' : 'A'))['state']);
    assert_eq('not_found', share_resolve('')['state']);
    assert_eq('not_found', share_resolve("../../config/config.php")['state']);
    db_exec('UPDATE share_links SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 5), $r['link']['id']]);
    assert_eq('expired', share_resolve($r['token'])['state']);
    db_exec('UPDATE share_links SET expires_at = NULL WHERE id = ?', [$r['link']['id']]);
    db_exec("UPDATE users SET status = 'disabled' WHERE id = ?", [$u]);
    assert_eq('revoked', share_resolve($r['token'])['state'], 'devre dışı kullanıcı');
    db_exec("UPDATE users SET status = 'active' WHERE id = ?", [$u]);
    album_delete($al, $u);
    assert_eq('revoked', share_resolve($r['token'])['state'], 'silinen albüm');
});

dbtest('test_share_public_gallery_hides_internals_and_sets_headers', function () {
    $u = user_create('mehmet@example.com', 'parola12345', 'Mehmet Balkay');
    $o = user_create('b@example.com', 'parola12345', 'Banu');
    $ids = sh_photos($u, 3);
    $other = sh_photos($o, 1);
    $r = share_create($u, ['kind' => 'selection', 'ids' => [$ids[0], $ids[2]], 'title' => 'Bayram']);
    $res = sh_req('GET', '/s/' . $r['token']);
    assert_eq(200, $res['status']);
    $html = $res['body'];
    assert_true(str_contains($html, 'Bayram'));
    assert_true(str_contains($html, 'Mehmet'), 'sahibin adı');
    assert_true(!str_contains($html, 'Balkay'), 'yalnız ilk ad');
    assert_true(!str_contains($html, 'mehmet@example.com'));
    foreach (array_merge($ids, $other) as $aid) {
        assert_true(!str_contains($html, $aid), 'iç asset kimliği sayfada olmamalı');
    }
    assert_true(!str_contains($html, hash('sha256', $r['token'])));
    assert_true(!str_contains($html, 'IMG_1.jpg'), 'dosya adı gösterilmez');
    assert_eq(2, count(sh_keys($html)));
    $h = array_change_key_case($res['headers']);
    assert_true(str_contains($h['content-security-policy'], "default-src 'none'"));
    assert_true(str_contains($h['content-security-policy'], "frame-ancestors 'none'"));
    assert_eq('no-referrer', $h['referrer-policy']);
    assert_true(str_contains($h['x-robots-tag'], 'noindex'));
    assert_true(str_contains($h['cache-control'], 'no-store'));
    assert_true(str_contains($html, '<meta name="robots" content="noindex'));
    assert_true(str_contains($html, 'og:image'), 'WhatsApp önizlemesi');
    assert_true(!preg_match('#(src|href)="https?://(?!paylas\.example\.net)#', $html), 'üçüncü taraf istek yok');
    assert_eq(1, (int) db_val('SELECT views FROM share_links WHERE id = ?', [$r['link']['id']]));
    // İkinci ziyaret (aynı tarayıcı, sayım çerezi) sayılmaz.
    sh_req('GET', '/s/' . $r['token'], ['cookies' => sh_cookie_jar($res)]);
    assert_eq(1, (int) db_val('SELECT views FROM share_links WHERE id = ?', [$r['link']['id']]));
});

dbtest('test_share_media_keys_are_per_link_and_scoped', function () {
    $u = user_create('a@example.com', 'parola12345', 'Ali');
    $ids = sh_photos($u, 3);
    $a = share_create($u, ['kind' => 'selection', 'ids' => [$ids[0], $ids[1]]]);
    $b = share_create($u, ['kind' => 'selection', 'ids' => [$ids[2]]]);
    $ka = sh_keys(sh_req('GET', '/s/' . $a['token'])['body']);
    $kb = sh_keys(sh_req('GET', '/s/' . $b['token'])['body']);
    assert_eq(2, count($ka));
    $ok = sh_req('GET', "/s/{$a['token']}/m/{$ka[0]}/t");
    assert_eq(200, $ok['status']);
    assert_true(isset($ok['file']) && is_file($ok['file']['path']));
    assert_eq(200, sh_req('GET', "/s/{$a['token']}/m/{$ka[1]}/p")['status']);
    // B bağlantısının anahtarı A'da, A'nınki B'de çalışmaz (IDOR).
    assert_eq(404, sh_req('GET', "/s/{$a['token']}/m/{$kb[0]}/t")['status']);
    assert_eq(404, sh_req('GET', "/s/{$b['token']}/m/{$ka[0]}/t")['status']);
    // Ham asset kimliği, rastgele anahtar, yol geçişi.
    assert_eq(404, sh_req('GET', "/s/{$a['token']}/m/{$ids[0]}/t")['status']);
    assert_eq(404, sh_req('GET', "/s/{$a['token']}/m/AAAAAAAAAAAAAAAAAAAAAA/t")['status']);
    assert_eq(404, sh_req('GET', "/s/{$a['token']}/m/../../../config/config.php")['status']);
    assert_eq(404, sh_req('GET', "/s/{$a['token']}/m/{$ka[0]}/../../t")['status']);
    assert_eq(404, sh_req('GET', '/s/assets/../../includes/functions.php')['status']);
    assert_eq(404, sh_req('GET', '/s/assets/%2e%2e/config/config.php')['status']);
    assert_eq(404, sh_req('GET', '/api/v1/assets')['status'], 'yalnız /s/ altı');
    assert_eq(404, sh_req('GET', '/s/')['status']);
    // Varyant: indirme kapalıyken orijinal ve zip yasak.
    assert_eq(403, sh_req('GET', "/s/{$a['token']}/m/{$ka[0]}/o")['status']);
    assert_eq(403, sh_req('GET', "/s/{$a['token']}/zip")['status']);
    assert_eq(404, sh_req('GET', "/s/{$a['token']}/m/{$ka[0]}/x")['status']);
    // Çöpe atılan öğe bağlantıdan düşer.
    asset_trash($u, [$ids[0]]);
    assert_eq(1, count(sh_keys(sh_req('GET', '/s/' . $a['token'])['body'])));
    $gone = array_values(array_diff($ka, sh_keys(sh_req('GET', '/s/' . $a['token'])['body'])))[0];
    assert_eq(404, sh_req('GET', "/s/{$a['token']}/m/$gone/t")['status']);
    // İptal edilince medya da kapanır.
    share_revoke($u, (int) $a['link']['id']);
    assert_eq(410, sh_req('GET', '/s/' . $a['token'])['status']);
    assert_eq(404, sh_req('GET', "/s/{$a['token']}/m/{$ka[1]}/t")['status']);
});

dbtest('test_share_album_link_follows_album_and_states_pages', function () {
    $u = user_create('a@example.com', 'parola12345', 'Ali');
    $ids = sh_photos($u, 3);
    $al = album_create($u, 'Yaz');
    album_add_assets($al, $u, [$ids[0]]);
    $r = share_create($u, ['kind' => 'album', 'album_id' => $al]);
    assert_eq(1, count(sh_keys(sh_req('GET', '/s/' . $r['token'])['body'])));
    album_add_assets($al, $u, [$ids[1]]);
    assert_eq(2, count(sh_keys(sh_req('GET', '/s/' . $r['token'])['body'])), 'albüme eklenen görünür');
    $keys = sh_keys(sh_req('GET', '/s/' . $r['token'])['body']);
    album_remove_assets($al, $u, [$ids[0]]);
    $after = sh_keys(sh_req('GET', '/s/' . $r['token'])['body']);
    assert_eq(1, count($after));
    foreach (array_diff($keys, $after) as $k) {
        assert_eq(404, sh_req('GET', "/s/{$r['token']}/m/$k/p")['status'], 'albümden çıkarılan');
    }
    $nf = sh_req('GET', '/s/' . str_repeat('A', 43));
    assert_eq(404, $nf['status']);
    assert_true(str_contains($nf['body'], 'bulunamadı'));
    db_exec('UPDATE share_links SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $r['link']['id']]);
    $ex = sh_req('GET', '/s/' . $r['token']);
    assert_eq(410, $ex['status']);
    assert_true(str_contains($ex['body'], 'süresi dolmuş'));
    assert_true(!str_contains($ex['body'], 'Yaz'), 'süresi dolan sayfada başlık yok');
});

dbtest('test_share_password_flow_rate_limit_and_cookie_scope', function () {
    $u = user_create('a@example.com', 'parola12345', 'Ali');
    $ids = sh_photos($u, 2);
    $a = share_create($u, ['kind' => 'selection', 'ids' => [$ids[0]], 'password' => 'anneanne2025', 'title' => 'Gizli albüm']);
    $b = share_create($u, ['kind' => 'selection', 'ids' => [$ids[1]], 'password' => 'anneanne2025']);
    $page = sh_req('GET', '/s/' . $a['token']);
    assert_eq(401, $page['status']);
    assert_true(str_contains($page['body'], 'type="password"'));
    assert_true(!str_contains($page['body'], 'Gizli albüm'), 'parolasız başlık görünmez');
    assert_true(!str_contains($page['body'], 'og:image'), 'parolalıda önizleme görseli yok');
    assert_eq(0, count(sh_keys($page['body'])));
    assert_eq(0, (int) db_val('SELECT views FROM share_links WHERE id = ?', [$a['link']['id']]));
    $wrong = sh_req('POST', '/s/' . $a['token'], ['post' => ['password' => 'yanlis']]);
    assert_eq(401, $wrong['status']);
    assert_true(str_contains($wrong['body'], 'Parola yanlış'));
    $ok = sh_req('POST', '/s/' . $a['token'], ['post' => ['password' => 'anneanne2025']]);
    assert_eq(303, $ok['status']);
    $jar = sh_cookie_jar($ok);
    assert_true(count($jar) === 1);
    $c = $ok['cookies'][0];
    assert_eq('/s/' . $a['token'], $c['path'], 'çerez yalnız bu bağlantıya');
    assert_true($c['httponly'] && $c['secure']);
    $g = sh_req('GET', '/s/' . $a['token'], ['cookies' => $jar]);
    assert_eq(200, $g['status']);
    assert_true(str_contains($g['body'], 'Gizli albüm'));
    $k = sh_keys($g['body'])[0];
    assert_eq(200, sh_req('GET', "/s/{$a['token']}/m/$k/t", ['cookies' => $jar])['status']);
    assert_eq(401, sh_req('GET', "/s/{$a['token']}/m/$k/t")['status'], 'çerezsiz medya kapalı');
    // A'nın çerezi B'yi açmaz (aynı parola olsa da).
    assert_eq(401, sh_req('GET', '/s/' . $b['token'], ['cookies' => $jar])['status']);
    // Sahte çerez.
    assert_eq(401, sh_req('GET', '/s/' . $a['token'], ['cookies' => [array_key_first($jar) => (time() + 999) . '.AAAA']])['status']);
    // Parola değişince eski çerez geçersiz.
    share_update($u, (int) $a['link']['id'], ['password' => 'yenisifre99']);
    assert_eq(401, sh_req('GET', '/s/' . $a['token'], ['cookies' => $jar])['status']);
    // Kaba kuvvet: IP başına sınır, sonra doğru parola da reddedilir.
    $limited = false;
    for ($i = 0; $i < 12; $i++) {
        $res = sh_req('POST', '/s/' . $b['token'], ['post' => ['password' => 'tahmin' . $i], 'ip' => '198.51.100.9']);
        if ($res['status'] === 429) {
            $limited = true;
            break;
        }
    }
    assert_true($limited, 'deneme sınırı');
    assert_eq(429, sh_req('POST', '/s/' . $b['token'], ['post' => ['password' => 'anneanne2025'], 'ip' => '198.51.100.9'])['status']);
    assert_eq(303, sh_req('POST', '/s/' . $b['token'], ['post' => ['password' => 'anneanne2025'], 'ip' => '198.51.100.10'])['status'], 'başka IP etkilenmez');
    // Dağıtık deneme: bağlantı başına genel sınır.
    $limited = false;
    for ($i = 0; $i < 60; $i++) {
        $res = sh_req('POST', '/s/' . $b['token'], ['post' => ['password' => 'x' . $i], 'ip' => '192.0.2.' . $i]);
        if ($res['status'] === 429) {
            $limited = true;
            break;
        }
    }
    assert_true($limited, 'bağlantı başına genel sınır');
});

dbtest('test_share_token_guessing_is_rate_limited', function () {
    $hit = false;
    for ($i = 0; $i < 80; $i++) {
        $res = sh_req('GET', '/s/' . b64url(random_bytes(32)), ['ip' => '203.0.113.99']);
        if ($res['status'] === 429) {
            $hit = true;
            break;
        }
        assert_eq(404, $res['status']);
    }
    assert_true($hit, 'tahmin denemeleri sınırlanır');
});

dbtest('test_share_download_zip_and_gps_stripping', function () {
    $u = user_create('a@example.com', 'parola12345', 'Ali');
    $ids = sh_photos($u, 2, true);
    $exif = tools_detect(true)['exiftool'];
    $hasGps = function (string $bytes) use ($exif): bool {
        $f = temp_dir() . '/x.jpg';
        file_put_contents($f, $bytes);
        $r = tool_run([$exif, '-j', '-n', '-a', '-G1', '-gps:all', '-xmp:all', '-Keys:all', $f]);
        $j = json_decode($r['out'], true)[0] ?? [];
        unset($j['SourceFile']);
        return $j !== [];
    };
    // Kaynakta gerçekten GPS var.
    assert_true($hasGps((string) file_get_contents(blob_path((string) db_val('SELECT blob_sha1 FROM assets WHERE id = ?', [$ids[0]])))), 'fikstürde GPS var');
    $hid = share_create($u, ['kind' => 'selection', 'ids' => $ids, 'allow_download' => true, 'show_location' => false]);
    $vis = share_create($u, ['kind' => 'selection', 'ids' => $ids, 'allow_download' => true, 'show_location' => true]);
    $kh = sh_keys(sh_req('GET', '/s/' . $hid['token'])['body']);
    $kv = sh_keys(sh_req('GET', '/s/' . $vis['token'])['body']);
    foreach (['t', 'p'] as $v) {
        assert_true(!$hasGps(sh_body(sh_req('GET', "/s/{$hid['token']}/m/{$kh[0]}/$v"))), "türev $v GPS içermez");
    }
    $orig = sh_req('GET', "/s/{$hid['token']}/m/{$kh[0]}/o");
    assert_eq(200, $orig['status']);
    $bytes = sh_body($orig);
    assert_true(str_starts_with($bytes, "\xFF\xD8"), 'geçerli JPEG');
    assert_true(!$hasGps($bytes), 'konum gizli: orijinalde GPS silinir');
    assert_true(str_contains(strtolower(json_encode($orig['headers'])), 'attachment'));
    $origV = sh_body(sh_req('GET', "/s/{$vis['token']}/m/{$kv[0]}/o"));
    assert_true($hasGps($origV), 'konum açık: orijinal aynen');
    // Zip: geçerli arşiv, iki dosya, GPS yok.
    $zip = sh_req('GET', "/s/{$hid['token']}/zip");
    assert_eq(200, $zip['status']);
    $zf = temp_dir() . '/a.zip';
    file_put_contents($zf, sh_body($zip));
    $za = new ZipArchive();
    assert_eq(true, $za->open($zf, ZipArchive::CHECKCONS));
    assert_eq(2, $za->numFiles);
    $names = [$za->getNameIndex(0), $za->getNameIndex(1)];
    sort($names);
    assert_eq(['IMG_1.jpg', 'IMG_2.jpg'], $names);
    assert_true(!$hasGps((string) $za->getFromIndex(0)), 'zip içinde GPS yok');
    $za->close();
    // Orijinali değişmedi.
    assert_true($hasGps((string) file_get_contents(blob_path((string) db_val('SELECT blob_sha1 FROM assets WHERE id = ?', [$ids[0]])))), 'orijinal dosya korunur');
});

test('test_share_jpeg_strip_removes_app1_and_keeps_image', function () {
    $i = imagecreatetruecolor(20, 10);
    ob_start();
    imagejpeg($i);
    $jpg = (string) ob_get_clean();
    $app1 = "\xFF\xE1" . pack('n', 2 + 14) . "Exif\0\0GPSDATA!";
    $with = substr($jpg, 0, 2) . $app1 . substr($jpg, 2);
    $out = share_jpeg_strip($with);
    assert_true(!str_contains($out, 'GPSDATA'));
    assert_eq($jpg, $out);
    assert_true(imagecreatefromstring($out) !== false);
});

test('test_share_zip_writer_produces_valid_archive', function () {
    $dir = temp_dir();
    file_put_contents("$dir/a.txt", str_repeat('merhaba ', 1000));
    $buf = '';
    share_zip_write([
        ['name' => 'Fotoğraflar/a.txt', 'mtime' => 1700000000, 'open' => fn() => fopen("$dir/a.txt", 'rb')],
        ['name' => 'b.txt', 'mtime' => 1700000000, 'open' => function () { $f = fopen('php://memory', 'w+b'); fwrite($f, 'ikinci'); rewind($f); return $f; }],
    ], function (string $c) use (&$buf) { $buf .= $c; });
    file_put_contents("$dir/z.zip", $buf);
    $z = new ZipArchive();
    assert_eq(true, $z->open("$dir/z.zip", ZipArchive::CHECKCONS));
    assert_eq(str_repeat('merhaba ', 1000), $z->getFromName('Fotoğraflar/a.txt'));
    assert_eq('ikinci', $z->getFromName('b.txt'));
    $z->close();
});

test('test_share_date_range_turkish', function () {
    assert_eq('14 Ağustos 2025', share_date_range('2025-08-14', '2025-08-14'));
    assert_eq('12–18 Ağustos 2025', share_date_range('2025-08-12', '2025-08-18'));
    assert_eq('28 Temmuz – 3 Ağustos 2025', share_date_range('2025-07-28', '2025-08-03'));
    assert_eq('Aralık 2024 – Mart 2025', share_date_range('2024-12-30', '2025-03-02'));
    assert_eq('', share_date_range(null, null));
});

/* --- Doğrulayıcı eklemeleri (adversarial review) --- */

dbtest('test_share_clean_download_falls_back_when_exiftool_cannot_write', function () {
    // exiftool'un yazamadığı (BMP) ya da bozuk bir orijinal: boş (0 bayt) dosya yerine metadatasız önizleme verilmeli.
    if (!tools_detect(true)['exiftool']) {
        skip('exiftool yok');
    }
    $u = user_create('a@example.com', 'parola12345', 'Ali');
    $ids = sh_photos($u, 2);
    $sha = (string) db_val('SELECT blob_sha1 FROM assets WHERE id = ?', [$ids[0]]);
    // Orijinali exiftool'un yazamayacağı bir içerikle değiştir (yalnız test deposu).
    file_put_contents(blob_path($sha), "BM" . str_repeat("\0", 64) . 'GPSSECRET');
    db_exec("UPDATE assets SET mime = 'image/bmp', original_filename = 'Eski.bmp' WHERE id = ?", [$ids[0]]);
    $r = share_create($u, ['kind' => 'selection', 'ids' => $ids, 'allow_download' => true]);
    $keys = sh_keys(sh_req('GET', "/s/{$r['token']}")['body']);
    $byId = [];
    foreach ($keys as $k) {
        $byId[share_item_asset($r['token'], $k)] = $k;
    }
    $res = sh_req('GET', "/s/{$r['token']}/m/{$byId[$ids[0]]}/o");
    $body = sh_body($res);
    assert_true(strlen($body) > 20 && (str_starts_with($body, "\xFF\xD8") || str_starts_with($body, 'RIFF')), 'indirme boş olmamalı, önizleme görseli (' . strlen($body) . ' bayt)');
    assert_true(!str_contains($body, 'GPSSECRET'), 'ham orijinal sızmamalı');
    $ct = (string) ($res['headers']['Content-Type'] ?? ($res['file']['mime'] ?? ''));
    assert_true(in_array($ct, ['image/jpeg', 'image/webp'], true), "türü önizlemeye uymalı: $ct");
    // Zip: iki dosya da boş olmamalı.
    $zip = sh_body(sh_req('GET', "/s/{$r['token']}/zip"));
    $zf = temp_dir() . '/a.zip';
    file_put_contents($zf, $zip);
    $za = new ZipArchive();
    assert_eq(true, $za->open($zf, ZipArchive::CHECKCONS));
    assert_eq(2, $za->numFiles);
    for ($i = 0; $i < $za->numFiles; $i++) {
        $st = $za->statIndex($i);
        assert_true($st['size'] > 20, 'zip öğesi boş: ' . $st['name']);
        assert_true(!str_contains((string) $za->getFromIndex($i), 'GPSSECRET'));
    }
});

dbtest('test_share_robots_and_browser_probes_do_not_lock_out_visitors', function () {
    // Tarayıcıların ve önizleme botlarının kendiliğinden istediği yollar (favicon, apple-touch-icon, robots.txt)
    // "tahmin" sayılmamalı: aynı IP'den (Funnel arkasında herkes aynı IP görünebilir) geçerli bağlantı açılmalı.
    $u = user_create('a@example.com', 'parola12345', 'Ali');
    $ids = sh_photos($u, 1);
    $r = share_create($u, ['kind' => 'selection', 'ids' => $ids]);
    $rb = sh_req('GET', '/robots.txt');
    assert_eq(200, $rb['status']);
    assert_true(str_contains((string) $rb['body'], 'Disallow: /'));
    for ($i = 0; $i < 30; $i++) {
        foreach (['/favicon.ico', '/apple-touch-icon.png', '/apple-touch-icon-precomposed.png', '/robots.txt', '/wp-login.php', '/.env', '/s/kisa'] as $p) {
            sh_req('GET', $p);
        }
    }
    assert_eq(200, sh_req('GET', "/s/{$r['token']}")['status'], 'tarayıcı/tarayıcı-bot yoklamaları kilitlememeli');
    // Belirteç biçimindeki bilinmeyen adresler ise sayılır (tahmin): 40'tan sonra 429.
    for ($i = 0; $i < 41; $i++) {
        sh_req('GET', '/s/' . share_token_new(), ['ip' => '203.0.113.99']);
    }
    assert_eq(429, sh_req('GET', "/s/{$r['token']}", ['ip' => '203.0.113.99'])['status']);
});

dbtest('test_share_list_cover_and_count_match_gallery_without_loading_items', function () {
    $u = user_create('a@example.com', 'parola12345', 'Ali');
    $ids = sh_photos($u, 3);
    $al = album_create($u, 'Kapak');
    album_add_assets($al, $u, $ids);
    $r = share_create($u, ['kind' => 'album', 'album_id' => $al]);
    $link = db_row('SELECT * FROM share_links WHERE id = ?', [$r['link']['id']]);
    $check = function () use ($link) {
        $items = share_items($link);
        $j = share_to_json($link);
        assert_eq(count($items), $j['count']);
        assert_eq(share_cover_id($link, $items), $j['cover_asset_id']);
    };
    $check();
    db_exec('UPDATE albums SET cover_asset_id = ? WHERE id = ?', [$ids[2], $al]);
    $check();
    db_exec('UPDATE assets SET trashed_at = ? WHERE id = ?', [now_utc(), $ids[2]]); // çöpteki kapak
    $check();
    db_exec("UPDATE assets SET type = 'video' WHERE id = ?", [$ids[0]]); // ilk öğe video: kapak ilk fotoğraf
    db_exec('UPDATE albums SET cover_asset_id = NULL WHERE id = ?', [$al]);
    $check();
    $s = share_create($u, ['kind' => 'selection', 'ids' => [$ids[0], $ids[1]]]);
    $sl = db_row('SELECT * FROM share_links WHERE id = ?', [$s['link']['id']]);
    assert_eq(share_cover_id($sl, share_items($sl)), share_to_json($sl)['cover_asset_id']);
    assert_eq(2, share_to_json($sl)['count']);
});
