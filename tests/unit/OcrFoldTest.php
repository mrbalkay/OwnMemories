<?php
/**
 * OCR arama katlaması: PHP ocr_fold() ile Python ocr.fold() aynı sonucu vermeli
 * (ortak örnekler: tests/fixtures/ocr/fold.json; Python tarafı ml/tests/test_ocr.py).
 */
declare(strict_types=1);

test('test_ocr_fold_matches_fixture', function () {
    $cases = json_decode((string) file_get_contents(fixture('ocr/fold.json')), true);
    assert_true(count($cases) > 10);
    foreach ($cases as $in => $want) {
        assert_eq($want, ocr_fold((string) $in), "katlama: $in");
    }
});

test('test_ocr_query_tokens', function () {
    assert_eq(['fatura'], ocr_query_tokens('  Fatura '));
    assert_eq(['istanbul', 'kadikoy'], ocr_query_tokens('İSTANBUL, Kadıköy'));
    assert_eq(['su', 'faturasi'], ocr_query_tokens('su faturası'));
    assert_eq([], ocr_query_tokens('!!! ?'));
    assert_eq(['x'], ocr_query_tokens('x'));
});

test('test_ocr_snippet_parts_highlight_original_words', function () {
    $text = "BOĞAZİÇİ ELEKTRİK\nELEKTRİK FATURASI\nÖDENECEK TUTAR 812,40 TL";
    $s = ocr_snippet($text, ['fatura']);
    assert_eq('ELEKTRİK FATURASI', $s['snippet']);
    assert_eq([['t' => 'ELEKTRİK ', 'm' => false], ['t' => 'FATURASI', 'm' => true]], $s['parts']);
    $s = ocr_snippet($text, ['odenecek', 'tutar']);
    assert_eq('ÖDENECEK TUTAR 812,40 TL', $s['snippet']);
    assert_eq([['t' => 'ÖDENECEK', 'm' => true], ['t' => ' ', 'm' => false], ['t' => 'TUTAR', 'm' => true], ['t' => ' 812,40 TL', 'm' => false]], $s['parts']);
    // eşleşme yoksa ilk satır, vurgusuz
    $s = ocr_snippet($text, ['yok']);
    assert_eq('BOĞAZİÇİ ELEKTRİK', $s['snippet']);
    // uzun satır kısaltılır, eşleşme görünür kalır
    $long = str_repeat('kelime ', 40) . 'PASAPORT ' . str_repeat('son ', 30);
    $s = ocr_snippet($long, ['pasaport']);
    assert_true(mb_strlen($s['snippet']) <= 90, 'kısaltma');
    assert_true(str_contains($s['snippet'], 'PASAPORT'));
    assert_eq(1, count(array_filter($s['parts'], fn($p) => $p['m'])));
});

test('test_ocr_match_kind', function () {
    $f = ' ' . ocr_fold("ELEKTRİK FATURASI\nNÖBETÇİ ECZANE\nÇİĞKÖFTE DÜRÜM") . ' ';
    assert_eq(3, ocr_match_score($f, ['eczane']));          // tam sözcük
    assert_eq(2, ocr_match_score($f, ['fatura']));          // sözcük başı (Türkçe ek)
    assert_eq(1, ocr_match_score($f, ['kofte']));           // sözcük içi (≥4 harf)
    assert_eq(0, ocr_match_score($f, ['ura']));             // kısa sözcük içi: yok
    assert_eq(0, ocr_match_score($f, ['eczane', 'pasaport'])); // tüm sözcükler gerekli
    assert_eq(2, ocr_match_score($f, ['nobetci', 'ecz']));  // en zayıf sözcük belirler
    assert_eq(3, ocr_match_score($f, ['nobetci', 'eczane']));
});
