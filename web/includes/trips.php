<?php
/**
 * OwnMemories — Geziler ve etkinlikler: otomatik gezi ve etkinlik albümleri.
 *
 * Gezi: evden (≥ TRIPS_HOME_KM) uzakta geçen ardışık günler (fotoğrafsız 1 güne izin verilir; arada evde geçen bir
 * gün geziyi böler). "Ev" dönem dönem bulunur (taşınmalar): her ay için ±6 aylık pencerede en çok ayda/günde
 * fotoğraf çekilen şehir; ev noktası o şehirdeki çekimlerin ortancası. Etkinlik: gezi dışında, kısa sürede alışılmadık
 * yoğunlukta çekim (doğum günü, düğün…) ya da evin yakınına günübirlik çıkış ("Şile, 3 Ağustos 2024").
 * İsteğe bağlı: ML hizmeti çalışıyorsa etkinliğe aday sözcüklerle (doğum günü, düğün, konser, plaj, kar) etiket verilir.
 *
 * Saklama: trips + trip_assets (009). 'trips' işi kullanıcı başına yeniden hesaplar (yükleme/içe aktarma/yer bulma
 * sonrası gecikmeli ve gece). Kimlikler kararlıdır: yeni adaylar mevcut gezilerle ortak öğe sayısına göre eşleşir;
 * kullanıcının verdiği ad (title_auto=0), seçtiği kapak, gizleme ve albüme dönüştürme korunur.
 *
 * trips_detect() saftır (veritabanı yok): satırlar trips_load_rows() biçimindedir.
 */
declare(strict_types=1);

const TRIPS_ALGO = 1;
const TRIPS_HOME_KM = 50.0;      // evden bu kadar uzak kareler "uzakta"
const TRIPS_FAR_KM = 150.0;      // tek günlük uzak çıkış da gezi sayılır (uçakla günübirlik)
const TRIPS_MIN_ASSETS = 4;
const TRIPS_OUTING_MIN = 5;      // günübirlik yakın çıkış etkinliği için en az kare
const TRIPS_EVENT_MIN = 12;
const TRIPS_EVENT_FACES_MIN = 8;
const TRIPS_EVENT_GAP = 7200;    // etkinlik içinde iki kare arası en fazla (sn)
const TRIPS_EVENT_SPAN = 57600;  // etkinlik en fazla 16 saat
const TRIPS_GAP_DAYS = 2;        // ardışık uzak günler arası en fazla fark (1 boş gün)
const TRIPS_DEBOUNCE = 600;      // yükleme sonrası yeniden hesaplama gecikmesi (sn)
const TRIPS_SYNC_MAX = 40000;    // ilk ziyarette web isteğinde hesaplanacak en büyük kütüphane (üstü: iş)
/** Etkinlik etiketleri: anahtar → akıllı arama sorgusu. */
const TRIPS_LABEL_PROMPTS = ['birthday' => 'doğum günü', 'wedding' => 'düğün', 'concert' => 'konser', 'beach' => 'plaj', 'snow' => 'kar'];


/* ------------------------------------------------------------------ metin */

/** "12–19 Temmuz 2024" · "28 Haziran – 3 Temmuz 2024" · "30 Aralık 2023 – 2 Ocak 2024" · tek gün "12 Temmuz 2024". */
function trips_range_text(string $a, string $b): string
{
    [$y1, $m1, $d1] = array_map('intval', explode('-', substr($a, 0, 10)));
    [$y2, $m2, $d2] = array_map('intval', explode('-', substr($b, 0, 10)));
    if ($a === $b) {
        return "$d1 " . places_month($m1) . " $y1";
    }
    if ($y1 === $y2 && $m1 === $m2) {
        return "{$d1}–{$d2} " . places_month($m1) . " $y1";
    }
    if ($y1 === $y2) {
        return "$d1 " . places_month($m1) . " – $d2 " . places_month($m2) . " $y1";
    }
    return "$d1 " . places_month($m1) . " $y1 – $d2 " . places_month($m2) . " $y2";
}

/** "Mart 2019" · "Kasım–Aralık 2025" · "Aralık 2025 – Ocak 2026". */
function trips_month_text(string $a, string $b): string
{
    [$y1, $m1] = array_map('intval', explode('-', substr($a, 0, 7)));
    [$y2, $m2] = array_map('intval', explode('-', substr($b, 0, 7)));
    if ($y1 === $y2 && $m1 === $m2) {
        return places_month($m1) . " $y1";
    }
    if ($y1 === $y2) {
        return places_month($m1) . '–' . places_month($m2) . " $y1";
    }
    return places_month($m1) . " $y1 – " . places_month($m2) . " $y2";
}

/** "Roma" · "Roma ve Floransa" · "Roma, Floransa ve Venedik". */
function trips_join(array $names): string
{
    $names = array_values($names);
    $n = count($names);
    if ($n <= 1) {
        return (string) ($names[0] ?? '');
    }
    return implode(', ', array_slice($names, 0, $n - 1)) . ' ' . t('trips.and') . ' ' . $names[$n - 1];
}

/** Payı yeterli öğeler (en az biri): [['name','count',...], ...] → aynı sırayla. */
function trips_significant(array $items, float $share, int $min): array
{
    if (!$items) {
        return [];
    }
    $total = array_sum(array_column($items, 'count'));
    $need = max($min, $share * $total);
    $out = array_values(array_filter($items, fn($p) => $p['count'] >= $need));
    if (!$out) {
        $top = $items;
        usort($top, fn($a, $b) => $b['count'] <=> $a['count']);
        $out = [$top[0]];
    }
    return $out;
}

/** Tarih aralığı hafta sonuna düşüyor mu (2–3 gün, Cumartesi ya da Pazar içeriyor)? */
function trips_is_weekend(string $start, string $end, int $days): bool
{
    if ($days < 2 || $days > 3) {
        return false;
    }
    for ($ts = strtotime($start . ' UTC'); $ts <= strtotime($end . ' UTC'); $ts += 86400) {
        if ((int) gmdate('N', $ts) >= 6) {
            return true;
        }
    }
    return false;
}

/**
 * Otomatik başlık. $c: kind, label, start_day, end_day, days, places, countries, home_cc.
 * $withDate=false: anılar ve kısa gösterim için ("Bozcaada gezisi").
 */
