<?php
/**
 * OwnMemories — Google Takeout eşleştirme ve meta veri çözümleme (saf fonksiyonlar, G/Ç yok).
 *
 * Takeout'ta fotoğrafın tarihi/konumu çoğu zaman yanındaki .json "sidecar" dosyasındadır ve
 * sidecar adları çeşitli biçimlerde gelir: IMG.jpg.json, IMG.jpg.supplemental-metadata.json ve
 * bunun kısaltılmış halleri, IMG.jpg(1).json (numaralı kopyalar), 46/47 karakterde kesilmiş uzun adlar,
 * düzenlenmiş kopyalar (-edited / -düzenlendi) ve Live Photo videoları (fotoğrafın sidecar'ını kullanır).
 */
declare(strict_types=1);

const TAKEOUT_SUPPLEMENTAL = '.supplemental-metadata';
const TAKEOUT_EDITED_RE = '/-(edited|düzenlendi|duzenlendi|bearbeitet|modifié|modifie|editado|modificato|bewerkt|redigerad|edytowane|編集済み)$/iu';
const TAKEOUT_ALBUM_META_NAMES = ['metadata.json', 'metadaten.json', 'métadonnées.json', 'metadatos.json', 'metadati.json', 'meta veriler.json', 'meta veri.json', 'metagegevens.json'];
const TAKEOUT_OTHER_JSON = ['print-subscriptions.json', 'shared_album_comments.json', 'user-generated-memory-titles.json'];

function tk_nfc(string $s): string
{
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_C);
        if (is_string($n)) {
            return $n;
        }
    }
    return $s;
}

/** Bir klasördeki sidecar adlarından arama indeksi: ['exact' => [nfc => özgün], 'lower' => [nfc küçük harf => özgün]]. */
function takeout_sidecar_index(array $sidecarNames): array
{
    $idx = ['exact' => [], 'lower' => []];
    foreach ($sidecarNames as $name) {
        $n = tk_nfc((string) $name);
        $idx['exact'][$n] = $name;
        $idx['lower'][mb_strtolower($n)] ??= $name;
    }
    return $idx;
}

