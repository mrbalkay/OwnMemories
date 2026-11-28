<?php
/**
 * Fotoğraftaki yazı (OCR): asset_text tablosu, GET /v1/assets/{id}/text, aramaya katılım (GET /v1/search),
 * kalıcı silme, yönetim paneli ilerlemesi. ML hizmeti ml_client_override ile taklit edilir (SearchTest.php).
 */
declare(strict_types=1);

/** Dizinleyicinin yazacağı satırı test için yazar. */
function ocr_put(array $u, string $assetId, string $text, string $engine = 'ppocr-v6/1'): void
{
    ocr_store($assetId, (int) $u['id'], $text, $text === '' ? null : 'tr', $engine);
}

/** search_test() gibi (SearchTest.php bu dosyadan sonra yüklenir; yardımcılar çalışma anında hazır). */
function ocr_test(string $name, callable $fn): void
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

dbtest('test_ocr_schema_has_asset_text', function () {
    $cols = array_column(db_all('SHOW COLUMNS FROM asset_text'), 'Field');
    foreach (['asset_id', 'user_id', 'text', 'folded', 'lang', 'words', 'engine', 'computed_at'] as $c) {
        assert_true(in_array($c, $cols, true), "sütun $c");
    }
    assert_true(SCHEMA_VERSION >= 11);
    assert_true(isset(migration_files()[11]), '011_ocr.sql');
});

dbtest('test_ocr_store_folds_and_counts_words', function () {
    $u = api_user();
    $a = api_asset($u, '2024-01-01 00:00:00', 1);
    ocr_put($u, $a, "ELEKTRİK FATURASI\nÖdenecek tutar 812,40 TL");
    $r = db_row('SELECT * FROM asset_text WHERE asset_id = ?', [$a]);
    assert_eq(' elektrik faturasi odenecek tutar 812 40 tl ', $r['folded']);
    assert_eq(7, (int) $r['words']); // katlanmış sözcükler (812,40 → 812 40)
    assert_eq('tr', $r['lang']);
    // yeniden yazma günceller
    ocr_put($u, $a, '');
    $r = db_row('SELECT * FROM asset_text WHERE asset_id = ?', [$a]);
    assert_eq(0, (int) $r['words']);
    assert_eq('', $r['folded']);
});

dbtest('test_asset_text_endpoint', function () {
    $u = api_user();
    $other = api_user('veli@example.com');
    $a = api_asset($u, '2024-01-01 00:00:00', 1);
    $b = api_asset($u, '2024-01-02 00:00:00', 2);
    $c = api_asset($u, '2024-01-03 00:00:00', 3);
    ocr_put($u, $a, "NÖBETÇİ ECZANE\nKadıköy");
    ocr_put($u, $c, '');
    $r = call('GET', "/v1/assets/$a/text", $u)['json'];
    assert_eq('done', $r['status']);
    assert_eq("NÖBETÇİ ECZANE\nKadıköy", $r['text']);
    assert_eq(['NÖBETÇİ ECZANE', 'Kadıköy'], $r['lines']);
    assert_eq(3, $r['words']);
    assert_eq('tr', $r['lang']);
    assert_true(is_string($r['computed_at']));
    $r = call('GET', "/v1/assets/$b/text", $u)['json'];
    assert_eq('pending', $r['status']);
    assert_eq('', $r['text']);
    $r = call('GET', "/v1/assets/$c/text", $u)['json'];
    assert_eq('none', $r['status']);
    assert_eq([], $r['lines']);
    // başkasının fotoğrafı: 404
    $e = call_err('GET', "/v1/assets/$a/text", $other);
    assert_eq(404, $e->status);
});

ocr_test('test_search_ocr_word_match_ranks_first', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);   // akıllı aramada 1.
    $b = api_asset($u, '2021-01-01 00:00:00', 2);   // yazıda "fatura" (tam sözcük), akıllı aramada yok
    $c = api_asset($u, '2022-01-01 00:00:00', 3);   // yazıda "faturası" (sözcük başı)
    $d = api_asset($u, '2023-01-01 00:00:00', 4);   // akıllı aramada 2.
    ocr_put($u, $b, "MAĞAZA\nFATURA\nTOPLAM 120 TL");
    ocr_put($u, $c, 'Elektrik faturası');
    ocr_put($u, $d, 'Alakasız yazı');
    search_stub([$a, $d]);
    $r = call('GET', '/v1/search', $u, [], ['q' => 'fatura'])['json'];
    assert_eq('smart', $r['mode']);
    assert_eq([$b, $c, $a, $d], array_column($r['assets'], 'id'));
    $byId = array_column($r['assets'], null, 'id');
    assert_eq('fatura', $byId[$b]['text_match']['term']);
    assert_eq('FATURA', $byId[$b]['text_match']['snippet']);
    assert_eq([['t' => 'FATURA', 'm' => true]], $byId[$b]['text_match']['parts']);
    assert_eq('Elektrik faturası', $byId[$c]['text_match']['snippet']);
    assert_true(!isset($byId[$a]['text_match']), 'yazısız sonuçta text_match yok');
});