function trips_title(array $c, bool $withDate = true): string
{
    $range = trips_range_text($c['start_day'], $c['end_day']);
    $when = trips_month_text($c['start_day'], $c['end_day']);
    $loc = locale() === 'tr';
    if ($c['kind'] === 'event') {
        $label = $c['label'] ?? null;
        if ($label === 'day_trip' && !empty($c['places'])) {
            $name = (string) trips_significant($c['places'], 0.3, 1)[0]['name'];
            return $withDate ? t('trips.title_event_place', ['place' => $name, 'date' => $range]) : t('trips.short_event_place', ['place' => $name, 'at' => $loc ? tr_locative($name) : $name]);
        }
        if ($label !== null && isset(TRIPS_LABEL_PROMPTS[$label])) {
            $l = t('trips.label_' . $label);
            return $withDate ? t('trips.title_event_label', ['label' => $l, 'date' => $range]) : $l;
        }
        return $withDate ? t('trips.title_event', ['date' => $range]) : t('trips.event');
    }
    $places = trips_significant($c['places'] ?? [], 0.08, 2);
    $countries = trips_significant($c['countries'] ?? [], 0.10, 3);
    if (!$places) {
        return $withDate ? t('trips.title_generic', ['range' => $range]) : t('trips.trip');
    }
    if (count($countries) >= 2) {
        $names = array_column($countries, 'name');
        $list = count($names) > 3 ? t('trips.and_more', ['list' => implode(', ', array_slice($names, 0, 2)), 'n' => count($names) - 2]) : trips_join($names);
        return $withDate ? t('trips.title_countries', ['list' => $list, 'when' => $when]) : t('trips.short_single', ['place' => $list]);
    }
    $country = $countries[0] ?? null;
    $foreign = $country !== null && ($c['home_cc'] ?? null) !== null && $country['cc'] !== $c['home_cc'];
    if ($country !== null) {
        // Tek ülkeli gezide başlık yalnız o ülkenin yerleri (Roma'dan Vatikan'a uğramak "İtalya" gezisini bozmaz)
        $inCountry = array_values(array_filter($c['places'] ?? [], fn($p) => $p['country_code'] === $country['cc']));
        $places = trips_significant($inCountry ?: $places, 0.1, 2) ?: $places;
    }
    $names = array_column($places, 'name');
    if (count($names) >= 4 || ($foreign && $c['days'] >= 14)) {
        if ($foreign) {
            return $withDate ? t('trips.title_country', ['country' => $country['name'], 'when' => $when]) : t('trips.short_single', ['place' => $country['name']]);
        }
        // Yurt içi uzun tur: en çok fotoğraflı üç yer, ziyaret sırasıyla
        $top = $places;
        usort($top, fn($a, $b) => $b['count'] <=> $a['count']);
        $keep = array_flip(array_column(array_slice($top, 0, 3), 'name'));
        $names = array_values(array_filter($names, fn($n) => isset($keep[$n])));
    }
    if (count($names) === 1) {
        $name = $names[0];
        $vars = ['place' => $name, 'at' => $loc ? tr_locative($name) : $name, 'range' => $range];
        if (trips_is_weekend($c['start_day'], $c['end_day'], (int) $c['days'])) {
            return t($withDate ? 'trips.title_weekend' : 'trips.short_weekend', $vars);
        }
        return t($withDate ? 'trips.title_single' : 'trips.short_single', $vars);
    }
    $list = trips_join($names);
    if ($foreign) {
        return t('trips.title_multi_foreign', ['country' => $country['name'], 'list' => $list]);
    }
    return t($withDate ? 'trips.title_multi' : 'trips.short_single', ['list' => $list, 'place' => $list, 'range' => $range]);
}

/* -------------------------------------------------------------- algılama */

/** Kapak puanı: favori, yüzler, netlik; video ve ekran görüntüsü geride. */
function trips_cover_score(array $r): float
{
    $s = 0.0;
    if ($r['type'] !== 'photo') {
        $s -= 3;
    }
    if (!empty($r['shot'])) {
        $s -= 6;
    }
    // Küçük/bozuk görseller (simge, 1×1 yer tutucu) kapak olmasın; yatay kareler afişe daha iyi oturur
    if (isset($r['w'], $r['h']) && $r['w'] > 0 && $r['h'] > 0) {
        if ($r['w'] * $r['h'] < 64 * 64) {
            $s -= 8;
        } elseif ($r['w'] * $r['h'] < 300 * 300) {
            $s -= 1.5;
        } elseif ($r['w'] >= $r['h']) {
            $s += 0.3;
        }
    }
    if (!empty($r['fav'])) {
        $s += 4;
    }
    $s += min(3, (int) ($r['faces'] ?? 0)) * 0.8;
    if (($r['sharp'] ?? null) !== null) {
        $s += max(0.0, min(1.0, log10(max(1.0, (float) $r['sharp'])) / 3)) * 2;
    }
    return $s;
}

/** En iyi kapak (eşitlikte ortaya en yakın kare). */
function trips_pick_cover(array $rows): ?string
{
    if (!$rows) {
        return null;
    }
    $rows = array_values($rows);
    $mid = (count($rows) - 1) / 2;
    $best = null;
    $bestScore = -INF;
    foreach ($rows as $i => $r) {
        $s = trips_cover_score($r) - abs($i - $mid) / max(1, count($rows)) * 0.01;
        if ($s > $bestScore) {
            $bestScore = $s;
            $best = $r['id'];
        }
    }
    return $best;
}

/**
 * Etkinlik etiketi: $scores = [sorgu => [asset id => puan]] (ML'den) ya da null (ML yok).
 * En iyi sorgunun etkinlik karelerindeki ortalaması açıkça (≥ %15) ötekilerden yüksekse ve karelerin en az yarısı
 * sonuçlardaysa etiket anahtarı ('birthday'…), yoksa null.
 */
function trips_pick_label(array $ids, ?array $scores): ?string
{
    if ($scores === null || !$ids) {
        return null;
    }
    $res = [];
    foreach (TRIPS_LABEL_PROMPTS as $key => $prompt) {
        $m = $scores[$prompt] ?? [];
        $sum = 0.0;
        $hits = 0;
        foreach ($ids as $id) {
            if (isset($m[$id])) {
                $sum += (float) $m[$id];
                $hits++;
            }
        }
        $res[$key] = ['mean' => $sum / count($ids), 'hits' => $hits / count($ids)];
    }
    uasort($res, fn($a, $b) => $b['mean'] <=> $a['mean']);
    $keys = array_keys($res);
    $best = $res[$keys[0]];
    $second = $res[$keys[1]] ?? ['mean' => 0.0];
    return $best['hits'] >= 0.5 && $best['mean'] >= 0.15 && $best['mean'] >= $second['mean'] * 1.15 ? $keys[0] : null;
}

function trips_month_index(string $day): int
{
    return (int) substr($day, 0, 4) * 12 + (int) substr($day, 5, 2) - 1;
}

function trips_median(array $v): float
{
    sort($v);
    $n = count($v);
    return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
}

/**
 * Dönemlik ev: ay dizini → ['city_id','lat','lng','cc','name'] (taşınmalar: her ayın ±6 aylık penceresinde
 * en yüksek puanlı şehir; puan = Σ min(o aydaki farklı gün, 4) — uzun bir gezi evi geçemez). Ev hiç bulunamazsa [].
 */
