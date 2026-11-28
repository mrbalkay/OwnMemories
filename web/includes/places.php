<?php
/**
 * OwnMemories — Yerler: fotoğraf konumlarından çevrimdışı yer adı bulma (ters coğrafi kodlama).
 *
 * Veri: GeoNames (CC BY 4.0, https://www.geonames.org) — server/install-geo.sh dosyaları indirir,
 * bin/geo-import.php (geo_import()) geo_places / geo_countries tablolarına yükler. Çalışma anında dış ağ
 * isteği YOKTUR: konumlar yalnızca bu tablolarla eşleştirilir.
 *
 *   geo_places   : yerleşim yerleri (dünya: cities500, Türkiye: TR.txt'teki tüm yerleşimler/mahalleler).
 *                  cell = 0,1°'lik ızgara hücresi; en yakın yer komşu hücrelerden aranır.
 *   asset_places : asset → yer (name: en yakın yer "Moda", city: şehir düzeyi "İstanbul", district: ilçe
 *                  "Kadıköy" (yalnız TR), admin1: il/eyalet, country: Türkçe ülke adı, region: "Kapadokya").
 *                  Konumu olan ama yakında yer bulunmayan asset için place_id NULL satır tutulur (işlendi).
 *
 * İşler: 'geocode' (tek asset; yükleme/konum değişikliği) ve 'geocode_backfill' (toplu, imleçli, kaldığı yerden).
 */
declare(strict_types=1);

const GEO_CELL = 10;            // 1° başına hücre (0,1°)
const GEO_LNG_CELLS = 3600;     // 360° × 10
const GEO_MAX_KM = 50.0;        // bundan uzaktaki yerler "yer yok"
const GEO_NAME_KM = 3.0;        // en yakın yer (mahalle/köy) bu mesafedeyse adı kullanılır, yoksa şehir
const GEO_BACKFILL_BATCH = 400;
/** İçe aktarılmayan GeoNames türleri: tarihî, terk edilmiş, yıkılmış, dinî merkez. */
const GEO_SKIP_CODES = ['PPLH', 'PPLQ', 'PPLW', 'PPLCH'];

function places_data(): array
{
    static $d = null;
    return $d ??= (array) require __DIR__ . '/places_data.php';
}

/* --------------------------------------------------------------- metin */

/** Aksan/Türkçe harf katlama + küçük harf: "Kadıköy" → "kadikoy", "İZMİR" → "izmir". Arama anahtarı içindir. */
function places_fold(string $s): string
{
    $s = str_replace(['İ', 'I'], ['i', 'ı'], $s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ç' => 'c', 'ö' => 'o', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u',
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ú' => 'u', 'ù' => 'u',
        'ñ' => 'n', 'ś' => 's', 'š' => 's', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'č' => 'c', 'ć' => 'c', 'ř' => 'r', 'ł' => 'l',
        'ß' => 'ss', 'œ' => 'oe', 'æ' => 'ae', 'ý' => 'y', 'ț' => 't', 'ţ' => 't', 'ș' => 's', 'ő' => 'o', 'ű' => 'u', 'ę' => 'e', 'ą' => 'a',
    ]);
    if (function_exists('transliterator_transliterate') && preg_match('/[^\x00-\x7F]/', $s)) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
        if (is_string($t) && $t !== '') {
            $s = strtolower($t);
        }
    }
    return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace("/[^a-z0-9]+/", ' ', $s)));
}

/**
 * Türkçe bulunma eki (-da/-de/-ta/-te), özel adlarda kesme işaretiyle: "Bozcaada'da", "Paris'te", "Göreme'de",
 * "Bangkok'ta", "İzmir'de", "Kadıköy'de". Son ünlüye göre ünlü uyumu (a ı o u → a; e i ö ü → e), sert ünsüzle
 * (f s t k ç ş h p) biten adlarda ünsüz benzeşmesi (d → t). Kısaltmalarda son harfin okunuşu esas alınır ("ABD'de").
 * Okunuşu yazılışından farklı adlar places_data()['locative'] tablosundadır ("Nice'te").
 */
function tr_locative(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $exc = places_data()['locative'];
    if (isset($exc[$name])) {
        return $name . "'" . $exc[$name];
    }
    $word = (string) preg_replace('/[^\p{L}\p{N}]+$/u', '', $name);
    if ($word === '') {
        return $name;
    }
    $parts = preg_split('/[\s\-]+/u', $word) ?: [$word];
    $last = (string) end($parts);
    // Kısaltma (ABD, NYC): harflerin okunuşu — sessiz harfler "e" ile okunur (be, ce, de…), K → ka, H → he.
    if (mb_strlen($last) >= 2 && mb_strlen($last) <= 5 && preg_match('/^\p{Lu}+$/u', $last)) {
        $spell = ['A' => 'a', 'E' => 'e', 'I' => 'ı', 'İ' => 'i', 'O' => 'o', 'Ö' => 'ö', 'U' => 'u', 'Ü' => 'ü', 'K' => 'ka', 'Q' => 'ku', 'W' => 've', 'X' => 'iks', 'Y' => 'ye'];
        $l = mb_substr($last, -1);
        $last = $spell[$l] ?? (mb_strtolower($l === 'I' ? 'ı' : $l) . 'e');
    }
    $low = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $last), 'UTF-8');
    $chars = preg_split('//u', $low, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $back = ['a', 'ı', 'o', 'u', 'â', 'û', 'á', 'à', 'ó', 'ò', 'ú', 'ù', 'ô', 'å', 'ā', 'ō', 'ū'];
    $front = ['e', 'i', 'ö', 'ü', 'î', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'ï', 'ä', 'ø', 'œ', 'æ', 'ē', 'ī', 'y'];
    $vowel = 'e';
    for ($i = count($chars) - 1; $i >= 0; $i--) {
        $c = $chars[$i];
        if (in_array($c, $back, true)) {
            $vowel = 'a';
            break;
        }
        if (in_array($c, $front, true) && !($c === 'y' && $i < count($chars) - 1)) {
            // "y" yalnız sonda ünlü gibi okunur (Sydney); ortada/başta ünsüzdür (Kyoto).
            if ($c === 'y' && $i === count($chars) - 1 && $i > 0 && in_array($chars[$i - 1], array_merge($back, $front), true)) {
                continue; // "Moray", "Kadıköy": y ünsüz, önceki ünlüye bak
            }
            $vowel = 'e';
            break;
        }
    }
    $end = $chars ? $chars[count($chars) - 1] : '';
    $hard = in_array($end, ['f', 's', 't', 'k', 'ç', 'ş', 'h', 'p', 'x', 'q'], true);
    return $name . "'" . ($hard ? 't' : 'd') . $vowel;
}

