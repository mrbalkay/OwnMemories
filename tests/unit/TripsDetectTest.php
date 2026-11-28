<?php
declare(strict_types=1);

/*
 * Geziler ve etkinlikler: saf algılama (trips_detect) — veritabanı gerektirmez. Satırlar elle kurulur:
 * evden uzaklık GPS'ten hesaplanır, yer adları (şehir/bölge/ülke) asset_places'teki gibi verilir.
 */

/** Yer tanımları: [lat, lng, city_id, city, region, country, cc, district] */
function td_places(): array
{
    return [
        'kadikoy' => [40.9900, 29.0270, 745044, 'İstanbul', null, 'Türkiye', 'TR', 'Kadıköy'],
        'moda' => [40.9830, 29.0260, 745044, 'İstanbul', null, 'Türkiye', 'TR', 'Kadıköy'],
        'besiktas' => [41.0430, 29.0050, 745044, 'İstanbul', null, 'Türkiye', 'TR', 'Beşiktaş'],
        'sile' => [41.1750, 29.6120, 740264, 'Şile', null, 'Türkiye', 'TR', 'Şile'],
        'bozcaada' => [39.8340, 26.0700, 320700, 'Bozcaada', null, 'Türkiye', 'TR', 'Bozcaada'],
        'goreme' => [38.6431, 34.8289, 313947, 'Göreme', 'Kapadokya', 'Türkiye', 'TR', 'Nevşehir'],
        'urgup' => [38.6320, 34.9110, 298326, 'Ürgüp', 'Kapadokya', 'Türkiye', 'TR', 'Ürgüp'],
        'ankara' => [39.9200, 32.8540, 323786, 'Ankara', null, 'Türkiye', 'TR', 'Çankaya'],
        'roma' => [41.8986, 12.4769, 3169070, 'Roma', null, 'İtalya', 'IT', null],
        'floransa' => [43.7696, 11.2558, 3176959, 'Floransa', null, 'İtalya', 'IT', null],
        'venedik' => [45.4371, 12.3326, 3164603, 'Venedik', null, 'İtalya', 'IT', null],
        'bangkok' => [13.7500, 100.4913, 1609350, 'Bangkok', null, 'Tayland', 'TH', null],
        'ayutthaya' => [14.3532, 100.5689, 1607532, 'Ayutthaya', null, 'Tayland', 'TH', null],
        'chiangmai' => [18.7904, 98.9847, 1153671, 'Chiang Mai', null, 'Tayland', 'TH', null],
        'krabi' => [8.0863, 98.9063, 1152633, 'Krabi', null, 'Tayland', 'TH', null],
        'phuket' => [7.8804, 98.3923, 1151254, 'Phuket', null, 'Tayland', 'TH', null],
        'paris' => [48.8606, 2.3376, 2988507, 'Paris', null, 'Fransa', 'FR', null],
    ];
}

/** Bir satır: yerel saat "Y-m-d H:i" + dilim (dk). */
function td_row(string $place, string $local, int $tz = 180, array $extra = []): array
{
    static $n = 0;
    $n++;
    $ts = (int) strtotime($local . ':00 UTC') - $tz * 60;
    $p = $place === '' ? null : td_places()[$place];
    // Aynı yerde çekilen karelerde küçük GPS oynaması
    $j = ($n % 7) * 0.0007;
    return $extra + [
        'id' => sprintf('00000000-0000-4000-8000-%012d', $n),
        'ts' => $ts,
        'day' => substr($local, 0, 10),
        'lat' => $p ? $p[0] + $j : null,
        'lng' => $p ? $p[1] - $j : null,
        'city_id' => $p ? $p[2] : null,
        'city' => $p ? $p[3] : null,
        'region' => $p ? $p[4] : null,
        'country' => $p ? $p[5] : null,
        'cc' => $p ? $p[6] : null,
        'district' => $p ? $p[7] : null,
        'fav' => false,
        'type' => 'photo',
        'faces' => 0,
        'sharp' => null,
        'shot' => false,
        'dsrc' => 'exif',
    ];
}