function trips_homes(array $rows): array
{
    $days = [];   // city → mi → day → true
    $coords = []; // city → mi → [[lat,lng]…] (ay başına en çok 40)
    $info = [];
    $minMi = PHP_INT_MAX;
    $maxMi = PHP_INT_MIN;
    foreach ($rows as $r) {
        $mi = trips_month_index($r['day']);
        $minMi = min($minMi, $mi);
        $maxMi = max($maxMi, $mi);
        if ($r['city_id'] === null || $r['lat'] === null) {
            continue;
        }
        $c = (int) $r['city_id'];
        $days[$c][$mi][$r['day']] = true;
        if (count($coords[$c][$mi] ?? []) < 40) {
            $coords[$c][$mi][] = [(float) $r['lat'], (float) $r['lng']];
        }
        $info[$c] ??= ['cc' => $r['cc'], 'name' => $r['city']];
    }
    if (!$days) {
        return [];
    }
    $monthCities = [];
    foreach ($days as $c => $months) {
        foreach ($months as $mi => $d) {
            $monthCities[$mi][$c] = min(4, count($d));
        }
    }
    $pick = [];
    for ($mi = $minMi; $mi <= $maxMi; $mi++) {
        $score = [];
        $months = [];
        for ($w = $mi - 6; $w <= $mi + 6; $w++) {
            foreach ($monthCities[$w] ?? [] as $c => $s) {
                $score[$c] = ($score[$c] ?? 0) + $s;
                $months[$c] = ($months[$c] ?? 0) + 1;
            }
        }
        $best = null;
        foreach ($score as $c => $s) {
            if ($months[$c] < 2 || $s < 3) {
                continue;
            }
            if ($best === null || $s > $score[$best] || ($s === $score[$best] && $months[$c] > $months[$best])) {
                $best = $c;
            }
        }
        $pick[$mi] = $best;
    }
    // Boş aylar en yakın önceki evi alır; baştaki boş aylar ilk bulunan evi
    $last = null;
    foreach ($pick as $mi => $c) {
        if ($c === null) {
            $pick[$mi] = $last;
        } else {
            $last = $c;
        }
    }
    $first = null;
    foreach ($pick as $c) {
        if ($c !== null) {
            $first = $c;
            break;
        }
    }
    foreach ($pick as $mi => $c) {
        if ($c !== null) {
            break;
        }
        $pick[$mi] = $first;
    }
    if (count(array_filter($pick, fn($c) => $c !== null)) === 0) {
        // Az veri: en çok farklı günde çekilen şehir (en az 3 gün)
        $tot = array_map(fn($ms) => array_sum(array_map('count', $ms)), $days);
        arsort($tot);
        $c = (int) array_key_first($tot);
        if ($tot[$c] < 3) {
            return [];
        }
        $pick = array_fill($minMi, $maxMi - $minMi + 1, $c);
    }
    $out = [];
    $memo = [];
    foreach ($pick as $mi => $c) {
        if ($c === null) {
            continue;
        }
        // Ev noktası: pencerede o şehirdeki çekimlerin ortancası (şehir merkezi değil: İstanbul çok geniş)
        $key = $c . ':' . intdiv($mi, 3);
        if (!isset($memo[$key])) {
            $lat = $lng = [];
            for ($w = $mi - 6; $w <= $mi + 6; $w++) {
                foreach ($coords[$c][$w] ?? [] as [$la, $lo]) {
                    $lat[] = $la;
                    $lng[] = $lo;
                }
            }
            if (!$lat) {
                foreach ($coords[$c] as $list) {
                    foreach ($list as [$la, $lo]) {
                        $lat[] = $la;
                        $lng[] = $lo;
                    }
                }
            }
            $memo[$key] = [trips_median($lat), trips_median($lng)];
        }
        $out[$mi] = ['city_id' => $c, 'lat' => $memo[$key][0], 'lng' => $memo[$key][1], 'cc' => $info[$c]['cc'], 'name' => $info[$c]['name']];
    }
    return $out;
}

/** Yer özeti (ziyaret sırasıyla) ve ülkeler. $event: Türkiye'de ilçe düzeyi ("Kadıköy"). */
function trips_place_summary(array $rows, bool $event = false): array
{
    $places = [];
    $countries = [];
    foreach ($rows as $r) {
        if ($r['lat'] === null) {
            continue;
        }
        if ($r['cc'] !== null) {
            $countries[$r['cc']] ??= ['cc' => $r['cc'], 'name' => $r['country'] ?? $r['cc'], 'count' => 0, 'first' => $r['ts']];
            $countries[$r['cc']]['count']++;
        }
        if ($r['city_id'] === null && $r['region'] === null) {
            continue;
        }
        if ($r['region'] !== null) {
            [$k, $name] = ['r:' . $r['region'], $r['region']];
        } elseif ($event && $r['cc'] === 'TR' && ($r['district'] ?? null) !== null) {
            [$k, $name] = ['d:' . $r['district'], $r['district']];
        } else {
            [$k, $name] = ['c:' . $r['city_id'], (string) $r['city']];
        }
        $places[$k] ??= ['name' => $name, 'country' => $r['country'], 'country_code' => $r['cc'], 'city_id' => $r['city_id'] !== null ? (int) $r['city_id'] : null,
            'count' => 0, 'first' => $r['ts'], 'slat' => 0.0, 'slng' => 0.0];
        $places[$k]['count']++;
        $places[$k]['slat'] += (float) $r['lat'];
        $places[$k]['slng'] += (float) $r['lng'];
    }
    $places = array_values($places);
    usort($places, fn($a, $b) => $a['first'] <=> $b['first']);
    $places = array_map(fn($p) => [
        'name' => $p['name'], 'country' => $p['country'], 'country_code' => $p['country_code'], 'city_id' => $p['city_id'], 'count' => $p['count'],
        'lat' => round($p['slat'] / $p['count'], 4), 'lng' => round($p['slng'] / $p['count'], 4),
    ], $places);
    $countries = array_values($countries);
    usort($countries, fn($a, $b) => $a['first'] <=> $b['first']);
    $countries = array_map(fn($c) => ['cc' => $c['cc'], 'name' => $c['name'], 'count' => $c['count']], $countries);
    return [$places, $countries];
}

/** Aday nesnesi (başlık, kapak, sayılar). */
function trips_candidate(string $kind, array $rows, ?string $label, ?array $home, float $dist): array
{
    $rows = array_values($rows);
    [$places, $countries] = trips_place_summary($rows, $kind === 'event');
    $days = array_column($rows, 'day');
    sort($days);
    $start = $days[0];
    $end = $days[count($days) - 1];
    $c = [
        'kind' => $kind,
        'label' => $label,
        'ids' => array_column($rows, 'id'),
        'start_ts' => $rows[0]['ts'],
        'end_ts' => $rows[count($rows) - 1]['ts'],
        'start_day' => $start,
        'end_day' => $end,
        'days' => (int) round((strtotime($end . ' UTC') - strtotime($start . ' UTC')) / 86400) + 1,
        'places' => $places,
        'countries' => $countries,
        'home_cc' => $home['cc'] ?? null,
        'distance_km' => round($dist, 1),
        'cover' => trips_pick_cover($rows),
        'photo_count' => count(array_filter($rows, fn($r) => $r['type'] === 'photo')),
        'video_count' => count(array_filter($rows, fn($r) => $r['type'] !== 'photo')),
        'faces' => array_sum(array_map(fn($r) => min(1, (int) $r['faces']), $rows)),
    ];
    $c['title'] = trips_title($c);
    $c['short_title'] = trips_title($c, false);
    return $c;
}

/**
 * Gezi ve etkinlik adayları (zamana göre). $rows taken_at sırasıyla, her biri:
 * id, ts, day (yerel Y-m-d), lat, lng, city_id, city, district, region, country, cc, fav, type, faces, sharp, shot, dsrc.
 */
