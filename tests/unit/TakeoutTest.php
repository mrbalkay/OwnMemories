<?php
declare(strict_types=1);

/** Klasördeki sidecar adlarından indeks kurar ve eşleştirir. */
function tk_match(string $media, array $sidecars): ?string
{
    return takeout_match_sidecar($media, takeout_sidecar_index($sidecars));
}

test('test_match_simple_json', function () {
    assert_eq('IMG_1234.JPG.json', tk_match('IMG_1234.JPG', ['IMG_1234.JPG.json', 'IMG_123.JPG.json']));
    assert_eq('IMG_123.JPG.json', tk_match('IMG_123.JPG', ['IMG_1234.JPG.json', 'IMG_123.JPG.json']));
    assert_eq(null, tk_match('IMG_9.JPG', ['IMG_1234.JPG.json']));
});

test('test_match_old_style_without_extension', function () {
    assert_eq('IMG_5.json', tk_match('IMG_5.jpg', ['IMG_5.json']));
    assert_eq(null, tk_match('IMG_50.jpg', ['IMG_5.json']), 'IMG_5 öneki IMG_50 için kullanılmaz');
});

test('test_match_supplemental_and_truncations', function () {
    foreach ([
        'IMG_0001.HEIC.supplemental-metadata.json',
        'IMG_0001.HEIC.supplemental-metadat.json',
        'IMG_0001.HEIC.supplemental-meta.json',
        'IMG_0001.HEIC.supplemental-me.json',
        'IMG_0001.HEIC.supplemen.json',
        'IMG_0001.HEIC.suppl.json',
        'IMG_0001.HEIC.s.json',
    ] as $sc) {
        assert_eq($sc, tk_match('IMG_0001.HEIC', [$sc, 'IMG_0002.HEIC.supplemental-metadata.json']), $sc);
    }
    // 51 karakter sınırına göre kesilmiş uzun ad
    $media = 'Screenshot_20200101-101010_WhatsApp.jpg';            // 39
    $sc = substr($media . '.supplemental-metadata', 0, 46) . '.json'; // 51
    assert_eq($sc, tk_match($media, [$sc]));
});

test('test_match_numbered_duplicate', function () {
    $sc = ['IMG_7.jpg.json', 'IMG_7.jpg(1).json', 'IMG_8.jpg.supplemental-metadata(2).json'];
    assert_eq('IMG_7.jpg(1).json', tk_match('IMG_7(1).jpg', $sc));
    assert_eq('IMG_7.jpg.json', tk_match('IMG_7.jpg', $sc));
    assert_eq('IMG_8.jpg.supplemental-metadata(2).json', tk_match('IMG_8(2).jpg', $sc));
    assert_eq('IMG_9.jpg.supplemental-me(1).json', tk_match('IMG_9(1).jpg', ['IMG_9.jpg.supplemental-me(1).json']));
});

test('test_match_long_name_truncated_46_47', function () {
    $long = 'Bir çok uzun dosya adı olan fotoğraf örneği burada yazıyor.jpg';
    $sc46 = mb_substr($long, 0, 46) . '.json';
    assert_eq($sc46, tk_match($long, [$sc46]));
    $sc47 = mb_substr($long, 0, 47) . '.json';
    assert_eq($sc47, tk_match($long, [$sc47]));
});

test('test_match_edited_uses_original', function () {
    $sc = ['IMG_1.jpg.json'];
    assert_eq('IMG_1.jpg.json', tk_match('IMG_1-edited.jpg', $sc));
    assert_eq('IMG_1.jpg.json', tk_match('IMG_1-düzenlendi.jpg', $sc));
    assert_eq('IMG_1.jpg.json', tk_match('IMG_1-bearbeitet.jpg', $sc));
});

test('test_match_live_video_uses_photo_sidecar', function () {
    assert_eq('IMG_3.HEIC.json', tk_match('IMG_3.MOV', ['IMG_3.HEIC.json']));
    assert_eq('IMG_4.JPG.supplemental-metadata.json', tk_match('IMG_4.MP4', ['IMG_4.JPG.supplemental-metadata.json']));
    assert_eq('IMG_5.MP4.json', tk_match('IMG_5.MP4', ['IMG_5.HEIC.json', 'IMG_5.MP4.json']), 'kendi sidecar\'ı önce');
});

test('test_match_unicode_names', function () {
    $nfc = Normalizer::normalize('Doğum günü – İzmir(1).jpg', Normalizer::FORM_C);
    $nfd = Normalizer::normalize('Doğum günü – İzmir.jpg(1).json', Normalizer::FORM_D);
    assert_eq($nfd, tk_match($nfc, [$nfd]));
    $nfdMedia = Normalizer::normalize('Çiçek.jpg', Normalizer::FORM_D);
    assert_eq('Çiçek.jpg.json', tk_match($nfdMedia, ['Çiçek.jpg.json']));
});

