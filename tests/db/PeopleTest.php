<?php
/**
 * Kişiler: elle etiketleme, yüz atama, birleştirme, gizleme, silme, süzgeç/arama, yüz kırpma,
 * asset_people (Takeout) taşıma ve kalıcı silmede temizlik.
 */
declare(strict_types=1);

/** Yüz satırını doğrudan ekler (ML'in yazdığı gibi). $box: [x1,y1,x2,y2] 0..1. */
function pp_face(array $u, string $assetId, array $box = [0.25, 0.25, 0.5, 0.6], ?int $personId = null, float $score = 0.9, ?string $by = null): int
{
    db_exec('INSERT INTO faces (asset_id, user_id, person_id, x1, y1, x2, y2, score, embedding, model, assigned_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
        $assetId, (int) $u['id'], $personId, $box[0], $box[1], $box[2], $box[3], $score, str_repeat("\0", 2048), 'buffalo_l', $personId !== null ? ($by ?? 'auto') : null, now_utc(),
    ]);
    $id = (int) db()->lastInsertId();
    if ($personId !== null) {
        db_exec("INSERT IGNORE INTO asset_person (asset_id, person_id, source, created_at) VALUES (?, ?, 'face', ?)", [$assetId, $personId, now_utc()]);
    }
    return $id;
}

/** Adsız (yüz kümesi) kişi. */
function pp_cluster(array $u): int
{
    db_exec('INSERT INTO people (user_id, name, hidden, created_at, updated_at) VALUES (?, NULL, 0, ?, ?)', [(int) $u['id'], now_utc(), now_utc()]);
    return (int) db()->lastInsertId();
}

function pp_tag(array $u, string $assetId, string $name): array
{
    return call('POST', "/v1/assets/$assetId/people", $u, ['name' => $name])['json'];
}

function pp_pid(array $u, string $name): int
{
    return (int) db_val('SELECT id FROM people WHERE user_id = ? AND name = ?', [(int) $u['id'], $name]);
}

/** ML çağrılarını yakalar; testten sonra kaldırılır. */
function pp_test(string $name, callable $fn): void
{
    dbtest($name, function () use ($fn) {
        $GLOBALS['__ml_calls'] = [];
        ml_client_override(function (string $method, string $path, array $payload): ?array {
            $GLOBALS['__ml_calls'][] = [$method, $path, $payload];
            return $GLOBALS['__ml_reply'][$path] ?? null;
        });
        try {
            $fn();
        } finally {
            ml_client_override(null);
            $GLOBALS['__ml_reply'] = [];
        }
    });
}

function pp_reclusters(): int
{
    return count(array_filter($GLOBALS['__ml_calls'], fn($c) => $c[0] === 'POST' && $c[1] === '/faces/recluster'));
}

pp_test('test_people_manual_tag_creates_person_and_untag', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $r = pp_tag($u, $a, '  Ayşe ');
    assert_eq(1, count($r['people']));
    assert_eq('Ayşe', $r['people'][0]['name']);
    assert_eq('manual', $r['people'][0]['source']);
    assert_eq([], $r['faces']);
    $pid = $r['people'][0]['id'];
    assert_true(is_int($pid));
    // Aynı adla tekrar etiketleme yeni kişi açmaz; person_id ile de olur.
    $b = api_asset($u, '2020-01-02 00:00:00', 2);
    assert_eq($pid, pp_tag($u, $b, 'ayşe')['people'][0]['id']);
    $c = api_asset($u, '2020-01-03 00:00:00', 3);
    assert_eq($pid, call('POST', "/v1/assets/$c/people", $u, ['person_id' => $pid])['json']['people'][0]['id']);
    assert_eq(1, (int) db_val('SELECT COUNT(*) FROM people'));
    // Asset ayrıntısındaki eski 'people' alanı adları döner.
    assert_eq(['Ayşe'], call('GET', "/v1/assets/$a", $u)['json']['asset']['people']);
    assert_eq(['Ayşe'], array_column(call('GET', "/v1/assets/$a/people", $u)['json']['people'], 'name'));
    $p = call('GET', "/v1/people/$pid", $u)['json']['person'];
    assert_eq(['id' => $pid, 'name' => 'Ayşe', 'count' => 3, 'cover_face_id' => null, 'cover_asset_id' => $c, 'hidden' => false], $p);
    // Çıkarma.
    $r = call('DELETE', "/v1/assets/$a/people/$pid", $u)['json'];
    assert_eq([], $r['people']);
    assert_eq([], call('GET', "/v1/assets/$a", $u)['json']['asset']['people']);
    assert_eq(2, call('GET', "/v1/people/$pid", $u)['json']['person']['count']);
    // Ad ya da kişi yoksa 422.
    assert_eq(422, call_err('POST', "/v1/assets/$a/people", $u, ['name' => '  '])->status);
    assert_eq(422, call_err('POST', "/v1/assets/$a/people", $u, ['name' => str_repeat('x', 191)])->status);
});