function trips_detect(array $rows): array
{
    if (!$rows) {
        return [];
    }
    $homes = trips_homes($rows);
    // 1) Uzak günler
    $byDay = [];
    $distOf = [];
    foreach ($rows as $i => $r) {
        $d = &$byDay[$r['day']];
        $d ??= ['located' => 0, 'away' => 0, 'max' => 0.0];
        if ($r['lat'] === null) {
            continue;
        }
        $home = $homes[trips_month_index($r['day'])] ?? null;
        $dist = $home === null ? 0.0 : geo_distance_km($home['lat'], $home['lng'], (float) $r['lat'], (float) $r['lng']);
        $distOf[$i] = $dist; // ayrı dizide: satırları değiştirmek büyük kütüphanede her satırı kopyalardı
        $d['located']++;
        if ($dist >= TRIPS_HOME_KM) {
            $d['away']++;
            $d['max'] = max($d['max'], $dist);
        }
    }
    unset($d);
    ksort($byDay);
    $runs = [];
    $cur = null;
    foreach ($byDay as $day => $d) {
        if ($d['located'] === 0) {
            continue;
        }
        $away = $d['away'] > 0 && $d['away'] * 2 >= $d['located'];
        $idx = intdiv((int) strtotime($day . ' UTC'), 86400);
        if (!$away) {
            if ($cur !== null) {
                $runs[] = $cur;
                $cur = null;
            }
            continue;
        }
        if ($cur !== null && $idx - $cur['last'] <= TRIPS_GAP_DAYS) {
            $cur['last'] = $idx;
            $cur['days'][$day] = true;
        } else {
            if ($cur !== null) {
                $runs[] = $cur;
            }
            $cur = ['first' => $idx, 'last' => $idx, 'days' => [$day => true]];
        }
    }
    if ($cur !== null) {
        $runs[] = $cur;
    }
    // 2) Gezi üyeleri: uzak günlerin uzak kareleri + aradaki konumsuz kareler
    $out = [];
    $used = [];
    $dayRows = [];
    foreach ($rows as $i => $r) {
        if ($r['lat'] !== null && $distOf[$i] >= TRIPS_HOME_KM) {
            $dayRows[$r['day']][] = $i;
        }
    }
    foreach ($runs as $run) {
        $first = null;
        $last = null;
        foreach (array_keys($run['days']) as $day) {
            foreach ($dayRows[$day] ?? [] as $i) {
                $first = $first === null ? $i : min($first, $i);
                $last = $last === null ? $i : max($last, $i);
            }
        }
        if ($first === null) {
            continue;
        }
        $members = [];
        $max = 0.0;
        for ($i = $first; $i <= $last; $i++) {
            $r = $rows[$i];
            if ($r['lat'] === null || $distOf[$i] >= TRIPS_HOME_KM) {
                $members[$i] = $r;
                $max = max($max, $distOf[$i] ?? 0.0);
            }
        }
        $home = $homes[trips_month_index($rows[$first]['day'])] ?? null;
        $nDays = $run['last'] - $run['first'] + 1;
        if (count($members) >= TRIPS_MIN_ASSETS && ($nDays >= 2 || $max >= TRIPS_FAR_KM)) {
            $out[] = trips_candidate('trip', $members, null, $home, $max);
        } elseif ($nDays === 1 && count($members) >= TRIPS_OUTING_MIN) {
            $out[] = trips_candidate('event', $members, 'day_trip', $home, $max);
        } else {
            continue;
        }
        $used += $members;
    }
    // 3) Etkinlikler: gezi dışı, gerçek çekim zamanlı (dosya tarihi değil) kareler içinde yoğun patlamalar
    $pool = [];
    foreach ($rows as $i => $r) {
        if (!isset($used[$i]) && $r['dsrc'] !== 'file' && empty($r['shot'])) {
            $pool[$i] = $r;
        }
    }
    if ($pool) {
        $perDay = array_count_values(array_column($pool, 'day'));
        $baseline = trips_median(array_values($perDay));
        $bursts = [];
        $b = [];
        $prev = null;
        foreach ($pool as $i => $r) {
            if ($prev !== null && $r['ts'] - $prev > TRIPS_EVENT_GAP) {
                $bursts[] = $b;
                $b = [];
            }
            $b[$i] = $r;
            $prev = $r['ts'];
        }
        $bursts[] = $b;
        foreach ($bursts as $b) {
            $n = count($b);
            if ($n < TRIPS_EVENT_FACES_MIN) {
                continue;
            }
            $f = reset($b);
            $l = end($b);
            if ($l['ts'] - $f['ts'] > TRIPS_EVENT_SPAN) {
                continue;
            }
            if (count(array_unique(array_column($b, 'ts'))) < 0.6 * $n) {
                continue; // aynı anda damgalanmış toplu kopya (ör. WhatsApp), gerçek bir an değil
            }
            $faces = count(array_filter($b, fn($r) => (int) $r['faces'] > 0));
            $need = $faces >= 0.4 * $n ? max(TRIPS_EVENT_FACES_MIN, (int) ceil(2 * $baseline)) : max(TRIPS_EVENT_MIN, (int) ceil(3 * $baseline));
            if ($n < $need) {
                continue;
            }
            $home = $homes[trips_month_index($f['day'])] ?? null;
            $out[] = trips_candidate('event', $b, null, $home, max(array_map(fn($i) => $distOf[$i] ?? 0.0, array_keys($b))));
        }
    }
    usort($out, fn($a, $b) => $a['start_ts'] <=> $b['start_ts']);
    return $out;
}

/* ------------------------------------------------------------- veritabanı */

/** Kullanıcının zaman çizelgesinde görünen öğeleri (trips_detect satırları), çekim sırasıyla. */
function trips_load_rows(int $userId): array
{
    $base = memories_base_where();
    // Büyük kütüphanede bellek: sonuç tamponlanmadan okunur ve yinelenen metinler (şehir, ülke…) tek kopya tutulur
    $pdo = db();
    $buffered = null;
    try {
        $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    } catch (Throwable $e) {
        $buffered = null;
    }
    $intern = [];
    $in = static function (?string $v) use (&$intern): ?string {
        return $v === null ? null : ($intern[$v] ??= $v);
    };
    $st = null;
    try {
        $st = $pdo->prepare("SELECT a.id, a.taken_at, a.taken_tz_offset, a.type, a.is_favorite, a.width, a.height, a.date_source, a.latitude, a.longitude,
                pl.city_id, pl.city, pl.district, pl.region, pl.country, pl.country_code, q.sharpness, q.is_screenshot,
                GREATEST(COALESCE(fc.n, 0), COALESCE(pc.n, 0)) AS faces
            FROM assets a
            LEFT JOIN asset_places pl ON pl.asset_id = a.id
            LEFT JOIN asset_quality q ON q.asset_id = a.id
            LEFT JOIN (SELECT asset_id, COUNT(*) AS n FROM faces WHERE user_id = ? GROUP BY asset_id) fc ON fc.asset_id = a.id
            LEFT JOIN (SELECT ap.asset_id, COUNT(*) AS n FROM asset_person ap JOIN people p ON p.id = ap.person_id WHERE p.user_id = ? GROUP BY ap.asset_id) pc ON pc.asset_id = a.id
            WHERE $base AND a.shared_from_others = 0 ORDER BY a.taken_at, a.id"); // başkasının paylaştığı kareler gezi/ev belirlemez
        $st->execute([$userId, $userId, $userId]);
        $rows = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $ts = (int) strtotime($r['taken_at'] . ' UTC');
            $located = $r['latitude'] !== null && $r['longitude'] !== null;
            $rows[] = [
                'id' => $r['id'],
                'ts' => $ts,
                'day' => gmdate('Y-m-d', $ts + ($r['taken_tz_offset'] !== null ? (int) $r['taken_tz_offset'] : 180) * 60),
                'lat' => $located ? (float) $r['latitude'] : null,
                'lng' => $located ? (float) $r['longitude'] : null,
                'city_id' => $located && $r['city_id'] !== null ? (int) $r['city_id'] : null,
                'city' => $in($r['city']),
                'district' => $in($r['district']),
                'region' => $located ? $in($r['region']) : null,
                'country' => $in($r['country']),
                'cc' => $located ? $in($r['country_code']) : null,
                'fav' => (int) $r['is_favorite'] === 1,
                'type' => $in($r['type']),
                'faces' => (int) $r['faces'],
                'sharp' => $r['sharpness'] !== null ? (float) $r['sharpness'] : null,
                'shot' => (int) ($r['is_screenshot'] ?? 0) === 1,
                'dsrc' => $in($r['date_source']),
                'w' => $r['width'] !== null ? (int) $r['width'] : null,
                'h' => $r['height'] !== null ? (int) $r['height'] : null,
            ];
        }
    } finally {
        $st?->closeCursor();
        if ($buffered !== null) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }
    return $rows;
}