ocr_test('test_search_ocr_turkish_folding', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2021-01-01 00:00:00', 2);
    $c = api_asset($u, '2022-01-01 00:00:00', 3);
    ocr_put($u, $a, 'ISTANBUL GÜNES ECZANESİ');      // OCR "İ" yerine "I" okumuş
    ocr_put($u, $b, 'ÇİĞKÖFTE DÜRÜM 45 TL');
    ocr_put($u, $c, "Kadıköy'de buluşalım");
    search_stub([]);
    assert_eq([$a], search_ids($u, ['q' => 'İSTANBUL']));
    assert_eq([$a], search_ids($u, ['q' => 'istanbul eczane']));
    assert_eq([$b], search_ids($u, ['q' => 'çiğköfte']));
    assert_eq([$b], search_ids($u, ['q' => 'cigkofte']));
    assert_eq([$b], search_ids($u, ['q' => 'köfte']));      // sözcük içi (≥4 harf)
    assert_eq([$c], search_ids($u, ['q' => 'KADIKÖY']));
    assert_eq([], search_ids($u, ['q' => 'ankara']));
});

ocr_test('test_search_ocr_when_ml_down_and_with_filename', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2021-01-01 00:00:00', 2);
    db_exec('UPDATE assets SET original_filename = ? WHERE id = ?', ['pasaport-tarama.jpg', $b]);
    ocr_put($u, $a, "TÜRKİYE CUMHURİYETİ\nPASAPORT / PASSPORT");
    search_stub(null); // hizmet kapalı
    $r = call('GET', '/v1/search', $u, [], ['q' => 'pasaport'])['json'];
    assert_eq('text', $r['mode']);
    assert_eq([$a, $b], array_column($r['assets'], 'id'));
    assert_eq(2, $r['total']);
    assert_eq('PASAPORT / PASSPORT', $r['assets'][0]['text_match']['snippet']);
});

ocr_test('test_search_ocr_respects_visibility_and_filters', function () {
    $u = api_user();
    $v = api_user('veli@example.com');
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $t = api_asset($u, '2021-01-01 00:00:00', 2);
    $o = api_asset($v, '2022-01-01 00:00:00', 3);
    $old = api_asset($u, '2010-01-01 00:00:00', 4);
    foreach ([$a, $t, $old] as $id) {
        ocr_put($u, $id, 'MENÜ Mercimek Çorbası');
    }
    ocr_put($v, $o, 'MENÜ');
    asset_trash((int) $u['id'], [$t]);
    search_stub([]);
    assert_eq([$a, $old], search_ids($u, ['q' => 'menü']));
    assert_eq([$a], search_ids($u, ['q' => 'menü', 'from' => '2019-01-01T00:00:00Z']));
    assert_eq([$o], search_ids($v, ['q' => 'menu']));
});

ocr_test('test_search_ocr_pagination_and_date_sort', function () {
    $u = api_user();
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[$i] = api_asset($u, sprintf('202%d-01-01 00:00:00', $i), $i + 1);
        ocr_put($u, $ids[$i], $i % 2 ? 'eczane' : 'Nöbetçi eczanesi');
    }
    search_stub([]);
    $r = call('GET', '/v1/search', $u, [], ['q' => 'eczane', 'limit' => 2])['json'];
    // tam sözcük önce (yeni → eski), sonra sözcük başı
    assert_eq([$ids[3], $ids[1]], array_column($r['assets'], 'id'));
    assert_eq(2, $r['next_offset']);
    assert_eq(5, $r['total']);
    $r = call('GET', '/v1/search', $u, [], ['q' => 'eczane', 'limit' => 2, 'offset' => 2])['json'];
    assert_eq([$ids[4], $ids[2]], array_column($r['assets'], 'id'));
    $r = call('GET', '/v1/search', $u, [], ['q' => 'eczane', 'sort' => 'date'])['json'];
    assert_eq([$ids[4], $ids[3], $ids[2], $ids[1], $ids[0]], array_column($r['assets'], 'id'));
});