pp_test('test_people_bulk_tag', function () {
    $u = api_user();
    $v = api_user('veli@example.com');
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2020-01-02 00:00:00', 2);
    $theirs = api_asset($v, '2020-01-02 00:00:00', 3);
    $r = call('POST', '/v1/assets/people', $u, ['ids' => [$a, $b, $theirs, 'bozuk'], 'name' => 'Mehmet'])['json'];
    assert_eq(2, $r['count']);
    assert_eq('Mehmet', $r['person']['name']);
    assert_eq(2, $r['person']['count']);
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM asset_person WHERE asset_id = ?', [$theirs]));
    // Tekrarı sayar ama çoğaltmaz; person_id ile.
    $r = call('POST', '/v1/assets/people', $u, ['ids' => [$a], 'person_id' => $r['person']['id']])['json'];
    assert_eq(1, $r['count']);
    assert_eq(2, (int) db_val('SELECT COUNT(*) FROM asset_person'));
    assert_eq(422, call_err('POST', '/v1/assets/people', $u, ['ids' => array_fill(0, 501, $a), 'name' => 'X'])->status);
    assert_eq(422, call_err('POST', '/v1/assets/people', $u, ['ids' => [$a]])->status);
    // Hiçbiri kendisinin değilse 404 ve boş kişi oluşmaz.
    assert_eq(404, call_err('POST', '/v1/assets/people', $u, ['ids' => [$theirs], 'name' => 'Hırsız'])->status);
    assert_eq(0, (int) db_val("SELECT COUNT(*) FROM people WHERE name = 'Hırsız'"));
});

pp_test('test_people_list_order_and_unnamed_threshold', function () {
    $u = api_user();
    $as = [];
    for ($i = 1; $i <= 5; $i++) {
        $as[] = api_asset($u, "2020-01-0$i 00:00:00", $i);
    }
    pp_tag($u, $as[0], 'Az');
    foreach ([$as[0], $as[1], $as[2]] as $a) {
        pp_tag($u, $a, 'Çok');
    }
    $big = pp_cluster($u);
    $f1 = pp_face($u, $as[1], [0.1, 0.1, 0.2, 0.2], $big, 0.8);
    $f2 = pp_face($u, $as[2], [0.1, 0.1, 0.2, 0.2], $big, 0.95);
    pp_face($u, $as[3], [0.1, 0.1, 0.2, 0.2], $big, 0.7);
    $single = pp_cluster($u);
    pp_face($u, $as[4], [0.5, 0.5, 0.6, 0.6], $single);
    // Adlı ama fotoğrafsız kişi listede görünmez.
    db_exec('INSERT INTO people (user_id, name, hidden, created_at, updated_at) VALUES (?, ?, 0, ?, ?)', [(int) $u['id'], 'Boş', now_utc(), now_utc()]);
    $list = call('GET', '/v1/people', $u)['json']['people'];
    assert_eq(['Çok', 'Az', null], array_column($list, 'name'));
    assert_eq([3, 1, 3], array_column($list, 'count'));
    assert_eq($big, $list[2]['id']);
    assert_eq($f2, $list[2]['cover_face_id'], 'kapak: en yüksek puanlı yüz');
    assert_eq($as[2], $list[2]['cover_asset_id']);
    // Kapak seçimi.
    $p = call('PATCH', "/v1/people/$big", $u, ['cover_face_id' => $f1])['json']['person'];
    assert_eq($f1, $p['cover_face_id']);
    assert_eq($as[1], $p['cover_asset_id']);
    $other = pp_face($u, $as[4], [0.7, 0.7, 0.8, 0.8]);
    assert_eq(422, call_err('PATCH', "/v1/people/$big", $u, ['cover_face_id' => $other])->status);
    // Çöpteki fotoğraflar sayılmaz.
    asset_trash((int) $u['id'], [$as[0]]);
    $list = call('GET', '/v1/people', $u)['json']['people'];
    assert_eq(['Çok', null], array_column($list, 'name'));
    assert_eq(2, $list[0]['count']);
});