/** Evde (İstanbul) düzenli çekimler: $from–$to arası her ayın birkaç gününde 2–3 kare. */
function td_home(string $fromYm, string $toYm, string $place = 'kadikoy'): array
{
    $rows = [];
    $t = strtotime($fromYm . '-01 UTC');
    $end = strtotime($toYm . '-01 UTC');
    $alt = ['kadikoy', 'moda', 'besiktas'];
    while ($t <= $end) {
        $ym = gmdate('Y-m', $t);
        foreach ([3, 11, 20, 27] as $i => $d) {
            $p = $place === 'kadikoy' ? $alt[$i % 3] : $place;
            $rows[] = td_row($p, sprintf('%s-%02d 10:%02d', $ym, $d, $i * 7));
            $rows[] = td_row($p, sprintf('%s-%02d 18:%02d', $ym, $d, $i * 5));
        }
        $t = strtotime('+1 month', $t);
    }
    return $rows;
}

/** Günde $perDay kare: [gün => yer]. */
function td_days(array $plan, int $perDay = 4, int $tz = 180): array
{
    $rows = [];
    foreach ($plan as $day => $place) {
        for ($i = 0; $i < $perDay; $i++) {
            $rows[] = td_row($place, sprintf('%s %02d:%02d', $day, 9 + $i * 3, ($i * 13) % 60), $tz);
        }
    }
    return $rows;
}