ocr_test('test_search_ocr_missing_table_is_harmless', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    db()->exec('DROP TABLE asset_text');
    try {
        search_stub([$a]);
        assert_eq([$a], search_ids($u, ['q' => 'fatura']));
        $r = call('GET', "/v1/assets/$a/text", $u)['json'];
        assert_eq('unavailable', $r['status']);
    } finally {
        schema_install(db());
    }
});

dbtest('test_purge_deletes_asset_text', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    ocr_put($u, $a, 'fatura');
    asset_trash((int) $u['id'], [$a]);
    asset_purge((int) $u['id'], [$a]);
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM asset_text WHERE asset_id = ?', [$a]));
});

ocr_test('test_ocr_admin_progress', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2020-01-02 00:00:00', 2);
    api_asset($u, '2020-01-03 00:00:00', 3);
    ocr_put($u, $a, 'fatura');
    ocr_put($u, $b, '');
    $p = ocr_progress();
    $row = array_values(array_filter($p['users'], fn($r) => $r['id'] === (int) $u['id']))[0];
    assert_eq(3, $row['total']);
    assert_eq(2, $row['scanned']);
    assert_eq(1, $row['with_text']);
    assert_eq(['total' => 3, 'scanned' => 2, 'with_text' => 1], array_intersect_key($p['all'], ['total' => 1, 'scanned' => 1, 'with_text' => 1]));
    search_stub([], ['indexed' => 3, 'total' => 3, 'pending' => 0, 'model' => 'm', 'ready' => true]);
    $r = call('GET', '/v1/search/status', $u)['json'];
    assert_eq(['scanned' => 2, 'total' => 3, 'with_text' => 1], $r['ocr']);
    search_stub(null, null); // hizmet yoksa yazı ilerlemesi de verilmez (yazıyı hizmet okur)
    assert_eq(['available' => false], call('GET', '/v1/search/status', $u)['json']);
});

ocr_test('test_search_ocr_fulltext_stopwords_and_fallback', function () {
    $u = api_user();
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    $b = api_asset($u, '2021-01-01 00:00:00', 2);
    $c = api_asset($u, '2022-01-01 00:00:00', 3);
    ocr_put($u, $a, 'THE BEATLES');
    ocr_put($u, $b, 'Çiğköfte dürüm · Ayran');
    ocr_put($u, $c, 'Dürümcü Ali');
    search_stub([]);
    assert_eq([$a], search_ids($u, ['q' => 'the beatles']));     // durak sözcüğü: LIKE yolu
    assert_eq([$b, $c], search_ids($u, ['q' => 'dürüm']));       // FULLTEXT: tam sözcük (dürüm) önce, sözcük başı (dürümcü) sonra
    assert_eq([$b], search_ids($u, ['q' => 'köfte']));           // sözcük içi: LIKE yolu
    assert_eq([$b], search_ids($u, ['q' => 'ayran']));
    // FULLTEXT dizini yoksa (eski kurulum) LIKE ile aynı sonuç
    db()->exec('ALTER TABLE asset_text DROP INDEX ft_at_folded');
    try {
        assert_eq([$b, $c], search_ids($u, ['q' => 'dürüm']));
        assert_eq([$b], search_ids($u, ['q' => 'çiğköfte']));
    } finally {
        db()->exec('ALTER TABLE asset_text ADD FULLTEXT KEY ft_at_folded (folded)');
    }
});

ocr_test('test_ocr_shared_album_member_sees_text_but_not_in_own_search', function () {
    $u = api_user();
    $v = api_user('veli@example.com');
    $w = api_user('ayse@example.com');
    $a = api_asset($u, '2020-01-01 00:00:00', 1);
    ocr_put($u, $a, 'PASAPORT');
    $album = album_create((int) $u['id'], 'Gezi', '');
    album_add_assets($album, (int) $u['id'], [$a]);
    album_share($album, (int) $u['id'], 'veli@example.com', 'viewer');
    // paylaşılan albüm üyesi fotoğrafı görebildiği için yazısını da görür
    assert_eq('done', call('GET', "/v1/assets/$a/text", $v)['json']['status']);
    // üye olmayan göremez; kimliksiz istek reddedilir
    assert_eq(404, call_err('GET', "/v1/assets/$a/text", $w)->status);
    assert_eq(401, call_err('GET', "/v1/assets/$a/text", null)->status);
    // başkasının yazısı kendi aramasına karışmaz
    search_stub([]);
    assert_eq([], search_ids($v, ['q' => 'pasaport']));
    assert_eq([$a], search_ids($u, ['q' => 'pasaport']));
    // çöpe atılınca üye yazıyı da göremez
    asset_trash((int) $u['id'], [$a]);
    assert_eq(404, call_err('GET', "/v1/assets/$a/text", $v)->status);
});