pp_test('test_people_rename_conflict_and_merge', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2020-01-02 00:00:00', 2);
    $c = api_asset($u, '2020-01-03 00:00:00', 3);
    $ali = pp_tag($u, $a, 'Ali')['people'][0]['id'];
    $cl = pp_cluster($u);
    $fb = pp_face($u, $b, [0.1, 0.1, 0.3, 0.3], $cl);
    $fc = pp_face($u, $c, [0.1, 0.1, 0.3, 0.3], $cl);
    $fa = pp_face($u, $a, [0.5, 0.1, 0.7, 0.3], $cl); // Ali'nin fotoğrafında da yüzü var
    $rejected = pp_face($u, $c, [0.6, 0.6, 0.7, 0.7]);
    db_exec('UPDATE faces SET rejected_person_id = ? WHERE id = ?', [$cl, $rejected]);
    // Adlandırma: başka kişinin adı → 409 name_exists + diğer kişi.
    $e = call_err('PATCH', "/v1/people/$cl", $u, ['name' => 'ali']);
    assert_eq(409, $e->status);
    assert_eq('name_exists', $e->errorCode);
    assert_eq($ali, $e->extra['person_id']);
    assert_eq('Ali', $e->extra['person']['name']);
    // Kendi adıyla aynı ad sorun değil.
    $p = call('PATCH', "/v1/people/$ali", $u, ['name' => 'Ali'])['json']['person'];
    assert_eq('Ali', $p['name']);
    assert_eq(0, pp_reclusters(), 'ad değişmediyse yeniden kümeleme yok');
    assert_eq('Geçici', call('PATCH', "/v1/people/$cl", $u, ['name' => 'Geçici'])['json']['person']['name']);
    assert_eq(1, pp_reclusters(), 'yeniden adlandırmada ML yeniden kümeleme');
    $before = pp_reclusters();
    // Birleştirme: küme → Ali.
    $r = call('POST', "/v1/people/$cl/merge", $u, ['into' => $ali])['json'];
    assert_eq($ali, $r['person']['id']);
    assert_eq(3, $r['person']['count']);
    assert_eq(null, db_row('SELECT id FROM people WHERE id = ?', [$cl]));
    assert_eq(3, (int) db_val('SELECT COUNT(*) FROM faces WHERE person_id = ?', [$ali]));
    assert_eq($ali, (int) db_val('SELECT rejected_person_id FROM faces WHERE id = ?', [$rejected]));
    assert_eq('manual', db_val('SELECT source FROM asset_person WHERE asset_id = ? AND person_id = ?', [$a, $ali]), 'elle etiket korunur');
    assert_eq('face', db_val('SELECT source FROM asset_person WHERE asset_id = ? AND person_id = ?', [$b, $ali]));
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM asset_person WHERE person_id = ?', [$cl]));
    assert_eq($before + 1, pp_reclusters());
    assert_eq(422, call_err('POST', "/v1/people/$ali/merge", $u, ['into' => $ali])->status);
    // Adsız hedefe adlı kaynak birleşince ad taşınır.
    $cl2 = pp_cluster($u);
    pp_face($u, $b, [0.8, 0.8, 0.9, 0.9], $cl2);
    $r = call('POST', "/v1/people/$ali/merge", $u, ['into' => $cl2])['json'];
    assert_eq('Ali', $r['person']['name']);
    assert_eq($cl2, $r['person']['id']);
    // Adı kaldırmak (null) kişiyi adsız yapar.
    assert_eq(null, call('PATCH', "/v1/people/$cl2", $u, ['name' => null])['json']['person']['name']);
    unset($fb, $fc, $fa);
});