/** Kütüphane parmak izi: değişmediyse yeniden hesaplanmaz. */
function trips_library_fingerprint(int $userId): string
{
    $parts = [TRIPS_ALGO, locale()];
    $parts[] = implode(',', (array) db_row('SELECT COUNT(*), SUM(trashed_at IS NULL), SUM(is_archived), MAX(version), MAX(updated_at) FROM assets WHERE user_id = ?', [$userId]));
    foreach ([
        'SELECT COUNT(*), MAX(updated_at), SUM(COALESCE(city_id, 0) % 9973) FROM asset_places WHERE user_id = ?',
        'SELECT COUNT(*), MAX(id) FROM faces WHERE user_id = ?',
        'SELECT COUNT(*), MAX(computed_at) FROM asset_quality WHERE user_id = ?',
        'SELECT COUNT(*) FROM asset_person ap JOIN people p ON p.id = ap.person_id WHERE p.user_id = ?',
    ] as $sql) {
        try {
            $parts[] = implode(',', (array) db_row($sql, [$userId]));
        } catch (PDOException $e) {
            $parts[] = '-';
        }
    }
    return sha1(implode('|', $parts));
}

function trips_fingerprint(string $kind, array $ids): string
{
    sort($ids);
    return sha1($kind . '|' . implode(',', $ids));
}

/** ML hizmetinden etkinlik sorgularının puanları ya da null (hizmet yok). */
function trips_event_scores(int $userId): ?array
{
    $out = [];
    foreach (TRIPS_LABEL_PROMPTS as $prompt) {
        $res = ml_search($userId, $prompt, SEARCH_ML_LIMIT);
        if ($res === null) {
            return null;
        }
        $out[$prompt] = array_column($res, 'score', 'id');
    }
    return $out;
}

/** Kullanıcının gezileri hesaplanmış mı? */
function trips_computed(int $userId): bool
{
    return (string) site_setting('trips_at_' . $userId, '') !== '';
}

/**
 * Kullanıcının gezi ve etkinliklerini yeniden hesaplar ve saklar.
 * $opt: force (parmak izi aynı olsa da), ml (etkinlik etiketleri için ML'e sor).
 * @return array{created:int,updated:int,deleted:int,skipped:bool,trips:int}
 */
function trips_recompute(int $userId, array $opt = []): array
{
    $res = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => false, 'trips' => 0];
    $lock = 'om_trips_' . $userId;
    if ((int) db()->query('SELECT GET_LOCK(' . db()->quote($lock) . ', 20)')->fetchColumn() !== 1) {
        return ['skipped' => true] + $res;
    }
    $prevLoc = trips_use_user_locale($userId); // saklı başlıklar isteğin değil kullanıcının dilinde (web/iOS/CLI aynı)
    try {
        $fp = trips_library_fingerprint($userId);
        if (empty($opt['force']) && site_setting('trips_fp_' . $userId) === $fp) {
            // Kütüphane aynı; yalnızca ML'in henüz etiketlemediği etkinlikler varsa ve hizmet çalışıyorsa devam
            $unlabeled = !empty($opt['ml']) && db_val("SELECT 1 FROM trips WHERE user_id = ? AND kind = 'event' AND label IS NULL AND label_checked = 0 LIMIT 1", [$userId]) !== null;
            if (!$unlabeled || ml_health() === null) {
                return ['skipped' => true] + $res;
            }
        }
        $rows = trips_load_rows($userId);
        $cands = trips_detect($rows);
        $visible = array_flip(array_column($rows, 'id'));
        unset($rows);
        $res = trips_sync($userId, $cands, $visible, !empty($opt['ml'])) + $res;
        site_setting_set('trips_fp_' . $userId, $fp);
        site_setting_set('trips_at_' . $userId, (string) time());
        return $res;
    } finally {
        locale($prevLoc);
        db()->query('SELECT RELEASE_LOCK(' . db()->quote($lock) . ')');
    }
}

/** Dili kullanıcının diline (users.locale) çevirir; önceki dili döner (geri almak için locale($prev)). */
function trips_use_user_locale(int $userId): string
{
    $prev = locale();
    $l = (string) db_val('SELECT locale FROM users WHERE id = ?', [$userId]);
    if (in_array($l, OM_LOCALES, true)) {
        locale($l);
    }
    return $prev;
}