/** Türkçe ay adı (1–12). */
function tr_month(int $m): string
{
    return ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'][max(1, min(12, $m)) - 1];
}

/** Dile göre ay adı. */
function places_month(int $m): string
{
    if (locale() === 'en') {
        return gmdate('F', gmmktime(0, 0, 0, $m, 1, 2000));
    }
    return tr_month($m);
}

/* -------------------------------------------------------------- ızgara */

function geo_cell(float $lat, float $lng): int
{
    [$la, $lo] = geo_cell_xy($lat, $lng);
    return $la * GEO_LNG_CELLS + $lo;
}

/** @return array{0:int,1:int} [enlem satırı 0..1800, boylam sütunu 0..3599] */
function geo_cell_xy(float $lat, float $lng): array
{
    $la = (int) floor((max(-90.0, min(90.0, $lat)) + 90.0) * GEO_CELL);
    $lo = (int) floor((fmod($lng + 180.0, 360.0) + 360.0) * GEO_CELL) % GEO_LNG_CELLS;
    return [min($la, 180 * GEO_CELL), $lo];
}

function geo_distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = M_PI / 180;
    $dlat = ($lat2 - $lat1) * $r;
    $dlng = ($lng2 - $lng1) * $r;
    $a = sin($dlat / 2) ** 2 + cos($lat1 * $r) * cos($lat2 * $r) * sin($dlng / 2) ** 2;
    return 6371.0 * 2 * asin(min(1.0, sqrt($a)));
}