/** Medya dosya adı (veya yolu) için sidecar adını bulur; yoksa null. */
function takeout_match_sidecar(string $mediaPath, array $sidecarIndex): ?string
{
    $name = tk_nfc(basename(str_replace('\\', '/', $mediaPath)));
    foreach (['exact', 'lower'] as $mode) {
        $set = $sidecarIndex[$mode] ?? [];
        if (!$set) {
            continue;
        }
        $look = function (string $stem) use ($set, $mode): ?string {
            $k = $stem . '.json';
            if ($mode === 'lower') {
                $k = mb_strtolower($k);
            }
            return $set[$k] ?? null;
        };
        // 1) Kendi adıyla
        $hit = tk_match_name($name, $look);
        if ($hit !== null) {
            return $hit;
        }
        // 2) Düzenlenmiş kopya → orijinalin sidecar'ı
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if (preg_match(TAKEOUT_EDITED_RE, $base)) {
            $orig = preg_replace(TAKEOUT_EDITED_RE, '', $base) . ($ext !== '' ? '.' . $ext : '');
            $hit = tk_match_name($orig, $look);
            if ($hit !== null) {
                return $hit;
            }
        }
        // 3) Live Photo videosu → aynı adlı fotoğrafın sidecar'ı
        if (in_array(strtolower($ext), ['mov', 'mp4', 'm4v'], true)) {
            foreach (['HEIC', 'heic', 'JPG', 'jpg', 'JPEG', 'jpeg'] as $pe) {
                $hit = tk_match_name($base . '.' . $pe, $look);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }
    }
    return null;
}

/** Tek bir ad için tüm sidecar biçimlerini dener. */
function tk_match_name(string $name, callable $look): ?string
{
    $noExt = pathinfo($name, PATHINFO_FILENAME);
    // a) IMG.jpg.json
    if (($h = $look($name)) !== null) {
        return $h;
    }
    // b) IMG.jpg.supplemental-metadata.json ve kısaltılmışları (en az "IMG.jpg.s")
    if (($h = tk_match_prefixes($name . TAKEOUT_SUPPLEMENTAL, mb_strlen($name) + 2, '', $look)) !== null) {
        return $h;
    }
    // c) Eski biçim: IMG.json
    if ($noExt !== $name && ($h = $look($noExt)) !== null) {
        return $h;
    }
    // d) Numaralı kopya: IMG(1).jpg → IMG.jpg(1).json, IMG.jpg.supplemental-metadata(1).json
    if (preg_match('/^(.*?)\s*\((\d+)\)(\.[^.]+)?$/u', $name, $m)) {
        $n = '(' . $m[2] . ')';
        $name0 = $m[1] . ($m[3] ?? '');
        if (($h = $look($name0 . $n)) !== null) {
            return $h;
        }
        if (($h = tk_match_prefixes($name0 . TAKEOUT_SUPPLEMENTAL, mb_strlen($name0) + 2, $n, $look)) !== null) {
            return $h;
        }
        if (($h = $look($m[1] . $n)) !== null) {
            return $h;
        }
    }
    // e) Uzun adlar 46/47 (eski) karakterde kesilir
    $len = mb_strlen($name);
    if ($len > 40) {
        for ($l = $len - 1; $l >= 40; $l--) {
            if (($h = $look(mb_substr($name, 0, $l))) !== null) {
                return $h;
            }
        }
    }
    return null;
}

/** $target'in $minLen..tam uzunluk öneklerini (+ $suffix) en uzundan başlayarak dener. Karakter ve bayt bazlı. */
function tk_match_prefixes(string $target, int $minLen, string $suffix, callable $look): ?string
{
    for ($l = mb_strlen($target); $l >= $minLen; $l--) {
        if (($h = $look(mb_substr($target, 0, $l) . $suffix)) !== null) {
            return $h;
        }
    }
    if (strlen($target) !== mb_strlen($target)) {
        for ($l = strlen($target) - 1; $l >= $minLen; $l--) {
            $p = mb_strcut($target, 0, $l);
            if (($h = $look($p . $suffix)) !== null) {
                return $h;
            }
        }
    }
    return null;
}

/**
 * Sidecar JSON'unu çözer. Bozuk JSON'da tüm alanlar boş döner.
 * @return array{title:?string, description:?string, taken_ts:?int, lat:?float, lng:?float, alt:?float, favorited:bool, archived:bool, trashed:bool, people:string[], from_shared:bool}
 */
function takeout_parse_sidecar(string $json): array
{
    $j = json_decode($json, true);
    $j = is_array($j) ? $j : [];
    $ts = $j['photoTakenTime']['timestamp'] ?? null;
    $ts = is_numeric($ts) && (int) $ts > 0 ? (int) $ts : null;
    $geo = null;
    foreach (['geoData', 'geoDataExif'] as $k) {
        $g = $j[$k] ?? null;
        if (is_array($g) && is_numeric($g['latitude'] ?? null) && is_numeric($g['longitude'] ?? null)
            && !((float) $g['latitude'] == 0.0 && (float) $g['longitude'] == 0.0)) {
            $geo = $g;
            break;
        }
    }
    $people = [];
    foreach ((array) ($j['people'] ?? []) as $p) {
        if (is_array($p) && is_string($p['name'] ?? null) && trim($p['name']) !== '') {
            $people[] = trim($p['name']);
        }
    }
    $origin = (array) ($j['googlePhotosOrigin'] ?? []);
    $desc = is_string($j['description'] ?? null) ? trim($j['description']) : '';
    return [
        'title' => is_string($j['title'] ?? null) ? $j['title'] : null,
        'description' => $desc !== '' ? $desc : null,
        'taken_ts' => $ts,
        'lat' => $geo !== null ? (float) $geo['latitude'] : null,
        'lng' => $geo !== null ? (float) $geo['longitude'] : null,
        'alt' => $geo !== null && is_numeric($geo['altitude'] ?? null) ? (float) $geo['altitude'] : null,
        'favorited' => !empty($j['favorited']),
        'archived' => !empty($j['archived']),
        'trashed' => !empty($j['trashed']),
        'people' => array_values(array_unique($people)),
        'from_shared' => array_key_exists('fromSharedAlbum', $origin) || array_key_exists('fromPartnerSharing', $origin),
    ];
}

/**
 * Sidecar + dosya meta verisinden asset_ingest girdilerini üretir (spec §7.1 öncelikleri).
 * $sidecar: takeout_parse_sidecar sonucu veya [] (sidecar yok).
 */
function takeout_resolve(array $sidecar, array $probe, string $filename, ?int $fileMtime): array
{
    $time = capture_time_resolve($sidecar['taken_ts'] ?? null, $probe, $filename, $fileMtime);
    $hasGeo = isset($sidecar['lat'], $sidecar['lng']);
    return [
        'taken_at_utc' => $time['taken_at_utc'],
        'tz_offset' => $time['tz_offset'],
        'date_source' => $time['date_source'],
        'lat' => $hasGeo ? $sidecar['lat'] : $probe['lat'],
        'lng' => $hasGeo ? $sidecar['lng'] : $probe['lng'],
        'alt' => $hasGeo ? ($sidecar['alt'] ?? null) : $probe['alt'],
        'description' => ($sidecar['description'] ?? null) ?: $probe['description'],
        'is_favorite' => !empty($sidecar['favorited']),
        'is_archived' => !empty($sidecar['archived']),
        'shared_from_others' => !empty($sidecar['from_shared']),
    ];
}

/** Takeout "kova" klasörü albüm mü? Albümse başlığı, değilse null. */
function takeout_album_title(string $dir, ?string $metadataJson): ?string
{
    $name = trim(tk_nfc(basename(str_replace('\\', '/', $dir))));
    if ($name === '' || takeout_is_year_bucket($name) || takeout_is_special_bucket($name)) {
        return null;
    }
    if ($metadataJson !== null) {
        $j = json_decode($metadataJson, true);
        if (is_array($j) && is_string($j['title'] ?? null) && trim($j['title']) !== '') {
            $t = trim($j['title']);
            return takeout_is_year_bucket($t) ? null : $t;
        }
    }
    return $name;
}

function takeout_is_year_bucket(string $name): bool
{
    $patterns = [
        '/^photos from \d{4}$/i', '/^\d{4} yılı(na ait|ndan|ndaki)? fotoğraf(lar)?(ı)?$/iu', '/^\d{4} fotoğrafları$/iu',
        '/^fotoğraflar \(\d{4}\)$/iu', '/^fotos von \d{4}$/i', '/^photos de \d{4}$/i', '/^fotos de \d{4}$/i',
        '/^foto del \d{4}$/i', '/^foto\'s uit \d{4}$/i', '/^zdjęcia z \d{4}$/iu', '/^\d{4}年の写真$/u', '/^\d{4}$/',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $name)) {
            return true;
        }
    }
    return false;
}