/** Adayları saklı gezilerle eşleştirir (ortak öğe), değişenleri yazar. */
function trips_sync(int $userId, array $cands, array $visible, bool $ml): array
{
    $res = ['created' => 0, 'updated' => 0, 'deleted' => 0];
    $existing = [];
    foreach (db_all('SELECT * FROM trips WHERE user_id = ?', [$userId]) as $t) {
        $existing[(int) $t['id']] = $t;
    }
    $byAsset = [];
    $sizes = [];
    foreach (db_all('SELECT ta.trip_id, ta.asset_id FROM trip_assets ta JOIN trips t ON t.id = ta.trip_id WHERE t.user_id = ?', [$userId]) as $m) {
        $byAsset[$m['asset_id']][] = (int) $m['trip_id'];
        $sizes[(int) $m['trip_id']] = ($sizes[(int) $m['trip_id']] ?? 0) + 1;
    }
    $pairs = [];
    foreach ($cands as $ci => $c) {
        $ov = [];
        foreach ($c['ids'] as $id) {
            foreach ($byAsset[$id] ?? [] as $tid) {
                $ov[$tid] = ($ov[$tid] ?? 0) + 1;
            }
        }
        foreach ($ov as $tid => $n) {
            if ($n >= max(1, 0.3 * min(count($c['ids']), $sizes[$tid]))) {
                $pairs[] = [$n, $ci, $tid];
            }
        }
    }
    usort($pairs, fn($a, $b) => $b[0] <=> $a[0] ?: $a[2] <=> $b[2]);
    $match = [];
    $taken = [];
    foreach ($pairs as [$n, $ci, $tid]) {
        if (!isset($match[$ci]) && !isset($taken[$tid])) {
            $match[$ci] = $tid;
            $taken[$tid] = true;
        }
    }
    $scores = false; // tembel: yalnız gerekince ML'e sorulur
    $now = now_utc();
    foreach ($cands as $ci => $c) {
        $row = isset($match[$ci]) ? $existing[$match[$ci]] : null;
        $fp = trips_fingerprint($c['kind'], $c['ids']);
        $labelChecked = 0;
        if ($c['kind'] === 'event' && $c['label'] === null) {
            if ($row !== null && $row['kind'] === 'event' && (int) $row['label_checked'] === 1 && $row['fingerprint'] === $fp) {
                $c['label'] = $row['label'];
                $labelChecked = 1;
            } elseif ($ml) {
                if ($scores === false) {
                    $scores = ml_health() !== null ? trips_event_scores($userId) : null;
                }
                if ($scores !== null) {
                    $c['label'] = trips_pick_label($c['ids'], $scores);
                    $labelChecked = 1;
                }
            }
            $c['title'] = trips_title($c);
        } else {
            $labelChecked = 1;
        }
        $summary = json_encode(['places' => $c['places'], 'countries' => $c['countries'], 'home_cc' => $c['home_cc']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $vals = [
            'kind' => $c['kind'], 'label' => $c['label'], 'label_checked' => $labelChecked,
            'start_at' => gmdate('Y-m-d H:i:s', $c['start_ts']), 'end_at' => gmdate('Y-m-d H:i:s', $c['end_ts']),
            'start_day' => $c['start_day'], 'end_day' => $c['end_day'], 'days' => $c['days'],
            'place_summary' => $summary, 'asset_count' => count($c['ids']), 'photo_count' => $c['photo_count'], 'video_count' => $c['video_count'],
            'distance_km' => number_format((float) $c['distance_km'], 1, '.', ''), 'fingerprint' => $fp,
        ];
        if ($row === null) {
            $vals += ['user_id' => $userId, 'title' => mb_substr($c['title'], 0, 255), 'title_auto' => 1, 'cover_asset_id' => $c['cover'], 'cover_auto' => 1,
                'dismissed' => 0, 'album_id' => null, 'created_at' => $now, 'updated_at' => $now];
            db_exec('INSERT INTO trips (' . implode(', ', array_keys($vals)) . ') VALUES (' . implode(', ', array_fill(0, count($vals), '?')) . ')', array_values($vals));
            trips_write_assets((int) db()->lastInsertId(), $c['ids']);
            $res['created']++;
            continue;
        }
        if ((int) $row['title_auto'] === 1) {
            $vals['title'] = mb_substr($c['title'], 0, 255);
        }
        if ((int) $row['cover_auto'] === 1 || !in_array($row['cover_asset_id'], $c['ids'], true)) {
            $vals['cover_asset_id'] = $c['cover'];
            $vals['cover_auto'] = 1;
        }
        $changed = [];
        foreach ($vals as $k => $v) {
            if ((string) $row[$k] !== (string) $v || ($row[$k] === null) !== ($v === null)) {
                $changed[$k] = $v;
            }
        }
        if ($changed) {
            $changed['updated_at'] = $now;
            db_exec('UPDATE trips SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($changed))) . ' WHERE id = ?', array_merge(array_values($changed), [(int) $row['id']]));
            $res['updated']++;
        }
        if ($row['fingerprint'] !== $fp) {
            trips_write_assets((int) $row['id'], $c['ids']);
        }
    }
    // Eşleşmeyen eski geziler: kullanıcı dokunmadıysa silinir; dokunduysa (ad, kapak, gizleme, albüm) görünür öğeleriyle kalır.
    foreach ($existing as $tid => $t) {
        if (isset($taken[$tid])) {
            continue;
        }
        $touched = (int) $t['title_auto'] === 0 || (int) $t['cover_auto'] === 0 || (int) $t['dismissed'] === 1 || $t['album_id'] !== null;
        $ids = array_column(db_all('SELECT asset_id FROM trip_assets WHERE trip_id = ?', [$tid]), 'asset_id');
        $keep = array_values(array_filter($ids, fn($id) => isset($visible[$id])));
        if (!$touched || !$keep) {
            db_exec('DELETE FROM trip_assets WHERE trip_id = ?', [$tid]);
            db_exec('DELETE FROM trips WHERE id = ?', [$tid]);
            $res['deleted']++;
            continue;
        }
        if (count($keep) !== count($ids)) {
            db_exec('DELETE FROM trip_assets WHERE trip_id = ? AND asset_id NOT IN (' . db_in($keep) . ')', array_merge([$tid], $keep));
            $cover = in_array($t['cover_asset_id'], $keep, true) ? $t['cover_asset_id'] : $keep[0];
            db_exec('UPDATE trips SET asset_count = ?, cover_asset_id = ?, fingerprint = ?, updated_at = ? WHERE id = ?', [count($keep), $cover, trips_fingerprint($t['kind'], $keep), $now, $tid]);
            $res['updated']++;
        }
    }
    $res['trips'] = count($cands);
    return $res;
}

function trips_write_assets(int $tripId, array $ids): void
{
    db_exec('DELETE FROM trip_assets WHERE trip_id = ?', [$tripId]);
    foreach (array_chunk($ids, 400) as $chunk) {
        db_exec('INSERT IGNORE INTO trip_assets (trip_id, asset_id) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?)')),
            array_merge(...array_map(fn($id) => [$tripId, $id], $chunk)));
    }
}

/* ------------------------------------------------------------------- işler */

/**
 * Kullanıcı için gecikmeli yeniden hesaplama (kuyrukta bekleyen varsa yenisi eklenmez; daha erken istendiyse öne çekilir).
 * Her yüklemede çağrılır: bekleyen işin kimliği site ayarında tutulur ve birincil anahtarla bakılır (büyük içe
 * aktarmada kuyrukta yüz binlerce iş varken jobs tablosunu taramamak için).
 */
function trips_schedule(int $userId, int $delaySec = TRIPS_DEBOUNCE): bool
{
    try {
        $key = 'trips_job_' . $userId;
        $runAfter = gmdate('Y-m-d H:i:s', time() + $delaySec);
        $jobId = (int) site_setting($key, 0);
        $job = $jobId > 0 ? db_row('SELECT id, status FROM jobs WHERE id = ? AND type = ?', [$jobId, 'trips']) : null;
        if ($job === null && $jobId === 0) {
            // İşaret yok (ör. güncellemeden önce kuyruğa girmiş iş): bir kez tara
            $found = db_val("SELECT id FROM jobs WHERE type = 'trips' AND status = 'queued' AND payload = ? LIMIT 1", [json_encode(['user_id' => $userId])]);
            $job = $found !== null ? ['id' => $found, 'status' => 'queued'] : null;
        }
        if ($job !== null && $job['status'] === 'queued') {
            db_exec("UPDATE jobs SET run_after = LEAST(run_after, ?) WHERE id = ? AND status = 'queued'", [$runAfter, (int) $job['id']]);
            if ((int) $job['id'] !== $jobId) {
                site_setting_set($key, (string) $job['id']);
            }
            return false;
        }
        site_setting_set($key, (string) job_enqueue('trips', ['user_id' => $userId], 8, $delaySec));
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Tüm etkin kullanıcılar (gece bakımı, yer verisi yüklenince). */
function trips_enqueue_all(int $delaySec = 0): int
{
    $n = 0;
    foreach (db_all("SELECT id FROM users WHERE status = 'active'") as $u) {
        $n += trips_schedule((int) $u['id'], $delaySec) ? 1 : 0;
    }
    return $n;
}

/** İş: payload user_id (yoksa herkes için kuyruğa koyar). */
function job_trips(array $p): string
{
    if (!isset($p['user_id'])) {
        trips_enqueue_all();
        return 'done';
    }
    $uid = (int) $p['user_id'];
    if (db_val('SELECT 1 FROM users WHERE id = ?', [$uid]) === null) {
        return 'skipped';
    }
    // Hesaplama görünür öğeleri bellekte tutar (~2 KB/öğe): sınır gerekirse kütüphane boyutuna göre yükseltilir
    $n = (int) db_val('SELECT COUNT(*) FROM assets WHERE user_id = ? AND trashed_at IS NULL', [$uid]);
    $need = max(512, 128 + (int) ceil($n * 3 / 1024)) * 1048576;
    $cur = php_memory_limit_bytes();
    if ($cur !== -1 && $cur < $need) {
        @ini_set('memory_limit', (string) intdiv($need, 1048576) . 'M');
    }
    $r = trips_recompute($uid, ['ml' => true]);
    return $r['skipped'] ? 'skipped' : 'done';
}

/* -------------------------------------------------------------------- API */

function trip_owned(int $userId, string $id): array
{
    if (!ctype_digit($id)) {
        api_not_found();
    }
    $t = db_row('SELECT t.*, al.id AS album_live FROM trips t LEFT JOIN albums al ON al.id = t.album_id AND al.user_id = t.user_id WHERE t.id = ? AND t.user_id = ?', [(int) $id, $userId]);
    if ($t === null) {
        api_not_found();
    }
    return $t;
}

/** Saklı satırdan otomatik başlık (yer özeti JSON'undan). */
function trips_row_title(array $t, bool $withDate = true): string
{
    $s = json_decode((string) $t['place_summary'], true) ?: [];
    return trips_title([
        'kind' => $t['kind'], 'label' => $t['label'], 'start_day' => (string) $t['start_day'], 'end_day' => (string) $t['end_day'],
        'days' => (int) $t['days'], 'places' => $s['places'] ?? [], 'countries' => $s['countries'] ?? [], 'home_cc' => $s['home_cc'] ?? null,
    ], $withDate);
}

function trip_to_json(array $t): array
{
    $s = json_decode((string) $t['place_summary'], true) ?: [];
    $auto = (int) $t['title_auto'] === 1;
    $label = $t['label'];
    return [
        'id' => (int) $t['id'],
        'kind' => $t['kind'],
        'label' => $label,
        'label_text' => $label !== null ? t('trips.label_' . $label) : null,
        'title' => $t['title'],
        'short_title' => $auto ? trips_row_title($t, false) : $t['title'],
        'title_auto' => $auto,
        'start_at' => iso_utc($t['start_at']),
        'end_at' => iso_utc($t['end_at']),
        'start_day' => (string) $t['start_day'],
        'end_day' => (string) $t['end_day'],
        'days' => (int) $t['days'],
        'range_text' => trips_range_text((string) $t['start_day'], (string) $t['end_day']),
        'places' => array_map(fn($p) => array_intersect_key($p, array_flip(['name', 'country', 'country_code', 'city_id', 'count', 'lat', 'lng'])), $s['places'] ?? []),
        'countries' => $s['countries'] ?? [],
        'cover_asset_id' => $t['cover_asset_id'],
        'count' => (int) $t['asset_count'],
        'photo_count' => (int) $t['photo_count'],
        'video_count' => (int) $t['video_count'],
        'distance_km' => $t['distance_km'] !== null ? (float) $t['distance_km'] : null,
        'dismissed' => (int) $t['dismissed'] === 1,
        'album_id' => array_key_exists('album_live', $t) ? $t['album_live'] : $t['album_id'],
        'updated_at' => iso_utc($t['updated_at']),
    ];
}

/** GET /v1/trips?kind=trip|event&dismissed=1 */
function api_trips_list(array $user, array $q): array
{
    $uid = (int) $user['id'];
    if (!trips_computed($uid)) {
        try {
            $n = (int) db_val('SELECT COUNT(*) FROM assets WHERE user_id = ? AND trashed_at IS NULL', [$uid]);
            if ($n <= (int) ($GLOBALS['__om_trips_sync_max'] ?? TRIPS_SYNC_MAX)) {
                trips_recompute($uid, ['ml' => false]); // ilk ziyaret: hemen hesapla (etiketler sonra işle gelir)
                trips_schedule($uid, 5);
            } else {
                trips_schedule($uid, 0); // büyük kütüphane: web isteğinin bellek/süre sınırına takılmasın, iş hesaplar
            }
        } catch (Throwable $e) {
            app_log('trips', $e->getMessage());
        }
    }
    $where = ['t.user_id = ?', 't.dismissed = ?'];
    $args = [$uid, api_bool($q['dismissed'] ?? null) ? 1 : 0];
    if (in_array($q['kind'] ?? '', ['trip', 'event'], true)) {
        $where[] = 't.kind = ?';
        $args[] = $q['kind'];
    }
    $rows = db_all('SELECT t.*, al.id AS album_live FROM trips t LEFT JOIN albums al ON al.id = t.album_id AND al.user_id = t.user_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY t.start_at DESC, t.id DESC', $args);
    $at = (string) site_setting('trips_at_' . $uid, '');
    return api_ok([
        'trips' => array_map('trip_to_json', $rows),
        'hidden' => (int) db_val('SELECT COUNT(*) FROM trips WHERE user_id = ? AND dismissed = 1', [$uid]),
        'status' => [
            'computed_at' => $at !== '' ? gmdate('Y-m-d\TH:i:s\Z', (int) $at) : null,
            // Yalnız şu an çalışan ya da sırası gelmiş iş (yükleme sonrası gecikmeli iş "güncelleniyor" göstermez)
            'pending' => db_val("SELECT 1 FROM jobs WHERE id = ? AND type = 'trips' AND (status = 'running' OR (status = 'queued' AND run_after <= ?))",
                [(int) site_setting('trips_job_' . $uid, 0), now_utc()]) !== null,
            'places_ready' => geo_ready(),
            'places_pending' => places_pending_count($uid),
        ],
    ]);
}

/** GET /v1/trips/{id}: + rota (çekim sırasıyla en çok 240 nokta). */
function api_trip_get(array $user, string $id): array
{
    $t = trip_owned((int) $user['id'], $id);
    $pts = db_all('SELECT a.latitude, a.longitude FROM trip_assets ta JOIN assets a ON a.id = ta.asset_id
        WHERE ta.trip_id = ? AND a.latitude IS NOT NULL AND a.trashed_at IS NULL ORDER BY a.taken_at, a.id', [(int) $t['id']]);
    $n = count($pts);
    $step = max(1, $n / 240);
    $route = [];
    for ($i = 0.0; (int) $i < $n; $i += $step) {
        $p = $pts[(int) $i];
        $route[] = ['lat' => (float) $p['latitude'], 'lng' => (float) $p['longitude']];
    }
    if ($n > 1 && end($route) != ['lat' => (float) $pts[$n - 1]['latitude'], 'lng' => (float) $pts[$n - 1]['longitude']]) {
        $route[] = ['lat' => (float) $pts[$n - 1]['latitude'], 'lng' => (float) $pts[$n - 1]['longitude']];
    }
    return api_ok(['trip' => trip_to_json($t) + ['route' => $route]]);
}

/** PATCH /v1/trips/{id} {title?, dismissed?, cover_asset_id?} — boş/null ad otomatik ada döner. */
function api_trip_patch(array $user, string $id, array $b): array
{
    $uid = (int) $user['id'];
    $t = trip_owned($uid, $id);
    $set = [];
    $args = [];
    foreach (['title', 'cover_asset_id'] as $k) {
        if (array_key_exists($k, $b) && $b[$k] !== null && !is_string($b[$k])) {
            throw new ValidationError(t('trips.bad_value'), $k);
        }
    }
    if (array_key_exists('dismissed', $b) && !is_scalar($b['dismissed'])) {
        throw new ValidationError(t('trips.bad_value'), 'dismissed');
    }
    if (array_key_exists('title', $b)) {
        $title = trim((string) ($b['title'] ?? ''));
        if (mb_strlen($title) > 120) {
            throw new ValidationError(t('trips.title_too_long'), 'title');
        }
        if ($title === '') {
            $set[] = 'title = ?, title_auto = 1';
            $prevLoc = trips_use_user_locale($uid);
            try {
                $args[] = mb_substr(trips_row_title($t), 0, 255);
            } finally {
                locale($prevLoc);
            }
        } else {
            $set[] = 'title = ?, title_auto = 0';
            $args[] = $title;
        }
    }
    if (array_key_exists('dismissed', $b)) {
        $set[] = 'dismissed = ?';
        $args[] = api_bool($b['dismissed']) ? 1 : 0;
    }
    if (array_key_exists('cover_asset_id', $b)) {
        $c = (string) $b['cover_asset_id'];
        if (db_val('SELECT 1 FROM trip_assets WHERE trip_id = ? AND asset_id = ?', [(int) $t['id'], $c]) === null) {
            throw new ValidationError(t('trips.cover_invalid'), 'cover_asset_id');
        }
        $set[] = 'cover_asset_id = ?, cover_auto = 0';
        $args[] = $c;
    }
    if ($set) {
        $set[] = 'updated_at = ?';
        array_push($args, now_utc(), (int) $t['id']);
        db_exec('UPDATE trips SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    }
    return api_trip_get($user, (string) $t['id']);
}

/** DELETE /v1/trips/{id}: gizle (öğeler silinmez; yeniden hesaplamada geri gelmez). */
function api_trip_dismiss(array $user, string $id): array
{
    $t = trip_owned((int) $user['id'], $id);
    db_exec('UPDATE trips SET dismissed = 1, updated_at = ? WHERE id = ?', [now_utc(), (int) $t['id']]);
    return api_ok(['ok' => true]);
}

/** POST /v1/trips/{id}/album: normal bir albüme dönüştürür (bağ korunur; zaten varsa onu döner). */
function api_trip_album(array $user, string $id): array
{
    $uid = (int) $user['id'];
    $t = trip_owned($uid, $id);
    if ($t['album_live'] !== null) {
        return api_ok(['album' => album_detail($t['album_live'], $uid), 'trip' => trip_to_json($t)]);
    }
    $ids = array_column(db_all('SELECT a.id FROM trip_assets ta JOIN assets a ON a.id = ta.asset_id
        WHERE ta.trip_id = ? AND a.user_id = ? AND a.trashed_at IS NULL ORDER BY a.taken_at, a.id', [(int) $t['id'], $uid]), 'id');
    if (!$ids) {
        throw new ValidationError(t('trips.empty'), 'id');
    }
    $s = json_decode((string) $t['place_summary'], true) ?: [];
    $where = trips_join(array_slice(array_column($s['places'] ?? [], 'name'), 0, 6));
    $desc = trips_range_text((string) $t['start_day'], (string) $t['end_day']) . ($where !== '' ? ' · ' . $where : '');
    $albumId = album_create($uid, (string) $t['title'], $desc);
    album_add_assets($albumId, $uid, $ids);
    if ($t['cover_asset_id'] !== null && in_array($t['cover_asset_id'], $ids, true)) {
        db_exec('UPDATE albums SET cover_asset_id = ? WHERE id = ?', [$t['cover_asset_id'], $albumId]);
    }
    db_exec('UPDATE trips SET album_id = ?, updated_at = ? WHERE id = ?', [$albumId, now_utc(), (int) $t['id']]);
    return api_ok(['album' => album_detail($albumId, $uid), 'trip' => trip_to_json(trip_owned($uid, (string) $t['id']))], 201);
}

/* ------------------------------------------------------------------ anılar */

/**
 * Saklı gezilerden anılar: yıldönümü bugünün ±3 gününe düşen (gizlenmemiş) geziler. Aynı yılın günlük/haftalık
 * anısı gezinin içindeyse gezi anısı onun yerine geçer. Başlık "Bozcaada gezisi" (ya da kullanıcının verdiği ad),
 * label "1 yıl önce bu hafta: Bozcaada gezisi".
 */
function trips_memories(int $userId, int $y, int $mo, int $d, array $out): array
{
    $trips = db_all("SELECT t.*, al.id AS album_live FROM trips t LEFT JOIN albums al ON al.id = t.album_id AND al.user_id = t.user_id
        WHERE t.user_id = ? AND t.kind = 'trip' AND t.dismissed = 0 AND YEAR(t.start_day) < ? ORDER BY t.start_day", [$userId, $y]);
    foreach ($trips as $t) {
        $ty = (int) substr((string) $t['start_day'], 0, 4);
        $dim = (int) gmdate('t', gmmktime(0, 0, 0, $mo, 1, $ty));
        $center = gmmktime(0, 0, 0, $mo, min($d, $dim), $ty);
        $from = gmdate('Y-m-d', $center - 3 * 86400);
        $to = gmdate('Y-m-d', $center + 3 * 86400);
        if ($t['end_day'] < $from || $t['start_day'] > $to) {
            continue;
        }
        $rows = db_all('SELECT a.* FROM trip_assets ta JOIN assets a ON a.id = ta.asset_id WHERE ta.trip_id = ? AND ' . memories_base_where() . ' ORDER BY a.taken_at, a.id',
            [(int) $t['id'], $userId]);
        if (!$rows) {
            continue;
        }
        if (count($rows) > MEMORY_MAX_ASSETS) {
            $step = count($rows) / MEMORY_MAX_ASSETS;
            $rows = array_map(fn($i) => $rows[(int) floor($i * $step)], range(0, MEMORY_MAX_ASSETS - 1));
        }
        $yearsAgo = $y - $ty;
        $tripIds = array_column(db_all('SELECT asset_id FROM trip_assets WHERE trip_id = ?', [(int) $t['id']]), 'asset_id');
        $j = trip_to_json($t);
        $title = $j['short_title'];
        $mem = memories_make('trip', $yearsAgo, $rows, (string) $t['start_day'], $title, $j['places'][0]['name'] ?? null);
        if ($t['cover_asset_id'] !== null && in_array($t['cover_asset_id'], $mem['asset_ids'], true)) {
            $mem['cover_asset_id'] = $t['cover_asset_id'];
        }
        $mem['id'] = 'trip:' . $t['start_day'];
        $mem['end_date'] = (string) $t['end_day'];
        $mem['days'] = (int) $t['days'];
        $mem['trip_id'] = (int) $t['id'];
        $mem['album_id'] = $j['album_id'];
        $mem['label'] = t('trips.memory_label', ['n' => $yearsAgo, 'title' => $title]);
        $replaced = false;
        foreach ($out as $i => $m) {
            if ($m['years_ago'] !== $yearsAgo || !in_array($m['kind'], ['on_this_day', 'this_week'], true)) {
                continue;
            }
            // Günlük/haftalık anı gezinin içindeyse ya da karelerinin yarısı gezidense gezi anısı onun yerine geçer
            $inTrip = count(array_intersect($m['asset_ids'], $tripIds));
            if (($m['date'] >= $t['start_day'] && $m['date'] <= $t['end_day']) || $inTrip * 2 >= count($m['asset_ids'])) {
                $out[$i] = $mem;
                $replaced = true;
                break;
            }
        }
        if (!$replaced && !in_array($mem['id'], array_column($out, 'id'), true)) {
            $out[] = $mem;
        }
    }
    return $out;
}

/** Kalıcı silinen öğe gezilerden çıkar (asset_purge çağırır): sayı ve kapak hemen düzelir, gezi bir sonraki hesaplamada güncellenir. */
function trips_purge_asset(string $assetId): void
{
    try {
        $tids = array_map('intval', array_column(db_all('SELECT trip_id FROM trip_assets WHERE asset_id = ?', [$assetId]), 'trip_id'));
        if (!$tids) {
            return;
        }
        db_exec('DELETE FROM trip_assets WHERE asset_id = ?', [$assetId]);
        foreach ($tids as $tid) {
            $next = db_val('SELECT ta.asset_id FROM trip_assets ta JOIN assets a ON a.id = ta.asset_id WHERE ta.trip_id = ? AND a.trashed_at IS NULL ORDER BY a.is_favorite DESC, a.taken_at LIMIT 1', [$tid]);
            db_exec('UPDATE trips SET asset_count = (SELECT COUNT(*) FROM trip_assets WHERE trip_id = ?), cover_asset_id = IF(cover_asset_id = ?, ?, cover_asset_id),
                cover_auto = IF(cover_asset_id = ?, 1, cover_auto), fingerprint = \'\', updated_at = ? WHERE id = ?', [$tid, $assetId, $next, $assetId, now_utc(), $tid]);
        }
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) !== 1146) { // trips henüz kurulmadı
            throw $e;
        }
    }
}