pp_test('test_people_hide_and_delete', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2020-01-02 00:00:00', 2);
    $pid = pp_tag($u, $a, 'Gizli')['people'][0]['id'];
    $f = pp_face($u, $b, [0.1, 0.1, 0.3, 0.3], $pid);
    $p = call('PATCH', "/v1/people/$pid", $u, ['hidden' => true])['json']['person'];
    assert_true($p['hidden']);
    assert_eq([], call('GET', '/v1/people', $u)['json']['people']);
    $all = call('GET', '/v1/people', $u, [], ['include_hidden' => '1'])['json']['people'];
    assert_eq([$pid], array_column($all, 'id'));
    assert_eq(true, $all[0]['hidden']);
    call('PATCH', "/v1/people/$pid", $u, ['hidden' => false]);
    assert_eq(1, count(call('GET', '/v1/people', $u)['json']['people']));
    // Silme: etiketler gider, yüzler atanmamış + reddedilmiş olur.
    assert_eq(['ok' => true], call('DELETE', "/v1/people/$pid", $u)['json']);
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM people'));
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM asset_person'));
    $face = db_row('SELECT person_id, rejected_person_id FROM faces WHERE id = ?', [$f]);
    assert_eq(null, $face['person_id']);
    assert_eq($pid, (int) $face['rejected_person_id']);
    assert_eq(404, call_err('GET', "/v1/people/$pid", $u)->status);
});

pp_test('test_people_person_filter_and_search', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2020-01-02 00:00:00', 2);
    $c = api_asset($u, '2020-01-03 00:00:00', 3, ['is_favorite' => true]);
    $pid = pp_tag($u, $a, 'Zeynep Kaya')['people'][0]['id'];
    pp_tag($u, $c, 'Zeynep Kaya');
    pp_tag($u, $b, 'Başka');
    $ids = array_column(call('GET', '/v1/assets', $u, [], ['person' => (string) $pid])['json']['assets'], 'id');
    assert_eq([$c, $a], $ids);
    $fav = array_column(call('GET', '/v1/assets', $u, [], ['person' => (string) $pid, 'favorite' => '1'])['json']['assets'], 'id');
    assert_eq([$c], $fav);
    assert_eq([['month' => '2020-01', 'count' => 2]], call('GET', '/v1/assets/summary', $u, [], ['person' => (string) $pid])['json']['months']);
    assert_eq(404, call_err('GET', '/v1/assets', $u, [], ['person' => '999999'])->status);
    // Metin araması kişi adlarında da arar (ML yok → metin kipi).
    $r = call('GET', '/v1/search', $u, [], ['q' => 'zeynep'])['json'];
    assert_eq('text', $r['mode']);
    assert_eq([$c, $a], array_column($r['assets'], 'id'));
    assert_eq([$b], array_column(call('GET', '/v1/assets', $u, [], ['q' => 'Başka'])['json']['assets'], 'id'));
    // Adsız kişiler aramada eşleşmez; eski asset_people tablosu artık okunmaz.
    db_exec('INSERT INTO asset_people (asset_id, name) VALUES (?, ?)', [$b, 'Eski Ad']);
    assert_eq([], call('GET', '/v1/assets', $u, [], ['q' => 'Eski Ad'])['json']['assets']);
});