test('test_match_case_insensitive_fallback', function () {
    assert_eq('img_1.jpg.json', tk_match('IMG_1.JPG', ['img_1.jpg.json']));
});

test('test_parse_full_sidecar', function () {
    $p = takeout_parse_sidecar((string) file_get_contents(fixture('takeout/sidecar_full.json')));
    assert_eq(1561982645, $p['taken_ts']);
    assert_eq(38.4192, $p['lat']);
    assert_eq(27.1287, $p['lng']);
    assert_eq(12.0, $p['alt']);
    assert_eq("Kordon'da gün batımı", $p['description']);
    assert_eq(true, $p['favorited']);
    assert_eq(false, $p['archived']);
    assert_eq(false, $p['trashed']);
    assert_eq(['Ayşe', 'Mehmet'], $p['people']);
    assert_eq(false, $p['from_shared']);
    assert_eq('IMG_20190701_150405.jpg', $p['title']);
});

test('test_parse_zero_geo_is_null', function () {
    $p = takeout_parse_sidecar((string) file_get_contents(fixture('takeout/sidecar_zero_geo.json')));
    assert_eq(41.01, $p['lat'], 'geoData 0/0 → geoDataExif');
    assert_eq(28.97, $p['lng']);
    $q = takeout_parse_sidecar((string) file_get_contents(fixture('takeout/sidecar_no_geo.json')));
    assert_eq(null, $q['lat']);
    assert_eq(null, $q['lng']);
    assert_eq(true, $q['trashed']);
});

test('test_parse_shared_flag', function () {
    assert_eq(true, takeout_parse_sidecar((string) file_get_contents(fixture('takeout/sidecar_zero_geo.json')))['from_shared']);
    assert_eq(true, takeout_parse_sidecar((string) file_get_contents(fixture('takeout/sidecar_no_geo.json')))['from_shared']);
    assert_eq(true, takeout_parse_sidecar((string) file_get_contents(fixture('takeout/sidecar_zero_geo.json')))['archived']);
});

test('test_parse_invalid_json_returns_empty_values', function () {
    $p = takeout_parse_sidecar('{bozuk');
    assert_eq(null, $p['taken_ts']);
    assert_eq([], $p['people']);
});

test('test_resolve_sidecar_beats_exif', function () {
    $sc = takeout_parse_sidecar((string) file_get_contents(fixture('takeout/sidecar_full.json')));
    $probe = media_probe_empty();
    $probe['taken_local'] = '2019-07-01 10:00:00'; // EXIF farklı (Google'da düzeltilmiş tarih)
    $probe['tz_offset'] = 180;
    $probe['lat'] = 1.0;
    $probe['lng'] = 2.0;
    $r = takeout_resolve($sc, $probe, 'IMG_20190701_150405.jpg', null);
    assert_eq('2019-07-01 12:04:05', $r['taken_at_utc']);
    assert_eq('takeout', $r['date_source']);
    assert_eq(180, $r['tz_offset']);
    assert_eq(38.4192, $r['lat']);
    assert_eq(true, $r['is_favorite']);
    assert_eq(false, $r['shared_from_others']);
    assert_eq("Kordon'da gün batımı", $r['description']);
});

test('test_resolve_tz_offset_from_exif_difference', function () {
    $probe = media_probe_empty();
    $probe['taken_local'] = '2019-07-01 15:00:00';
    $sc = ['taken_ts' => (int) strtotime('2019-07-01 12:00:00 UTC')] + takeout_parse_sidecar('{}');
    $r = takeout_resolve($sc, $probe, 'x.jpg', null);
    assert_eq(180, $r['tz_offset']);
    // Uçuk fark (yanlış EXIF saati) → İstanbul varsayılanı
    $probe['taken_local'] = '2001-01-01 00:00:00';
    assert_eq(180, takeout_resolve($sc, $probe, 'x.jpg', null)['tz_offset']);
});

test('test_resolve_location_falls_back_to_exif', function () {
    $sc = takeout_parse_sidecar((string) file_get_contents(fixture('takeout/sidecar_no_geo.json')));
    $probe = media_probe_empty();
    $probe['lat'] = 40.5;
    $probe['lng'] = 29.5;
    $r = takeout_resolve($sc, $probe, 'b.jpg', null);
    assert_eq(40.5, $r['lat']);
    assert_eq(true, $r['shared_from_others']);
});