function takeout_is_special_bucket(string $name): bool
{
    $n = mb_strtolower($name);
    return in_array($n, [
        'trash', 'bin', 'çöp kutusu', 'çöp', 'papierkorb', 'corbeille', 'papelera', 'cestino', 'prullenbak',
        'archive', 'arşiv', 'archiv', 'archives', 'archivo', 'archivio', 'archief',
        'failed videos', 'başarısız videolar', 'locked folder', 'kilitli klasör',
    ], true);
}

/**
 * Zip içi göreli yolları sınıflandırır.
 * media:     [yol => ['dir'=>, 'bucket'=>, 'name'=>, 'kind'=>]]
 * sidecars:  [yol => ['dir'=>, 'name'=>]]
 * album_meta:[dir => yol]
 * other:     [yol, ...]
 */
function takeout_classify(array $entryPaths): array
{
    $out = ['media' => [], 'sidecars' => [], 'album_meta' => [], 'other' => []];
    foreach ($entryPaths as $path) {
        $path = str_replace('\\', '/', (string) $path);
        if ($path === '' || str_ends_with($path, '/')) {
            continue;
        }
        $name = basename($path);
        $dir = dirname($path);
        $dir = $dir === '.' ? '' : $dir;
        $lower = mb_strtolower(tk_nfc($name));
        if (str_starts_with($name, '._') || $lower === '.ds_store' || $lower === 'thumbs.db') {
            $out['other'][] = $path;
            continue;
        }
        if (str_ends_with($lower, '.json')) {
            if (in_array($lower, TAKEOUT_ALBUM_META_NAMES, true)) {
                $out['album_meta'][$dir] = $path;
            } elseif (in_array($lower, TAKEOUT_OTHER_JSON, true)) {
                $out['other'][] = $path;
            } else {
                $out['sidecars'][$path] = ['dir' => $dir, 'name' => $name];
            }
            continue;
        }
        $kind = media_kind($path, $name);
        if ($kind === null) {
            $out['other'][] = $path;
            continue;
        }
        $out['media'][$path] = ['dir' => $dir, 'bucket' => takeout_bucket($dir), 'name' => $name, 'kind' => $kind];
    }
    return $out;
}

/** "Takeout/Google Fotoğraflar/<kova>/..." → kova; Takeout öneki yoksa en içteki klasör. */
function takeout_bucket(string $dir): string
{
    $parts = $dir === '' ? [] : explode('/', $dir);
    $i = array_search('Takeout', $parts, true);
    if ($i !== false && isset($parts[$i + 1]) && stripos($parts[$i + 1], 'google') === 0) {
        return $parts[$i + 2] ?? '';
    }
    return $parts ? (string) end($parts) : '';
}

/** Aynı klasörde aynı taban adlı fotoğraf (HEIC/JPG) + video (MOV/MP4) çiftleri: [foto => video]. */
function takeout_live_pairs(array $mediaPaths): array
{
    $photos = $videos = [];
    foreach ($mediaPaths as $p) {
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $key = mb_strtolower(tk_nfc(dirname($p) . '/' . pathinfo($p, PATHINFO_FILENAME)));
        if (in_array($ext, ['heic', 'heif', 'jpg', 'jpeg'], true)) {
            $photos[$key] ??= $p;
        } elseif (in_array($ext, ['mov', 'mp4'], true)) {
            $videos[$key] ??= $p;
        }
    }
    $pairs = [];
    foreach ($photos as $k => $p) {
        if (isset($videos[$k])) {
            $pairs[$p] = $videos[$k];
        }
    }
    return $pairs;
}

/** Düzenlenmiş kopyanın (IMG-edited.jpg) aynı klasördeki orijinali; yoksa null. */
function takeout_edited_original(string $path, array $mediaPathsInDir): ?string
{
    $base = pathinfo($path, PATHINFO_FILENAME);
    if (!preg_match(TAKEOUT_EDITED_RE, $base)) {
        return null;
    }
    $want = mb_strtolower(tk_nfc(dirname($path) . '/' . preg_replace(TAKEOUT_EDITED_RE, '', $base) . '.' . pathinfo($path, PATHINFO_EXTENSION)));
    foreach ($mediaPathsInDir as $p) {
        if ($p !== $path && mb_strtolower(tk_nfc($p)) === $want) {
            return $p;
        }
    }
    return null;
}