pp_test('test_people_smart_search_name_in_query', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2020-01-02 00:00:00', 2);
    $c = api_asset($u, '2020-01-03 00:00:00', 3);
    $d = api_asset($u, '2020-01-04 00:00:00', 4);
    pp_tag($u, $a, 'Zeynep Kaya');
    pp_tag($u, $c, 'Zeynep Kaya');
    pp_tag($u, $d, 'Zeynep Kaya');
    // ML her sorguya aynı sırayı verir; $d hiç sıralanmaz.
    $GLOBALS['__ml_reply']['/search'] = ['results' => [['id' => $b, 'score' => 0.9], ['id' => $c, 'score' => 0.8], ['id' => $a, 'score' => 0.7]]];
    // "zeynep kaya plajda": kişinin fotoğrafları önce (kalan sözcüklerle akıllı sıra, sonra tarih), sonra diğerleri.
    $r = call('GET', '/v1/search', $u, [], ['q' => 'zeynep kaya plajda'])['json'];
    assert_eq('smart', $r['mode']);
    assert_eq([$c, $a, $d, $b], array_column($r['assets'], 'id'));
    $texts = array_column(array_column(array_values(array_filter($GLOBALS['__ml_calls'], fn($x) => $x[1] === '/search')), 2), 'text');
    assert_true(in_array('plajda', $texts, true), 'kalan sözcüklerle arar: ' . json_encode($texts));
    // i/ı farkı eşleşmeyi bozmaz ("Ivan" → "ivan").
    pp_tag($u, $b, 'Ivan');
    $r = call('GET', '/v1/search', $u, [], ['q' => 'ivan deniz'])['json'];
    assert_eq($b, $r['assets'][0]['id']);
    // Ad bir sözcüğün parçasıysa eşleşmez ("zeynepler"); sıra değişmez.
    $GLOBALS['__ml_calls'] = [];
    $r = call('GET', '/v1/search', $u, [], ['q' => 'zeynepler plajda'])['json'];
    assert_eq([$b, $c, $a], array_column($r['assets'], 'id'));
});

pp_test('test_people_faces_payload_and_reassignment', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $cl = pp_cluster($u);
    $f1 = pp_face($u, $a, [0.6, 0.1, 0.8, 0.4], $cl);
    $f2 = pp_face($u, $a, [0.1, 0.1, 0.3, 0.4]);
    $r = call('GET', "/v1/assets/$a/people", $u)['json'];
    assert_eq([['id' => $cl, 'name' => null, 'source' => 'face']], $r['people']);
    assert_eq([$f2, $f1], array_column($r['faces'], 'id'), 'yüzler soldan sağa');
    assert_eq(['id' => $f2, 'x1' => 0.1, 'y1' => 0.1, 'x2' => 0.3, 'y2' => 0.4, 'person_id' => null, 'person_name' => null], $r['faces'][0]);
    // Adsız yüze ad ver (yeni kişi oluşur).
    $r = call('PATCH', "/v1/faces/$f2", $u, ['name' => 'Elif'])['json'];
    $elif = pp_pid($u, 'Elif');
    assert_eq($elif, $r['faces'][0]['person_id']);
    assert_eq('Elif', $r['faces'][0]['person_name']);
    assert_eq('user', db_val('SELECT assigned_by FROM faces WHERE id = ?', [$f2]));
    assert_eq(['Elif', null], array_column($r['people'], 'name'));
    assert_eq('face', $r['people'][0]['source']);
    assert_eq(1, pp_reclusters());
    // Kümedeki yüzü Elif'e taşı: küme boşalır ve silinir (adsız).
    $r = call('PATCH', "/v1/faces/$f1", $u, ['person_id' => $elif])['json'];
    assert_eq([['id' => $elif, 'name' => 'Elif', 'source' => 'face']], $r['people']);
    assert_eq(null, db_row('SELECT id FROM people WHERE id = ?', [$cl]));
    // Atamayı kaldır: reddedilir, Elif başka yüzüyle fotoğrafta kalır.
    $r = call('PATCH', "/v1/faces/$f1", $u, ['person_id' => null])['json'];
    $face = db_row('SELECT person_id, rejected_person_id FROM faces WHERE id = ?', [$f1]);
    assert_eq(null, $face['person_id']);
    assert_eq($elif, (int) $face['rejected_person_id']);
    assert_eq([$elif], array_column($r['people'], 'id'));
    // Son yüzü de kaldırınca 'face' satırı silinir; adlı kişi (fotoğrafsız) durur.
    $r = call('PATCH', "/v1/faces/$f2", $u, ['person_id' => null])['json'];
    assert_eq([], $r['people']);
    assert_eq($elif, pp_pid($u, 'Elif'));
    // Elle etiket varken yüz kaldırmak etiketi silmez.
    pp_tag($u, $a, 'Elif');
    call('PATCH', "/v1/faces/$f2", $u, ['person_id' => $elif]);
    assert_eq('manual', db_val('SELECT source FROM asset_person WHERE asset_id = ? AND person_id = ?', [$a, $elif]));
    call('PATCH', "/v1/faces/$f2", $u, ['person_id' => null]);
    assert_eq('manual', db_val('SELECT source FROM asset_person WHERE asset_id = ? AND person_id = ?', [$a, $elif]));
    // Fotoğraftan kişiyi çıkarmak o kişinin yüzlerini de ayırır ve reddeder.
    call('PATCH', "/v1/faces/$f1", $u, ['person_id' => $elif]);
    call('DELETE', "/v1/assets/$a/people/$elif", $u);
    $face = db_row('SELECT person_id, rejected_person_id FROM faces WHERE id = ?', [$f1]);
    assert_eq(null, $face['person_id']);
    assert_eq($elif, (int) $face['rejected_person_id']);
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM asset_person WHERE asset_id = ?', [$a]));
    // Reddedilmiş kişiye kullanıcı açıkça atarsa ret kalkar.
    call('PATCH', "/v1/faces/$f1", $u, ['person_id' => $elif]);
    assert_eq(null, db_val('SELECT rejected_person_id FROM faces WHERE id = ?', [$f1]));
    assert_eq(422, call_err('PATCH', "/v1/faces/$f1", $u, [])->status);
});