test('test_resolve_date_fallbacks', function () {
    $empty = media_probe_empty();
    $r = takeout_resolve([], $empty, 'IMG_20180512_093011.jpg', null);
    assert_eq('2018-05-12 06:30:11', $r['taken_at_utc']);
    assert_eq('filename', $r['date_source']);
    $r = takeout_resolve([], $empty, 'VID-20180512-WA0001.mp4', null);
    assert_eq('2018-05-12 09:00:00', $r['taken_at_utc'], 'yalnız tarih → yerel 12:00');
    $mt = (int) strtotime('2015-03-04 05:06:07 UTC');
    $r = takeout_resolve([], $empty, 'photo.jpg', $mt);
    assert_eq('2015-03-04 05:06:07', $r['taken_at_utc']);
    assert_eq('file', $r['date_source']);
    $r = takeout_resolve([], $empty, 'IMG_99999999_999999.jpg', $mt);
    assert_eq('file', $r['date_source'], 'geçersiz tarih adı yok sayılır');
});

test('test_album_title_rules', function () {
    assert_eq(null, takeout_album_title('Photos from 2019', null));
    assert_eq(null, takeout_album_title('Photos from 2019', '{"title":"Photos from 2019"}'));
    assert_eq(null, takeout_album_title('2019 yılına ait fotoğraflar', null));
    assert_eq(null, takeout_album_title('Fotos von 2019', null));
    assert_eq(null, takeout_album_title('Çöp Kutusu', null));
    assert_eq(null, takeout_album_title('Trash', null));
    assert_eq(null, takeout_album_title('Archive', null));
    assert_eq(null, takeout_album_title('Arşiv', null));
    assert_eq(null, takeout_album_title('Failed Videos', null));
    assert_eq('İzmir Tatili', takeout_album_title('Izmir Tatili', '{"title":"İzmir Tatili","description":""}'));
    assert_eq('Düğün', takeout_album_title('Düğün', null), 'meta yoksa klasör adı');
    assert_eq('Düğün', takeout_album_title('Düğün', '{bozuk'), 'bozuk meta → klasör adı');
});

test('test_classify_paths', function () {
    $c = takeout_classify([
        'Takeout/Google Fotoğraflar/Photos from 2019/IMG_1.jpg',
        'Takeout/Google Fotoğraflar/Photos from 2019/IMG_1.jpg.json',
        'Takeout/Google Fotoğraflar/Tatil/metadata.json',
        'Takeout/Google Fotoğraflar/Tatil/IMG_1.jpg',
        'Takeout/Google Photos/Photos from 2020/VID_2.MP4',
        'Takeout/archive_browser.html',
        'Takeout/Google Fotoğraflar/print-subscriptions.json',
        'Kendi klasörüm/foto.png',
    ]);
    assert_eq(['Takeout/Google Fotoğraflar/Photos from 2019/IMG_1.jpg', 'Takeout/Google Fotoğraflar/Tatil/IMG_1.jpg', 'Takeout/Google Photos/Photos from 2020/VID_2.MP4', 'Kendi klasörüm/foto.png'], array_keys($c['media']));
    assert_eq('Photos from 2019', $c['media']['Takeout/Google Fotoğraflar/Photos from 2019/IMG_1.jpg']['bucket']);
    assert_eq('video', $c['media']['Takeout/Google Photos/Photos from 2020/VID_2.MP4']['kind']);
    assert_eq('Kendi klasörüm', $c['media']['Kendi klasörüm/foto.png']['bucket']);
    assert_eq(['Takeout/Google Fotoğraflar/Photos from 2019/IMG_1.jpg.json'], array_keys($c['sidecars']));
    assert_eq(['Takeout/Google Fotoğraflar/Tatil' => 'Takeout/Google Fotoğraflar/Tatil/metadata.json'], $c['album_meta']);
    assert_eq(2, count($c['other']));
});

test('test_live_pairs', function () {
    $p = takeout_live_pairs(['d/IMG_1.HEIC', 'd/IMG_1.MOV', 'd/IMG_2.jpg', 'd/IMG_2.mp4', 'd/IMG_3.jpg', 'e/IMG_1.MOV']);
    assert_eq(['d/IMG_1.HEIC' => 'd/IMG_1.MOV', 'd/IMG_2.jpg' => 'd/IMG_2.mp4'], $p);
});

test('test_edited_original', function () {
    $dir = ['d/IMG_1.jpg', 'd/IMG_1-edited.jpg', 'd/IMG_2-düzenlendi.jpg'];
    assert_eq('d/IMG_1.jpg', takeout_edited_original('d/IMG_1-edited.jpg', $dir));
    assert_eq(null, takeout_edited_original('d/IMG_2-düzenlendi.jpg', $dir), 'orijinal yok');
    assert_eq(null, takeout_edited_original('d/IMG_1.jpg', $dir));
});
