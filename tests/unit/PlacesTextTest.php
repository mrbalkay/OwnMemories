<?php
declare(strict_types=1);

test('test_tr_locative_vowel_harmony_and_hardening', function () {
    $cases = [
        'Bozcaada' => "Bozcaada'da", 'Göreme' => "Göreme'de", 'Paris' => "Paris'te", 'İzmir' => "İzmir'de",
        'Kapadokya' => "Kapadokya'da", 'Bangkok' => "Bangkok'ta", 'Roma' => "Roma'da", 'Kadıköy' => "Kadıköy'de",
        'Beşiktaş' => "Beşiktaş'ta", 'Ürgüp' => "Ürgüp'te", 'Ankara' => "Ankara'da", 'İstanbul' => "İstanbul'da",
        'Iğdır' => "Iğdır'da", 'Sydney' => "Sydney'de", 'Tokyo' => "Tokyo'da", 'Kyoto' => "Kyoto'da", 'Prag' => "Prag'da",
        'New York' => "New York'ta", 'Berlin' => "Berlin'de", 'Alaçatı' => "Alaçatı'da", 'Ayvalık' => "Ayvalık'ta",
        'Muş' => "Muş'ta", 'Bakü' => "Bakü'de", 'Moda' => "Moda'da", 'Sivas' => "Sivas'ta", 'Kayseri' => "Kayseri'de",
        'Halep' => "Halep'te", 'Bitlis' => "Bitlis'te", 'Van' => "Van'da", 'Erzurum' => "Erzurum'da", 'Çeşme' => "Çeşme'de",
        'Londra' => "Londra'da", 'Dubai' => "Dubai'de", 'Madrid' => "Madrid'de", 'Lizbon' => "Lizbon'da",
    ];
    foreach ($cases as $in => $want) {
        assert_eq($want, tr_locative($in), $in);
    }
});

test('test_tr_locative_acronyms_and_exceptions', function () {
    assert_eq("ABD'de", tr_locative('ABD'));
    assert_eq("KKTC'de", tr_locative('KKTC'));
    assert_eq("Nice'te", tr_locative('Nice'));
    assert_eq("Bordeaux'da", tr_locative('Bordeaux'));
    assert_eq('', tr_locative(''));
});

test('test_places_fold_turkish_and_diacritics', function () {
    assert_eq('kadikoy', places_fold('Kadıköy'));
    assert_eq('kadikoy', places_fold('KADIKÖY'));
    assert_eq('izmir', places_fold('İZMİR'));
    assert_eq('isparta', places_fold('ISPARTA'));
    assert_eq('ile de france', places_fold('Île-de-France'));
    assert_eq('sanliurfa', places_fold('Şanlıurfa'));
});

test('test_geo_cell_neighbours', function () {
    assert_eq(geo_cell(40.98, 29.02), geo_cell(40.981, 29.029));
    assert_true(geo_cell(40.98, 29.02) !== geo_cell(41.08, 29.02));
    assert_eq(geo_cell(0, 179.99) - 3599, geo_cell(0, -180.0), 'tarih çizgisi');
    assert_true(abs(geo_distance_km(41.0138, 28.9497, 38.4237, 27.1428) - 329) < 5, 'İstanbul–İzmir ~329 km');
});

test('test_place_json_turkish_hierarchy_labels', function () {
    $row = fn(array $o) => $o + ['place_id' => 1, 'city_id' => 2, 'district' => null, 'admin1' => null, 'country' => 'Türkiye', 'country_code' => 'TR', 'region' => null];
    // İlçe biliniyorsa yakındaki kasaba ("şehir") etikete girmez: başka ilçede olabilir.
    $j = place_json($row(['name' => 'Çavuşin', 'city' => 'Göreme', 'district' => 'Avanos', 'admin1' => 'Nevşehir']));
    assert_eq('Çavuşin, Avanos, Nevşehir, Türkiye', $j['label']);
    assert_eq('Çavuşin, Avanos', $j['short']);
    // Boşluk farkı yinelenme sayılmaz
    $j = place_json($row(['name' => 'Kız Kalesi', 'city' => 'Kızkalesi', 'district' => 'Erdemli', 'admin1' => 'Mersin']));
    assert_eq('Kız Kalesi, Erdemli, Mersin, Türkiye', $j['label']);
    // İl merkezi noktası: ilçe önce
    $j = place_json($row(['name' => 'Bursa', 'city' => 'Bursa', 'district' => 'Osmangazi', 'admin1' => 'Bursa']));
    assert_eq('Osmangazi, Bursa, Türkiye', $j['label']);
    assert_eq('Osmangazi, Bursa', $j['short']);
    $j = place_json($row(['name' => 'Moda', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'admin1' => 'İstanbul']));
    assert_eq('Moda, Kadıköy, İstanbul, Türkiye', $j['label']);
    assert_eq('Moda, Kadıköy', $j['short']);
    // Yurt dışı: şehir korunur
    $j = place_json($row(['name' => 'Libération', 'city' => 'Nice', 'admin1' => "Provence-Alpes-Côte d'Azur", 'country' => 'Fransa', 'country_code' => 'FR']));
    assert_eq("Libération, Nice, Provence-Alpes-Côte d'Azur, Fransa", $j['label']);
    assert_eq('Libération, Nice', $j['short']);
    assert_eq(null, place_json(null));
});

test('test_trip_title_day_counts', function () {
    $trip = fn(string $s, string $e, int $days, string $name = 'Roma') => ['place' => ['name' => $name], 'days' => $days, 'start' => $s, 'end' => $e];
    assert_eq("Roma'da bir gün", places_trip_title($trip('2024-03-05', '2024-03-05', 1)));      // Salı
    assert_eq("Roma'da 4 gün", places_trip_title($trip('2024-03-04', '2024-03-07', 4)));        // Pzt–Per
    assert_eq("Roma'da 5 gün", places_trip_title($trip('2024-03-04', '2024-03-08', 5)));        // 5 gün "bir hafta" değil
    assert_eq("Roma'da bir hafta", places_trip_title($trip('2024-03-04', '2024-03-10', 7)));
    assert_eq("Bodrum'da bir hafta sonu", places_trip_title($trip('2024-03-09', '2024-03-10', 2, 'Bodrum')));
    assert_eq('Kapadokya, Mart 2024', places_trip_title($trip('2024-03-01', '2024-03-12', 12, 'Kapadokya')));
});