pp_test('test_people_user_isolation', function () {
    $u = api_user();
    $v = api_user('veli@example.com');
    $mine = api_asset($u, '2020-01-01 00:00:00', 1);
    $theirs = api_asset($v, '2020-01-01 00:00:00', 2);
    $myP = pp_tag($u, $mine, 'Benim')['people'][0]['id'];
    $theirP = pp_tag($v, $theirs, 'Onun')['people'][0]['id'];
    $theirF = pp_face($v, $theirs, [0.1, 0.1, 0.3, 0.3], $theirP);
    // Paylaşılan albümde görebilse bile kişi uçları yalnızca sahibine açık.
    $alb = call('POST', '/v1/albums', $v, ['title' => 'Ortak', 'ids' => [$theirs]])['json']['album']['id'];
    call('POST', "/v1/albums/$alb/members", $v, ['email' => 'ali@example.com']);
    $cases = [
        ['GET', "/v1/people/$theirP", []],
        ['PATCH', "/v1/people/$theirP", ['name' => 'X']],
        ['DELETE', "/v1/people/$theirP", []],
        ['POST', "/v1/people/$theirP/merge", ['into' => $myP]],
        ['POST', "/v1/people/$myP/merge", ['into' => $theirP]],
        ['GET', "/v1/assets/$theirs/people", []],
        ['POST', "/v1/assets/$theirs/people", ['name' => 'X']],
        ['POST', "/v1/assets/$mine/people", ['person_id' => $theirP]],
        ['DELETE', "/v1/assets/$theirs/people/$theirP", []],
        ['DELETE', "/v1/assets/$mine/people/$theirP", []],
        ['PATCH', "/v1/faces/$theirF", ['person_id' => null]],
        ['PATCH', "/v1/faces/$theirF", ['person_id' => $myP]],
        ['GET', "/v1/faces/$theirF/thumb", []],
        ['POST', '/v1/assets/people', ['ids' => [$mine], 'person_id' => $theirP]],
    ];
    foreach ($cases as [$m, $p, $b]) {
        assert_eq(404, call_err($m, $p, $u, $b)->status, "$m $p");
    }
    $myF = pp_face($u, $mine);
    assert_eq(404, call_err('PATCH', "/v1/faces/$myF", $u, ['person_id' => $theirP])->status);
    assert_eq(422, call_err('PATCH', "/v1/people/$myP", $u, ['cover_face_id' => $theirF])->status);
    assert_eq(404, call_err('GET', '/v1/assets', $u, [], ['person' => (string) $theirP])->status);
    assert_eq(['Benim'], array_column(call('GET', '/v1/people', $u)['json']['people'], 'name'));
    // Aynı ad farklı kullanıcılarda ayrı kişidir; çakışma sayılmaz.
    pp_tag($u, $mine, 'Onun');
    assert_eq(2, (int) db_val('SELECT COUNT(*) FROM people WHERE name = ?', ['Onun']));
    assert_eq(1, (int) db_val('SELECT COUNT(*) FROM people WHERE id = ? AND name = ?', [$theirP, 'Onun']));
    assert_eq(401, call_err('GET', '/v1/people', null)->status);
    // Paylaşılan albüm izleyicisi asset ayrıntısında adları görür (eski davranış).
    assert_eq(['Onun'], call('GET', "/v1/assets/$theirs", $u)['json']['asset']['people']);
});