/** Yer verisi kurulu mu (geo_places dolu)? 60 sn önbellekli (sürekli çalışan işçi sonradan kurulan veriyi görsün). */
function geo_ready(bool $refresh = false): bool
{
    static $ready = null;
    static $at = 0.0;
    if ($refresh || $ready === null || microtime(true) - $at > 60) {
        $at = microtime(true);
        try {
            $ready = db_val('SELECT 1 FROM geo_places LIMIT 1') !== null;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Noktanın çevresindeki adaylar ($r hücre yarıçapı). */
function geo_candidates(float $lat, float $lng, int $r): array
{
    [$la, $lo] = geo_cell_xy($lat, $lng);
    $conds = [];
    $args = [];
    for ($row = max(0, $la - $r); $row <= min(180 * GEO_CELL, $la + $r); $row++) {
        $from = $lo - $r;
        $to = $lo + $r;
        // Tarih değiştirme çizgisi: sütunlar sarılır.
        $ranges = [[max(0, $from), min(GEO_LNG_CELLS - 1, $to)]];
        if ($from < 0) {
            $ranges[] = [GEO_LNG_CELLS + $from, GEO_LNG_CELLS - 1];
        }
        if ($to >= GEO_LNG_CELLS) {
            $ranges[] = [0, $to - GEO_LNG_CELLS];
        }
        foreach ($ranges as [$a, $b]) {
            $conds[] = 'cell BETWEEN ? AND ?';
            array_push($args, $row * GEO_LNG_CELLS + $a, $row * GEO_LNG_CELLS + $b);
        }
    }
    $rows = db_all('SELECT * FROM geo_places WHERE ' . implode(' OR ', $conds), $args);
    foreach ($rows as &$c) {
        $c['d'] = geo_distance_km($lat, $lng, (float) $c['lat'], (float) $c['lng']);
    }
    unset($c);
    return $rows;
}

/** Görünen ad (Türkçe ad varsa o). */
function geo_display_name(array $p): string
{
    return (string) (($p['name_tr'] ?? '') !== '' ? $p['name_tr'] : $p['name']);
}

/**
 * Bir konumun yer bilgisi ya da (yakında yer yoksa / veri kurulu değilse) null:
 * ['place_id','city_id','name','city','district','admin1','country','country_code','region','distance_km']
 */
function geo_lookup(float $lat, float $lng): ?array
{
    if (!geo_ready()) {
        return null;
    }
    $cands = geo_candidates($lat, $lng, 2);
    if (!$cands || min(array_column($cands, 'd')) > 15.0) {
        $cands = geo_candidates($lat, $lng, 6);
    }
    if (!$cands) {
        return null;
    }
    usort($cands, fn($a, $b) => $a['d'] <=> $b['d']);
    $nearest = $cands[0];
    if ($nearest['d'] > GEO_MAX_KM) {
        return null;
    }
    // Şehir: mahalle (PPLX) olmayan yerler arasında nüfusla ağırlıklı yakınlık (d / nüfus^¼). Büyük şehrin
    // ilçeleri (Konak, Çankaya, Üsküdar) şehir merkezine yakınsa şehir adı (İzmir, Ankara, İstanbul) kullanılır.
    $city = null;
    $best = INF;
    foreach ($cands as $c) {
        if ($c['feature_code'] === 'PPLX' || $c['d'] > 40.0) {
            continue;
        }
        $score = $c['d'] / max(1.0, (float) $c['population']) ** 0.25;
        if ($score < $best) {
            $best = $score;
            $city = $c;
        }
    }
    $city ??= $nearest;
    // Büyük şehir kendi mahallelerini/semtlerini kapsar: il merkezi/başkent (PPLA/PPLC) daha kalabalıksa, diğer
    // yerleşimler ise en az 5 kat kalabalık ve 100 bin üstüyse şehir odur ("Libération" → "Nice").
    if (!in_array($city['feature_code'], ['PPLA', 'PPLC'], true)) {
        $cityPop = (int) $city['population'];
        $parent = null;
        foreach ($cands as $c) {
            if ($c['feature_code'] === 'PPLX' || $c['country_code'] !== $city['country_code'] || (int) $c['id'] === (int) $city['id']) {
                continue;
            }
            $pop = (int) $c['population'];
            $radius = $pop >= 1000000 ? 12.0 : ($pop >= 250000 ? 6.0 : ($pop >= 100000 ? 3.0 : 0.0));
            $capital = in_array($c['feature_code'], ['PPLA', 'PPLC'], true);
            if ($radius > 0 && $c['d'] <= $radius && ($capital ? ($pop >= 250000 && $pop > $cityPop) : $pop >= 5 * max(1, $cityPop))
                && ($parent === null || $pop > (int) $parent['population'])) {
                $parent = $c;
            }
        }
        $city = $parent ?? $city;
    }
    $local = $nearest['d'] <= GEO_NAME_KM ? $nearest : $city;
    $cc = (string) $local['country_code'];
    $region = null;
    foreach (places_data()['regions'] as [$rName, $rLat, $rLng, $rKm, $rCc]) {
        if ($rCc === $cc && geo_distance_km($lat, $lng, $rLat, $rLng) <= $rKm) {
            $region = $rName;
            break;
        }
    }
    return [
        'place_id' => (int) $local['id'],
        'city_id' => (int) $city['id'],
        'name' => geo_display_name($local),
        'city' => geo_display_name($city),
        'district' => $cc === 'TR' && ($local['admin2'] ?? '') !== '' ? (string) $local['admin2'] : null,
        'admin1' => ($local['admin1'] ?? '') !== '' ? (string) $local['admin1'] : null,
        'country' => geo_country_name($cc),
        'country_code' => $cc,
        'region' => $region,
        'distance_km' => round((float) $nearest['d'], 2),
    ];
}

/** Türkçe ülke adı (geo_countries; yoksa intl; yoksa tablo; yoksa kod). */
function geo_country_name(string $cc): string
{
    static $cache = [];
    if (isset($cache[$cc])) {
        return $cache[$cc];
    }
    $n = null;
    try {
        $n = db_val('SELECT COALESCE(name_tr, name) FROM geo_countries WHERE code = ?', [$cc]);
    } catch (PDOException $e) {
        $n = null;
    }
    return $cache[$cc] = (string) ($n ?: geo_country_tr($cc, $cc));
}

function geo_country_tr(string $cc, string $fallback): string
{
    if (class_exists('Locale')) {
        $n = Locale::getDisplayRegion('-' . $cc, 'tr');
        if (is_string($n) && $n !== '' && $n !== $cc) {
            return $n;
        }
    }
    return places_data()['countries'][$cc] ?? $fallback;
}

/* -------------------------------------------------------- asset yerleri */

/**
 * Asset JSON'undaki "place" nesnesi (satır asset_places'ten). place_id yoksa null. $asset verilirse ve konum
 * yer bulunduktan sonra değiştiyse (iş henüz çalışmadı) eski yer gösterilmez.
 */
function place_json(?array $p, ?array $asset = null): ?array
{
    if ($p === null || $p['place_id'] === null) {
        return null;
    }
    if ($asset !== null && (abs((float) $asset['latitude'] - (float) $p['lat']) > 1e-6 || abs((float) $asset['longitude'] - (float) $p['lng']) > 1e-6)) {
        return null;
    }
    $parts = [];
    $seen = [];
    $add = function (?string $s) use (&$parts, &$seen) {
        if ($s === null || $s === '') {
            return;
        }
        $k = str_replace(' ', '', places_fold($s)); // "Kız Kalesi" = "Kızkalesi"
        if (!isset($seen[$k])) {
            $seen[$k] = true;
            $parts[] = $s;
        }
    };
    // Türkiye: mahalle/köy → ilçe → il. İlçe biliniyorsa "şehir" (yakındaki büyük yerleşim) adı atlanır; başka bir
    // ilçedeki kasaba olabilir ("Çavuşin, Avanos, Nevşehir"; "Çavuşin, Avanos, Göreme" değil). Yer il merkezinin
    // kendisiyse ilçe önce gelir ("Osmangazi, Bursa").
    $isTr = $p['country_code'] === 'TR';
    $trDistrict = $isTr && ($p['district'] ?? '') !== '';
    $name = $trDistrict && $p['admin1'] !== null && places_fold((string) $p['name']) === places_fold((string) $p['admin1']) ? null : $p['name'];
    $city = $trDistrict ? null : $p['city'];
    foreach ([$name, $p['district'], $city, $p['admin1'], $p['country']] as $s) {
        $add($s);
    }
    $label = implode(', ', $parts);
    // Kısa ad (gün başlığı, harita): "Moda, Kadıköy" · "Bozcaada, Çanakkale" · "Paris, Fransa"
    $parts = [];
    $seen = [];
    foreach ([$name, $p['district'], $city, $isTr ? $p['admin1'] : $p['country']] as $s) {
        if (count($parts) < 2) {
            $add($s);
        }
    }
    return [
        'id' => (int) $p['city_id'],
        'name' => $p['name'],
        'city' => $p['city'],
        'district' => $p['district'],
        'admin1' => $p['admin1'],
        'country' => $p['country'],
        'country_code' => $p['country_code'],
        'region' => $p['region'],
        'label' => $label,
        'short' => implode(', ', $parts),
    ];
}

/** Toplu ön yükleme: asset_to_json() tek tek sorgu yapmasın. */
function places_prefetch(array $ids): void
{
    $ids = array_values(array_filter(array_map('strval', $ids), fn($id) => !array_key_exists($id, $GLOBALS['__om_places'] ?? [])));
    if (!$ids) {
        return;
    }
    foreach (array_chunk($ids, 500) as $chunk) {
        foreach ($chunk as $id) {
            $GLOBALS['__om_places'][$id] = null;
        }
        try {
            foreach (db_all('SELECT * FROM asset_places WHERE asset_id IN (' . db_in($chunk) . ')', $chunk) as $r) {
                $GLOBALS['__om_places'][$r['asset_id']] = $r;
            }
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1146) {
                throw $e;
            }
        }
    }
}

/** asset_to_json için: önbellekten (yoksa tek sorgu) yer satırı. */
function asset_place_row(string $assetId): ?array
{
    if (!array_key_exists($assetId, $GLOBALS['__om_places'] ?? [])) {
        places_prefetch([$assetId]);
    }
    return $GLOBALS['__om_places'][$assetId] ?? null;
}

function places_cache_forget(string $assetId): void
{
    unset($GLOBALS['__om_places'][$assetId]);
}

/** Satır listesi → asset JSON listesi (yerler toplu yüklenir). */
function assets_json(array $rows): array
{
    places_prefetch(array_column($rows, 'id'));
    return array_map('asset_to_json', $rows);
}

/** Arama anahtarı: tüm ad parçaları katlanmış (Türkçe + İngilizce ülke adı, bölge). */
function places_search_key(array $g, string $cc): string
{
    $words = [$g['name'], $g['city'], $g['district'], $g['admin1'], $g['country'], $g['region']];
    try {
        $words[] = db_val('SELECT name FROM geo_countries WHERE code = ?', [$cc]);
        foreach ([$g['place_id'], $g['city_id']] as $pid) {
            $row = db_row('SELECT name, name_tr FROM geo_places WHERE id = ?', [$pid]);
            if ($row) {
                array_push($words, $row['name'], $row['name_tr']);
            }
        }
    } catch (PDOException $e) {
        // tablo yoksa yalnız eldeki adlar
    }
    $out = [];
    foreach ($words as $w) {
        if (is_string($w) && $w !== '') {
            $out[places_fold($w)] = true;
        }
    }
    return mb_substr(' ' . implode(' | ', array_keys($out)) . ' ', 0, 600);
}

/**
 * Bir asset'in yerini bulur ve asset_places'e yazar. Konumu yoksa satır silinir.
 * 'done' | 'skipped' (veri kurulu değil / asset yok).
 */
function places_geocode_asset(string $assetId, array &$memo = []): string
{
    $a = db_row('SELECT id, user_id, latitude, longitude FROM assets WHERE id = ?', [$assetId]);
    if ($a === null) {
        db_exec('DELETE FROM asset_places WHERE asset_id = ?', [$assetId]);
        return 'skipped';
    }
    places_cache_forget($assetId);
    if ($a['latitude'] === null || $a['longitude'] === null) {
        db_exec('DELETE FROM asset_places WHERE asset_id = ?', [$assetId]);
        return 'done';
    }
    if (!geo_ready()) {
        return 'skipped';
    }
    $lat = (float) $a['latitude'];
    $lng = (float) $a['longitude'];
    $key = sprintf('%.3f,%.3f', $lat, $lng); // ~100 m: yakın çekimler aynı sonucu paylaşır
    if (!array_key_exists($key, $memo)) {
        $g = geo_lookup($lat, $lng);
        $memo[$key] = $g === null ? null : $g + ['search_key' => places_search_key($g, $g['country_code'])];
        if (count($memo) > 5000) {
            $memo = array_slice($memo, -2500, null, true);
        }
    }
    $g = $memo[$key];
    db_exec('REPLACE INTO asset_places (asset_id, user_id, place_id, city_id, name, city, district, admin1, country, country_code, region,
            distance_km, lat, lng, search_key, geo_version, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $a['id'], (int) $a['user_id'], $g['place_id'] ?? null, $g['city_id'] ?? null, $g['name'] ?? null, $g['city'] ?? null,
        $g['district'] ?? null, $g['admin1'] ?? null, $g['country'] ?? null, $g['country_code'] ?? null, $g['region'] ?? null,
        $g['distance_km'] ?? null, $a['latitude'], $a['longitude'], $g['search_key'] ?? '', geo_version(), now_utc(),
    ]);
    return 'done';
}

function geo_version(): string
{
    return (string) site_setting('geo_version', '');
}

/** İş: tek asset. */
function job_geocode(array $p): string
{
    return places_geocode_asset((string) ($p['asset_id'] ?? ''));
}

/** Yer bulunması gereken asset'ler (eksik, konumu değişmiş ya da eski veri sürümüyle işlenmiş), $after'dan sonra. */
function places_pending_ids(string $after, int $limit): array
{
    return array_column(db_all("SELECT a.id FROM assets a LEFT JOIN asset_places p ON p.asset_id = a.id
        WHERE a.latitude IS NOT NULL AND a.longitude IS NOT NULL AND a.id > ?
          AND (p.asset_id IS NULL OR p.lat <> a.latitude OR p.lng <> a.longitude OR p.geo_version <> ?)
        ORDER BY a.id LIMIT $limit", [$after, geo_version()]), 'id');
}

function places_pending_count(?int $userId = null): int
{
    try {
        $sql = 'SELECT COUNT(*) FROM assets a LEFT JOIN asset_places p ON p.asset_id = a.id
            WHERE a.latitude IS NOT NULL AND a.longitude IS NOT NULL AND (p.asset_id IS NULL OR p.lat <> a.latitude OR p.lng <> a.longitude OR p.geo_version <> ?)';
        $args = [geo_version()];
        if ($userId !== null) {
            $sql .= ' AND a.user_id = ?';
            $args[] = $userId;
        }
        return (int) db_val($sql, $args);
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * İş: toplu yer bulma. Süre bitene kadar GEO_BACKFILL_BATCH'lik gruplar işler; iş kalırsa imleçle kendini
 * yeniden kuyruğa koyar (kaldığı yerden sürer, çökse bile eksikler bir sonraki çalışmada yeniden seçilir).
 */
function job_geocode_backfill(array $p): string
{
    if (!geo_ready()) {
        return 'skipped';
    }
    $after = (string) ($p['after'] ?? '');
    $memo = [];
    $n = 0;
    // Konumu silinmiş asset'lerin eski yer satırları
    if ($after === '') {
        db_exec('DELETE p FROM asset_places p LEFT JOIN assets a ON a.id = p.asset_id WHERE a.id IS NULL OR a.latitude IS NULL OR a.longitude IS NULL');
    }
    while (true) {
        $ids = places_pending_ids($after, GEO_BACKFILL_BATCH);
        if (!$ids) {
            site_setting_set('geo_backfill_done_at', (string) time());
            return 'done';
        }
        foreach ($ids as $id) {
            places_geocode_asset($id, $memo);
            $after = $id;
            $n++;
        }
        if (job_time_left() < 5 || $n >= 20000) {
            job_enqueue('geocode_backfill', ['after' => $after], 8);
            return 'requeued';
        }
    }
}

/** Toplu işi kuyruğa koyar (bekleyen yoksa). */
function places_enqueue_backfill(): bool
{
    if (db_val("SELECT 1 FROM jobs WHERE type = 'geocode_backfill' AND status IN ('queued','running') LIMIT 1") !== null) {
        return false;
    }
    job_enqueue('geocode_backfill', [], 8);
    return true;
}

/** Yükleme / konum değişikliği sonrası: veri kuruluysa tek asset işi. */
function places_enqueue_asset(string $assetId): void
{
    if (geo_ready()) {
        job_enqueue('geocode', ['asset_id' => $assetId], 6);
    }
}

/* ------------------------------------------------------------- içe aktar */

/** GeoNames sekmeli dosyasını satır satır okur (# yorumları atlanır). */
function geo_read_tsv(string $file): Generator
{
    $fh = fopen($file, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Dosya açılamadı: ' . $file);
    }
    try {
        while (($line = fgets($fh)) !== false) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            yield explode("\t", rtrim($line, "\r\n"));
        }
    } finally {
        fclose($fh);
    }
}

/** Türkiye'deki yerler için alternatenames içinden Türkçe harfli yazımı seçer ("Istanbul" → "İstanbul"). */
function geo_turkish_form(string $name, string $alts): ?string
{
    if ($alts === '' || preg_match('/[İıŞşĞğÇçÖöÜü]/u', $name)) {
        return null;
    }
    $want = places_fold($name);
    foreach (explode(',', $alts) as $alt) {
        if (preg_match('/[İıŞşĞğÇçÖöÜü]/u', $alt) && places_fold($alt) === $want && mb_strlen($alt) === mb_strlen($name)) {
            return $alt;
        }
    }
    return null;
}

function geo_clean_admin(string $s): string
{
    return trim((string) preg_replace('/\s+(Province|İli|Ili|İlçesi|Ilcesi)$/u', '', trim($s)));
}

/**
 * GeoNames dosyalarını içe aktarır: $dir içinde cities500.txt (ya da cities1000.txt), admin1CodesASCII.txt,
 * admin2Codes.txt, countryInfo.txt ve isteğe bağlı TR.txt. Yeni tabloya yükler, sonra atomik olarak değiştirir.
 * Veri değiştiyse toplu yer bulma işi kuyruğa girer. @return array{places:int,countries:int,version:string,changed:bool}
 */
function geo_import(string $dir, ?callable $log = null, bool $force = false): array
{
    $log ??= fn(string $m) => null;
    $cities = null;
    foreach (['cities500.txt', 'cities1000.txt', 'cities5000.txt', 'cities15000.txt'] as $f) {
        if (is_file("$dir/$f")) {
            $cities = "$dir/$f";
            break;
        }
    }
    if ($cities === null) {
        throw new RuntimeException("GeoNames şehir dosyası bulunamadı ($dir/cities500.txt)");
    }
    $files = array_filter([$cities, "$dir/admin1CodesASCII.txt", "$dir/admin2Codes.txt", "$dir/countryInfo.txt", "$dir/TR.txt"], 'is_file');
    $version = substr(sha1(implode('|', array_map(fn($f) => basename($f) . ':' . filesize($f) . ':' . sha1_file($f), $files)) . '|v3'), 0, 16);
    if (!$force && $version === geo_version() && geo_ready(true)) {
        $log("Yer verisi güncel ($version); içe aktarma atlandı.");
        return ['places' => (int) db_val('SELECT COUNT(*) FROM geo_places'), 'countries' => (int) db_val('SELECT COUNT(*) FROM geo_countries'), 'version' => $version, 'changed' => false];
    }

    // 1) İdari adlar
    $admin1 = [];
    if (is_file("$dir/admin1CodesASCII.txt")) {
        foreach (geo_read_tsv("$dir/admin1CodesASCII.txt") as $c) {
            if (count($c) >= 2) {
                $admin1[$c[0]] = geo_clean_admin($c[1]);
            }
        }
    }
    $admin2 = [];
    if (is_file("$dir/admin2Codes.txt")) {
        foreach (geo_read_tsv("$dir/admin2Codes.txt") as $c) {
            if (count($c) >= 2) {
                $admin2[$c[0]] = geo_clean_admin($c[1]);
            }
        }
    }
    $tr = is_file("$dir/TR.txt") ? "$dir/TR.txt" : null;
    if ($tr !== null) {
        // TR.txt'teki il (ADM1) ve ilçe (ADM2) adları Türkçe harflidir ("Nevşehir", "Çanakkale").
        foreach (geo_read_tsv($tr) as $c) {
            if (count($c) < 15 || $c[6] !== 'A') {
                continue;
            }
            $n = geo_clean_admin(geo_turkish_form($c[1], $c[3]) ?? $c[1]);
            if ($c[7] === 'ADM1') {
                $admin1['TR.' . $c[10]] = $n;
            } elseif ($c[7] === 'ADM2' && $c[11] !== '') {
                $admin2['TR.' . $c[10] . '.' . $c[11]] = $n;
            }
        }
        // Türkçe harfsiz kalan ilçe adları ("Nevsehir" merkez ilçesi): aynı yazılan il adıyla düzeltilir.
        $ilByFold = [];
        foreach ($admin1 as $k => $v) {
            if (str_starts_with($k, 'TR.')) {
                $ilByFold[places_fold($v)] = $v;
            }
        }
        foreach ($admin2 as $k => $v) {
            if (str_starts_with($k, 'TR.') && !preg_match('/[İıŞşĞğÇçÖöÜü]/u', $v) && isset($ilByFold[places_fold($v)])) {
                $admin2[$k] = $ilByFold[places_fold($v)];
            }
        }
    }
    $log(sprintf('İdari adlar: %d il/eyalet, %d ilçe', count($admin1), count($admin2)));

    $pdo = db();
    $pdo->exec('DROP TABLE IF EXISTS geo_places_new');
    $pdo->exec('CREATE TABLE geo_places_new LIKE geo_places');
    $exonyms = places_data()['exonyms'];
    $seen = [];
    $batch = [];
    $count = 0;
    $flush = function () use (&$batch, $pdo) {
        if (!$batch) {
            return;
        }
        $ph = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?,?,?,?,?,?,?)'));
        $st = $pdo->prepare("INSERT INTO geo_places_new (id, name, name_tr, feature_code, country_code, admin1_code, admin2_code, admin1, admin2, lat, lng, population, cell) VALUES $ph");
        $st->execute(array_merge(...$batch));
        $batch = [];
    };
    $add = function (array $c) use (&$seen, &$batch, &$count, $admin1, $admin2, $exonyms, $flush) {
        if (count($c) < 15 || $c[6] !== 'P' || in_array($c[7], GEO_SKIP_CODES, true)) {
            return;
        }
        $id = (int) $c[0];
        $name = trim($c[1]);
        // "Paris 01 Louvre", "Paris 10e Arrondissement" gibi numaralı bölümler yer adı olarak kötü görünür.
        if ($id <= 0 || isset($seen[$id]) || $name === '' || preg_match('/\d/', $name)) {
            return;
        }
        $seen[$id] = true;
        $cc = strtoupper(trim($c[8]));
        $nameTr = $exonyms[$cc . '|' . $name] ?? $exonyms[$cc . '|' . $c[2]] ?? ($cc === 'TR' ? geo_turkish_form($name, $c[3]) : null);
        $lat = (float) $c[4];
        $lng = (float) $c[5];
        $a1 = $admin1[$cc . '.' . $c[10]] ?? null;
        $a2 = $c[11] !== '' ? ($admin2[$cc . '.' . $c[10] . '.' . $c[11]] ?? null) : null;
        $batch[] = [$id, mb_substr($name, 0, 160), $nameTr !== null ? mb_substr($nameTr, 0, 160) : null, mb_substr($c[7], 0, 10), $cc,
            mb_substr($c[10], 0, 20), mb_substr($c[11], 0, 80), $a1 !== null ? mb_substr($a1, 0, 160) : null, $a2 !== null ? mb_substr($a2, 0, 160) : null,
            round($lat, 6), round($lng, 6), max(0, min(4294967295, (int) $c[14])), geo_cell($lat, $lng)];
        $count++;
        if (count($batch) >= 500) {
            $flush();
        }
    };
    try {
        // 2) Türkiye: tüm yerleşimler (mahalle, köy, kasaba) önce — sonra dünya şehirleri (yinelenenler atlanır).
        if ($tr !== null) {
            foreach (geo_read_tsv($tr) as $c) {
                $add($c);
            }
            $flush();
            $log("Türkiye yerleşimleri: $count");
        }
        foreach (geo_read_tsv($cities) as $c) {
            $add($c);
        }
        $flush();
        $log('Toplam yer: ' . $count);

        // 3) Ülkeler (Türkçe adlar ICU'dan)
        $pdo->exec('DELETE FROM geo_countries');
        $nc = 0;
        if (is_file("$dir/countryInfo.txt")) {
            $st = $pdo->prepare('INSERT INTO geo_countries (code, name, name_tr) VALUES (?, ?, ?)');
            foreach (geo_read_tsv("$dir/countryInfo.txt") as $c) {
                if (count($c) >= 5 && preg_match('/^[A-Z]{2}$/', $c[0])) {
                    $st->execute([$c[0], mb_substr($c[4], 0, 120), mb_substr(geo_country_tr($c[0], $c[4]), 0, 120)]);
                    $nc++;
                }
            }
        }
        $pdo->exec('DROP TABLE IF EXISTS geo_places_old');
        $pdo->exec('RENAME TABLE geo_places TO geo_places_old, geo_places_new TO geo_places');
        $pdo->exec('DROP TABLE IF EXISTS geo_places_old');
    } catch (Throwable $e) {
        $pdo->exec('DROP TABLE IF EXISTS geo_places_new');
        throw $e;
    }
    site_setting_set('geo_version', $version);
    site_setting_set('geo_imported_at', (string) time());
    site_setting_set('geo_count', (string) $count);
    geo_ready(true);
    $queued = places_enqueue_backfill();
    $log('Ülkeler: ' . $nc . ($queued ? ' · fotoğraflar için yer bulma işi kuyruğa alındı' : ''));
    return ['places' => $count, 'countries' => $nc, 'version' => $version, 'changed' => true];
}

/* ------------------------------------------------------------------ API */

/**
 * GET /v1/places → şehir düzeyinde yerler (zaman çizelgesi görünürlük kurallarıyla), fotoğraf sayısına göre.
 * Her yer: id (şehir), name, admin1, country, country_code, count, cover_asset_id, lat, lng, region.
 */
function api_places_list(array $user, array $q): array
{
    [$where, $args] = api_asset_filters($user, array_intersect_key($q, array_flip(['favorite', 'type'])));
    $rows = db_all("SELECT city_id, city, admin1, country, country_code, region, n, cover, last_at, newest FROM (
            SELECT pl.city_id, pl.city, pl.admin1, pl.country, pl.country_code, pl.region, a.id AS cover, a.taken_at AS last_at,
                   COUNT(*) OVER (PARTITION BY pl.city_id) AS n, MAX(a.taken_at) OVER (PARTITION BY pl.city_id) AS newest,
                   ROW_NUMBER() OVER (PARTITION BY pl.city_id ORDER BY (a.type = 'photo') DESC, a.is_favorite DESC, a.taken_at DESC, a.id DESC) AS rn
            FROM assets a JOIN asset_places pl ON pl.asset_id = a.id
            WHERE $where AND pl.city_id IS NOT NULL
        ) x WHERE rn = 1 ORDER BY n DESC, newest DESC, city", $args);
    $coords = [];
    $ids = array_map('intval', array_column($rows, 'city_id'));
    if ($ids) {
        foreach (db_all('SELECT id, lat, lng, admin1 FROM geo_places WHERE id IN (' . db_in($ids) . ')', $ids) as $g) {
            $coords[(int) $g['id']] = $g;
        }
    }
    $places = array_map(fn($r) => [
        'id' => (int) $r['city_id'],
        'name' => $r['city'],
        'admin1' => $coords[(int) $r['city_id']]['admin1'] ?? $r['admin1'],
        'country' => $r['country'],
        'country_code' => $r['country_code'],
        'region' => $r['region'],
        'count' => (int) $r['n'],
        'cover_asset_id' => $r['cover'],
        'lat' => isset($coords[(int) $r['city_id']]) ? (float) $coords[(int) $r['city_id']]['lat'] : null,
        'lng' => isset($coords[(int) $r['city_id']]) ? (float) $coords[(int) $r['city_id']]['lng'] : null,
    ], $rows);
    return api_ok([
        'places' => $places,
        'ready' => geo_ready(),
        'pending' => places_pending_count((int) $user['id']),
    ]);
}

/** GET /v1/places/{id}: tek yerin özeti (yer sayfası başlığı). */
function api_place_get(array $user, string $id): array
{
    [$where, $args] = api_asset_filters($user, ['place' => $id]);
    $r = db_row("SELECT COUNT(*) AS n, MIN(a.taken_at) AS first_at, MAX(a.taken_at) AS last_at FROM assets a WHERE $where", $args);
    $p = db_row('SELECT pl.city, pl.admin1, pl.country, pl.country_code, pl.region FROM asset_places pl JOIN assets a ON a.id = pl.asset_id
        WHERE a.user_id = ? AND pl.city_id = ? LIMIT 1', [(int) $user['id'], (int) $id]);
    if ($p === null || (int) $r['n'] === 0) {
        api_not_found();
    }
    $g = db_row('SELECT lat, lng, admin1 FROM geo_places WHERE id = ?', [(int) $id]);
    return api_ok(['place' => [
        'id' => (int) $id, 'name' => $p['city'], 'admin1' => $g['admin1'] ?? $p['admin1'], 'country' => $p['country'],
        'country_code' => $p['country_code'], 'region' => $p['region'], 'count' => (int) $r['n'],
        'first_at' => iso_utc($r['first_at']), 'last_at' => iso_utc($r['last_at']),
        'lat' => $g !== null ? (float) $g['lat'] : null, 'lng' => $g !== null ? (float) $g['lng'] : null,
    ]]);
}

/** Yönetim paneli özeti. */
function admin_places_status(): array
{
    return [
        'ready' => geo_ready(),
        'places' => (int) site_setting('geo_count', '0'),
        'imported_at' => (int) site_setting('geo_imported_at', '0'),
        'pending' => places_pending_count(),
        'located' => (int) (db_val('SELECT COUNT(*) FROM assets WHERE latitude IS NOT NULL') ?? 0),
    ];
}

/* ------------------------------------------------------------- anılar */

/**
 * Asset satırları için baskın yer: en az yarısı aynı şehir/bölgedeyse ['name','city_id','country_code','country'],
 * yoksa null. Bölge (Kapadokya) şehirden önce gelir.
 */
function places_dominant(array $assetIds, float $share = 0.5): ?array
{
    if (!$assetIds) {
        return null;
    }
    places_prefetch($assetIds);
    $by = [];
    $located = 0;
    foreach ($assetIds as $id) {
        $p = $GLOBALS['__om_places'][$id] ?? null;
        if ($p === null || $p['city_id'] === null) {
            continue;
        }
        $located++;
        $k = $p['region'] !== null ? 'r:' . $p['region'] : 'c:' . $p['city_id'];
        $by[$k] ??= ['name' => $p['region'] ?? $p['city'], 'city_id' => (int) $p['city_id'], 'country_code' => $p['country_code'], 'country' => $p['country'], 'n' => 0];
        $by[$k]['n']++;
    }
    if (!$by) {
        return null;
    }
    uasort($by, fn($a, $b) => $b['n'] <=> $a['n']);
    $top = reset($by);
    return $top['n'] >= max(1, $share * count($assetIds)) || ($located >= 3 && $top['n'] >= $share * $located && $top['n'] * 2 >= count($assetIds)) ? $top : null;
}

/** Kullanıcının "ev" şehri: en çok farklı ayda (en az 3) fotoğraf çektiği şehir (['city_id','lat','lng']) ya da null. */
function places_home(int $userId): ?array
{
    // Önbellek: anı başlıkları her anı için sorar. Anahtar, kullanıcının yer satırlarının parmak izi (ucuz, dizinli).
    static $cache = [];
    try {
        $fp = implode('|', (array) db_row('SELECT COUNT(*) AS n, MAX(updated_at) AS t FROM asset_places WHERE user_id = ?', [$userId]));
    } catch (PDOException $e) {
        return null;
    }
    if (isset($cache[$userId]) && $cache[$userId][0] === $fp) {
        return $cache[$userId][1];
    }
    $home = places_home_query($userId);
    $cache[$userId] = [$fp, $home];
    return $home;
}

function places_home_query(int $userId): ?array
{
    try {
        // Farklı ay sayısı: uzun bir gezi (12 gün Kapadokya) evi geçemez; eşitlikte gün sayısı.
        $r = db_row("SELECT pl.city_id, COUNT(DISTINCT DATE_FORMAT(a.taken_at, '%Y-%m')) AS months, COUNT(DISTINCT DATE(a.taken_at)) AS days
            FROM assets a JOIN asset_places pl ON pl.asset_id = a.id
            WHERE a.user_id = ? AND a.trashed_at IS NULL AND pl.city_id IS NOT NULL GROUP BY pl.city_id ORDER BY months DESC, days DESC LIMIT 1", [$userId]);
    } catch (PDOException $e) {
        return null;
    }
    if ($r === null || (int) $r['months'] < 3) {
        return null; // ev belirlenemeyecek kadar az veri: her yer gezi sayılabilir
    }
    $g = db_row('SELECT lat, lng, country_code FROM geo_places WHERE id = ?', [(int) $r['city_id']]);
    return ['city_id' => (int) $r['city_id'], 'lat' => $g ? (float) $g['lat'] : null, 'lng' => $g ? (float) $g['lng'] : null, 'country_code' => $g['country_code'] ?? null];
}

/** Bir şehir evden uzak mı (aynı şehir değil ve 40 km'den uzak)? */
function places_is_away(?array $home, int $cityId): bool
{
    if ($home === null) {
        return true;
    }
    if ($cityId === $home['city_id']) {
        return false;
    }
    if ($home['lat'] === null) {
        return true;
    }
    $g = db_row('SELECT lat, lng FROM geo_places WHERE id = ?', [$cityId]);
    return $g === null || geo_distance_km($home['lat'], $home['lng'], (float) $g['lat'], (float) $g['lng']) > 40.0;
}

/**
 * Geziler: evden uzakta geçen ardışık günler (fotoğrafsız 1 güne izin verilir). Her gezi:
 * ['start' => 'Y-m-d', 'end' => 'Y-m-d', 'days' => int, 'ids' => [asset id], 'place' => baskın yer|null].
 * $localExpr ve $baseWhere memories.php'den gelir (aynı görünürlük kuralları).
 */
function places_trips(int $userId, string $localExpr, string $baseWhere): array
{
    try {
        $rows = db_all("SELECT a.id, DATE($localExpr) AS d, pl.city_id, pl.region, pl.country_code FROM assets a
            JOIN asset_places pl ON pl.asset_id = a.id WHERE $baseWhere AND pl.city_id IS NOT NULL ORDER BY a.taken_at, a.id", [$userId]);
    } catch (PDOException $e) {
        return [];
    }
    if (!$rows) {
        return [];
    }
    $home = places_home($userId);
    $awayCache = [];
    $days = [];
    foreach ($rows as $r) {
        $days[$r['d']]['ids'][] = $r['id'];
        $days[$r['d']]['cities'][(int) $r['city_id']] = ($days[$r['d']]['cities'][(int) $r['city_id']] ?? 0) + 1;
    }
    ksort($days);
    $trips = [];
    $cur = null;
    $prev = null;
    foreach ($days as $d => $info) {
        arsort($info['cities']);
        $city = (int) array_key_first($info['cities']);
        $away = $awayCache[$city] ??= places_is_away($home, $city);
        $gap = $prev === null ? 99 : (int) ((strtotime($d . ' UTC') - strtotime($prev . ' UTC')) / 86400);
        if ($away && $cur !== null && $gap <= 2) {
            $cur['end'] = $d;
            $cur['ids'] = array_merge($cur['ids'], $info['ids']);
        } else {
            if ($cur !== null) {
                $trips[] = $cur;
            }
            $cur = $away ? ['start' => $d, 'end' => $d, 'ids' => $info['ids']] : null;
        }
        $prev = $d;
    }
    if ($cur !== null) {
        $trips[] = $cur;
    }
    $out = [];
    foreach ($trips as $t) {
        if (count($t['ids']) < 3) {
            continue;
        }
        $t['days'] = (int) ((strtotime($t['end'] . ' UTC') - strtotime($t['start'] . ' UTC')) / 86400) + 1;
        $t['place'] = places_dominant($t['ids'], 0.6) ?? places_trip_country($t['ids']);
        if ($t['place'] !== null) {
            $out[] = $t;
        }
    }
    return $out;
}

/** Gezinin tamamı tek ülkedeyse ülke (ad olarak). */
function places_trip_country(array $ids): ?array
{
    places_prefetch($ids);
    $cc = null;
    $country = null;
    foreach ($ids as $id) {
        $p = $GLOBALS['__om_places'][$id] ?? null;
        if ($p === null || $p['country_code'] === null) {
            continue;
        }
        if ($cc !== null && $cc !== $p['country_code']) {
            return null;
        }
        $cc = $p['country_code'];
        $country = $p['country'];
    }
    return $cc === null ? null : ['name' => $country, 'city_id' => null, 'country_code' => $cc, 'country' => $country, 'is_country' => true];
}

/**
 * Gezi başlığı: "Bozcaada'da bir gün" · "… bir hafta sonu" · "… 4 gün" (≤5) · "… bir hafta" (6–9) · uzun/ülke: "Kapadokya, Kasım 2023".
 */
function places_trip_title(array $trip): string
{
    $name = (string) $trip['place']['name'];
    $days = (int) $trip['days'];
    [$y, $m] = array_map('intval', explode('-', $trip['start']));
    [$y2, $m2] = array_map('intval', explode('-', $trip['end']));
    $at = locale() === 'tr' ? tr_locative($name) : $name;
    $vars = ['place' => $name, 'at' => $at, 'n' => $days];
    if (!empty($trip['place']['is_country']) || $days >= 10) {
        $when = $m === $m2 && $y === $y2 ? places_month($m) . ' ' . $y
            : ($y === $y2 ? places_month($m) . '–' . places_month($m2) . ' ' . $y : places_month($m) . ' ' . $y . ' – ' . places_month($m2) . ' ' . $y2);
        return $name . ', ' . $when;
    }
    if ($days === 1) {
        return t('memories.trip_day', $vars);
    }
    $weekend = false;
    for ($ts = strtotime($trip['start'] . ' UTC'); $ts <= strtotime($trip['end'] . ' UTC'); $ts += 86400) {
        if ((int) gmdate('N', $ts) >= 6) {
            $weekend = true;
        }
    }
    if ($days <= 3 && $weekend) {
        return t('memories.trip_weekend', $vars);
    }
    if ($days <= 5) {
        return t('memories.trip_days', $vars);
    }
    return t('memories.trip_week', $vars);
}