function td_detect(array $rows): array
{
    usort($rows, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return trips_detect($rows);
}

function td_kind(array $list, string $kind): array
{
    return array_values(array_filter($list, fn($c) => $c['kind'] === $kind));
}

test('test_trips_range_text_turkish', function () {
    locale('tr');
    assert_eq('12–19 Temmuz 2024', trips_range_text('2024-07-12', '2024-07-19'));
    assert_eq('12 Temmuz 2024', trips_range_text('2024-07-12', '2024-07-12'));
    assert_eq('28 Haziran – 3 Temmuz 2024', trips_range_text('2024-06-28', '2024-07-03'));
    assert_eq('30 Aralık 2023 – 2 Ocak 2024', trips_range_text('2023-12-30', '2024-01-02'));
    assert_eq('Mart 2019', trips_month_text('2019-03-02', '2019-03-20'));
    assert_eq('Kasım–Aralık 2025', trips_month_text('2025-11-25', '2025-12-04'));
    assert_eq('Aralık 2025 – Ocak 2026', trips_month_text('2025-12-25', '2026-01-04'));
    assert_eq('Roma, Floransa ve Venedik', trips_join(['Roma', 'Floransa', 'Venedik']));
    assert_eq('Roma ve Floransa', trips_join(['Roma', 'Floransa']));
});

test('test_trips_detect_single_place_trip', function () {
    locale('tr');
    $rows = array_merge(td_home('2024-01', '2024-12'), td_days([
        '2024-07-12' => 'bozcaada', '2024-07-13' => 'bozcaada', '2024-07-14' => 'bozcaada', '2024-07-15' => 'bozcaada',
    ]));
    $trips = td_kind(td_detect($rows), 'trip');
    assert_eq(1, count($trips), 'tek gezi');
    $t = $trips[0];
    assert_eq('Bozcaada gezisi, 12–15 Temmuz 2024', $t['title']);
    assert_eq('Bozcaada gezisi', $t['short_title']);
    assert_eq('2024-07-12', $t['start_day']);
    assert_eq('2024-07-15', $t['end_day']);
    assert_eq(4, $t['days']);
    assert_eq(16, count($t['ids']));
    assert_eq(['Bozcaada'], array_column($t['places'], 'name'));
    assert_true($t['distance_km'] > 200, 'evden uzaklık: ' . $t['distance_km']);
    assert_eq([], td_kind(td_detect($rows), 'event'), 'sıradan günler etkinlik değil');
});

test('test_trips_detect_multi_city_foreign_and_gap_day', function () {
    locale('tr');
    $rows = array_merge(td_home('2024-01', '2024-12'), td_days([
        '2024-05-04' => 'roma', '2024-05-05' => 'roma', '2024-05-06' => 'roma',
        // 7 Mayıs: tren günü, fotoğraf yok (1 günlük boşluk gezi içinde kalır)
        '2024-05-08' => 'floransa', '2024-05-09' => 'venedik', '2024-05-10' => 'venedik',
    ], 4, 120));
    // Konumsuz bir kare gezinin ortasında: geziye dahil
    $rows[] = td_row('', '2024-05-08 21:00', 120);
    $trips = td_kind(td_detect($rows), 'trip');
    assert_eq(1, count($trips));
    $t = $trips[0];
    assert_eq('İtalya: Roma, Floransa ve Venedik', $t['title']);
    assert_eq(['Roma', 'Floransa', 'Venedik'], array_column($t['places'], 'name'));
    assert_eq(7, $t['days']);
    assert_eq(25, count($t['ids']), 'konumsuz kare dahil');
    assert_eq([['cc' => 'IT', 'name' => 'İtalya']], array_map(fn($c) => ['cc' => $c['cc'], 'name' => $c['name']], $t['countries']));
});

test('test_trips_detect_weekend_region_and_country_level', function () {
    locale('tr');
    $rows = array_merge(td_home('2025-01', '2025-12'),
        td_days(['2025-03-08' => 'goreme', '2025-03-09' => 'urgup']),
        td_days(['2025-11-18' => 'bangkok', '2025-11-19' => 'bangkok', '2025-11-20' => 'ayutthaya', '2025-11-21' => 'chiangmai',
            '2025-11-22' => 'chiangmai', '2025-11-24' => 'krabi', '2025-11-25' => 'phuket', '2025-11-26' => 'phuket'], 3, 420));
    $trips = td_kind(td_detect($rows), 'trip');
    assert_eq(2, count($trips));
    assert_eq("Kapadokya'da hafta sonu, 8–9 Mart 2025", $trips[0]['title']);
    assert_eq(['Kapadokya'], array_column($trips[0]['places'], 'name'), 'bölge şehirlerden önce');
    assert_eq('Tayland, Kasım 2025', $trips[1]['title']);
    assert_eq('Tayland gezisi', $trips[1]['short_title']);
    assert_eq(5, count($trips[1]['places']));
});

test('test_trips_detect_two_day_gap_splits_and_home_day_ends_trip', function () {
    locale('tr');
    $rows = array_merge(td_home('2024-01', '2024-12'),
        td_days(['2024-09-02' => 'bozcaada', '2024-09-03' => 'bozcaada', '2024-09-06' => 'bozcaada', '2024-09-07' => 'bozcaada']));
    $trips = td_kind(td_detect($rows), 'trip');
    assert_eq(2, count($trips), 'iki günlük boşluk iki ayrı gezi');
    $rows = array_merge(td_home('2024-01', '2024-12'),
        td_days(['2024-09-02' => 'bozcaada', '2024-09-03' => 'bozcaada', '2024-09-04' => 'kadikoy', '2024-09-05' => 'bozcaada', '2024-09-06' => 'bozcaada']));
    assert_eq(2, count(td_kind(td_detect($rows), 'trip')), 'arada evde geçen gün geziyi böler');
});

test('test_trips_detect_home_moves_between_periods', function () {
    locale('tr');
    // 2019-2021 Ankara'da yaşıyor, 2022'den beri İstanbul'da
    $rows = array_merge(td_home('2019-01', '2021-12', 'ankara'), td_home('2022-01', '2024-12'),
        td_days(['2020-06-12' => 'kadikoy', '2020-06-13' => 'besiktas', '2020-06-14' => 'moda']),
        td_days(['2023-10-07' => 'ankara', '2023-10-08' => 'ankara']));
    $trips = td_kind(td_detect($rows), 'trip');
    $titles = array_column($trips, 'title');
    assert_eq(["İstanbul'da hafta sonu, 12–14 Haziran 2020", "Ankara'da hafta sonu, 7–8 Ekim 2023"], $titles);
});

test('test_trips_detect_short_day_trip_is_event_not_trip', function () {
    locale('tr');
    // Şile, Kadıköy'e ~52 km: günübirlik gezi etkinlik olur, gezi değil
    $rows = array_merge(td_home('2024-01', '2024-12'), td_days(['2024-08-03' => 'sile'], 6));
    $all = td_detect($rows);
    assert_eq([], td_kind($all, 'trip'));
    $ev = td_kind($all, 'event');
    assert_eq(1, count($ev));
    assert_eq('Şile, 3 Ağustos 2024', $ev[0]['title']);
    assert_eq('day_trip', $ev[0]['label']);
    assert_eq(6, count($ev[0]['ids']));
});

test('test_trips_detect_dense_burst_at_home_is_event', function () {
    locale('tr');
    $rows = td_home('2025-01', '2025-06');
    // Doğum günü: 3 saatte 26 kare, çoğunda yüz
    for ($i = 0; $i < 26; $i++) {
        $rows[] = td_row('moda', sprintf('2025-02-14 %02d:%02d', 19 + intdiv($i * 7, 60), ($i * 7) % 60), 180, ['faces' => $i % 3 ? 2 : 0]);
    }
    // Dosya tarihinden gelen (EXIF'siz) toplu içe aktarma: aynı anda 40 kare — etkinlik değil
    for ($i = 0; $i < 40; $i++) {
        $rows[] = td_row('', '2025-04-01 12:00', 180, ['dsrc' => 'file']);
    }
    $ev = td_kind(td_detect($rows), 'event');
    assert_eq(1, count($ev), 'yalnız doğum günü');
    assert_eq('Etkinlik, 14 Şubat 2025', $ev[0]['title']);
    assert_eq(26, count($ev[0]['ids']));
    assert_eq('2025-02-14', $ev[0]['start_day']);
    assert_eq(['Kadıköy'], array_column($ev[0]['places'], 'name'), 'Türkiye içinde etkinlik yeri ilçe düzeyinde');
});

test('test_trips_cover_prefers_favorite_then_faces_then_sharp', function () {
    $rows = [
        td_row('roma', '2024-05-04 10:00', 120, ['sharp' => 20.0]),
        td_row('roma', '2024-05-04 11:00', 120, ['sharp' => 900.0]),
        td_row('roma', '2024-05-04 12:00', 120, ['faces' => 3, 'sharp' => 400.0]),
        td_row('roma', '2024-05-04 13:00', 120, ['type' => 'video']),
    ];
    assert_eq($rows[2]['id'], trips_pick_cover($rows), 'yüzlü ve net');
    $rows[0]['fav'] = true;
    assert_eq($rows[0]['id'], trips_pick_cover($rows), 'favori önce');
    assert_eq($rows[1]['id'], trips_pick_cover([$rows[1], $rows[3], td_row('roma', '2024-05-04 14:00', 120, ['shot' => true, 'sharp' => 5000.0])]), 'ekran görüntüsü değil');
});

test('test_trips_event_label_from_ml_scores', function () {
    $ids = ['a', 'b', 'c', 'd'];
    $scores = [
        'doğum günü' => ['a' => 0.31, 'b' => 0.30, 'c' => 0.29, 'd' => 0.33],
        'düğün' => ['a' => 0.22, 'b' => 0.21],
        'konser' => ['a' => 0.18],
        'plaj' => [],
        'kar' => ['b' => 0.19],
    ];
    assert_eq('birthday', trips_pick_label($ids, $scores));
    $scores['düğün'] = ['a' => 0.30, 'b' => 0.30, 'c' => 0.29, 'd' => 0.31];
    assert_eq(null, trips_pick_label($ids, $scores), 'açık fark yoksa etiket yok');
    assert_eq(null, trips_pick_label($ids, null), 'ML yok');
});