pp_test('test_people_face_thumb_crop', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    assert_eq(404, call_err('GET', '/v1/faces/123/thumb', $u)->status);
    $f = pp_face($u, $a, [0.4, 0.4, 0.6, 0.6]);
    assert_eq(404, call_err('GET', "/v1/faces/$f/thumb", $u)->status, 'önizleme yoksa 404');
    // 400×300 önizleme: ortası kırmızı (yüz), gerisi mavi.
    $img = imagecreatetruecolor(400, 300);
    imagefilledrectangle($img, 0, 0, 399, 299, imagecolorallocate($img, 0, 0, 255));
    imagefilledrectangle($img, 160, 120, 240, 180, imagecolorallocate($img, 255, 0, 0));
    $prev = derived_path($a, 'preview', 'jpg');
    @mkdir(dirname($prev), 0777, true);
    imagejpeg($img, $prev, 95);
    $r = call('GET', "/v1/faces/$f/thumb", $u);
    assert_eq(200, $r['status']);
    assert_eq('image/jpeg', $r['file']['mime']);
    assert_true(str_contains($r['file']['cache'], 'max-age'), 'önbellek başlığı');
    $path = $r['file']['path'];
    assert_eq(storage_root() . '/derived/face/' . substr((string) $f, 0, 2) . "/$f.jpg", $path);
    $info = getimagesize($path);
    assert_eq([256, 256, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
    $t = imagecreatefromjpeg($path);
    $c = imagecolorsforindex($t, imagecolorat($t, 128, 128));
    assert_true($c['red'] > 200 && $c['blue'] < 60, 'merkez yüz (kırmızı)');
    $c = imagecolorsforindex($t, imagecolorat($t, 3, 3));
    assert_true($c['blue'] > 200 && $c['red'] < 60, 'kenar dolgusu (mavi)');
    // Önbellekten aynı dosya.
    $mtime = filemtime($path);
    assert_eq($path, call('GET', "/v1/faces/$f/thumb", $u)['file']['path']);
    assert_eq($mtime, filemtime($path));
    // Kenardaki yüz: kare görüntü içinde kalır.
    $edge = pp_face($u, $a, [0.0, 0.0, 0.1, 0.1]);
    $info = getimagesize(call('GET', "/v1/faces/$edge/thumb", $u)['file']['path']);
    assert_eq([256, 256], [$info[0], $info[1]]);
});

pp_test('test_people_status_proxy', function () {
    $u = api_user();
    assert_eq(['available' => false], call('GET', '/v1/people/status', $u)['json']);
    $GLOBALS['__ml_reply']['/faces/status'] = ['scanned' => 1240, 'total' => 6500, 'faces' => 3000, 'people' => 40, 'ready' => false];
    assert_eq(['available' => true, 'scanned' => 1240, 'total' => 6500, 'faces' => 3000, 'people' => 40, 'ready' => false], call('GET', '/v1/people/status', $u)['json']);
    $st = array_values(array_filter($GLOBALS['__ml_calls'], fn($c) => $c[1] === '/faces/status'));
    assert_eq(['GET', (int) $u['id']], [$st[0][0], $st[0][2]['user_id']]);
});

pp_test('test_people_migrates_asset_people', function () {
    $u = api_user();
    $v = api_user('veli@example.com');
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2020-01-02 00:00:00', 2);
    $c = api_asset($v, '2020-01-03 00:00:00', 3);
    foreach ([[$a, 'Ayşe'], [$b, 'Ayşe'], [$b, 'Mehmet'], [$c, 'Ayşe']] as [$id, $n]) {
        db_exec('INSERT INTO asset_people (asset_id, name) VALUES (?, ?)', [$id, $n]);
    }
    for ($i = 0; $i < 2; $i++) { // tekrar çalıştırılabilir
        site_setting_set('schema_version', '5');
        site_setting_cache_clear();
        assert_eq(SCHEMA_VERSION, migrations_run());
    }
    assert_eq(6, SCHEMA_VERSION);
    assert_eq(['Ayşe', 'Mehmet'], array_column(db_all('SELECT name FROM people WHERE user_id = ? ORDER BY name', [(int) $u['id']]), 'name'));
    assert_eq(1, (int) db_val('SELECT COUNT(*) FROM people WHERE user_id = ?', [(int) $v['id']]));
    assert_eq(4, (int) db_val("SELECT COUNT(*) FROM asset_person WHERE source = 'takeout'"));
    $ayse = pp_pid($u, 'Ayşe');
    assert_eq(2, call('GET', "/v1/people/$ayse", $u)['json']['person']['count']);
    assert_eq(['Ayşe', 'Mehmet'], call('GET', "/v1/assets/$b", $u)['json']['asset']['people']);
});

pp_test('test_people_purge_cleanup', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2020-01-02 00:00:00', 2);
    $ali = pp_tag($u, $a, 'Ali')['people'][0]['id'];
    $cl = pp_cluster($u);
    $cl2 = pp_cluster($u);
    $f = pp_face($u, $a, [0.1, 0.1, 0.3, 0.3], $cl);
    pp_face($u, $a, [0.5, 0.1, 0.7, 0.3], $cl2);
    pp_face($u, $b, [0.5, 0.1, 0.7, 0.3], $cl2);
    db_exec('INSERT INTO face_scans (asset_id, user_id, model, faces, scanned_at) VALUES (?, ?, ?, 2, ?)', [$a, (int) $u['id'], 'buffalo_l', now_utc()]);
    db_exec('UPDATE people SET cover_face_id = ? WHERE id = ?', [$f, $ali]);
    $thumb = storage_root() . '/derived/face/' . substr((string) $f, 0, 2) . "/$f.jpg";
    @mkdir(dirname($thumb), 0777, true);
    file_put_contents($thumb, 'x');
    asset_trash((int) $u['id'], [$a]);
    assert_eq(1, asset_purge((int) $u['id'], [$a]));
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM faces WHERE asset_id = ?', [$a]));
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM face_scans WHERE asset_id = ?', [$a]));
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM asset_person WHERE asset_id = ?', [$a]));
    assert_eq(null, db_row('SELECT id FROM people WHERE id = ?', [$cl]), 'boş adsız küme silinir');
    assert_true(db_row('SELECT id FROM people WHERE id = ?', [$cl2]) !== null, 'başka yüzü olan küme kalır');
    $p = db_row('SELECT name, cover_face_id FROM people WHERE id = ?', [$ali]);
    assert_eq('Ali', $p['name'], 'adlı kişi kalır');
    assert_eq(null, $p['cover_face_id']);
    assert_true(!is_file($thumb), 'yüz kırpması silinir');
});
