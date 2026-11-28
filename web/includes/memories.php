<?php
/**
 * OwnMemories — anılar: "X yıl önce bugün", yoksa "X yıl önce bu hafta", ve "Favorilerinizden".
 * İstek anında hesaplanır (zaman çizelgesiyle aynı görünürlük kuralları; yerel çekim gününe göre).
 */
declare(strict_types=1);

const MEMORY_MAX_ASSETS = 60;

/** Asset'in çekildiği yerdeki yerel zamanı (SQL ifadesi). */
function memories_local_expr(): string
{
    return 'DATE_ADD(a.taken_at, INTERVAL COALESCE(a.taken_tz_offset, 180) MINUTE)';
}

/** Zaman çizelgesinde görünen asset'ler için temel koşul. */
function memories_base_where(): string
{
    return "a.user_id = ? AND a.trashed_at IS NULL AND a.is_archived = 0
        AND NOT (a.type = 'video' AND a.live_pair_id IS NOT NULL AND EXISTS (SELECT 1 FROM assets lp WHERE lp.id = a.live_pair_id AND lp.type = 'photo' AND lp.trashed_at IS NULL))
        AND NOT EXISTS (SELECT 1 FROM assets ed WHERE ed.edited_of_id = a.id AND ed.trashed_at IS NULL)";
}

/** Kullanıcının saat dilimine göre bugünün tarihi. */
function memories_today(int $userId): string
{
    $tz = (string) (db_val('SELECT timezone FROM users WHERE id = ?', [$userId]) ?: default_timezone());
    try {
        return (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
    } catch (Throwable $e) {
        return (new DateTimeImmutable('now', new DateTimeZone(default_timezone())))->format('Y-m-d');
    }
}

/** @return array<int, array> anılar (en yakın yıl önce) */
function memories_for(int $userId, string $date): array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        throw new ValidationError('Geçersiz tarih (YYYY-AA-GG).', 'date');
    }
    [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    if ($y < 1900 || $date > gmdate('Y-m-d', time() + 366 * 86400)) {
        throw new ValidationError('Tarih aralık dışında.', 'date');
    }
    $local = memories_local_expr();
    $base = memories_base_where();

    // 1) X yıl önce bugün
    $rows = db_all("SELECT a.*, YEAR($local) AS ly FROM assets a WHERE $base AND MONTH($local) = ? AND DAY($local) = ? AND YEAR($local) < ?
        ORDER BY a.taken_at, a.id", [$userId, $mo, $d, $y]);
    $out = [];
    foreach (memories_group_by_year($rows) as $year => $assets) {
        $out[] = memories_make('on_this_day', $y - $year, $assets, sprintf('%04d-%02d-%02d', $year, $mo, $d));
    }

    // 2) Bugün yoksa: X yıl önce bu hafta (±3 gün). Her yılın penceresi o yılın aynı gününe göre kurulur
    // (29 Şubat artık olmayan yıllarda 28 Şubat'a sabitlenir); fotoğraf, içine düştüğü pencerenin yılına sayılır
    // (yılbaşına yakın pencereler takvim yılı değiştirse de).
    if (!$out) {
        $minYear = (int) (db_val("SELECT MIN(YEAR($local)) FROM assets a WHERE $base", [$userId]) ?: $y);
        $windows = [];
        for ($yy = max($minYear - 1, $y - 100); $yy < $y; $yy++) {
            $dim = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $yy, $mo), new DateTimeZone('UTC')))->format('t');
            $center = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $yy, $mo, min($d, $dim)), new DateTimeZone('UTC'));
            $windows[$yy] = [$center->modify('-3 days')->format('Y-m-d 00:00:00'), $center->modify('+4 days')->format('Y-m-d 00:00:00'), $center->format('Y-m-d')];
        }
        if ($windows) {
            $conds = [];
            $args = [$userId];
            foreach ($windows as [$from, $to]) {
                $conds[] = "($local >= ? AND $local < ?)";
                array_push($args, $from, $to);
            }
            $rows = db_all("SELECT a.*, $local AS loc FROM assets a WHERE $base AND (" . implode(' OR ', $conds) . ') ORDER BY a.taken_at, a.id', $args);
            $by = [];
            foreach ($rows as $r) {
                foreach ($windows as $yy => [$from, $to]) {
                    if ($r['loc'] >= $from && $r['loc'] < $to) {
                        unset($r['loc']);
                        $by[$yy][] = $r;
                        break;
                    }
                }
            }
            krsort($by);
            foreach ($by as $yy => $assets) {
                $out[] = memories_make('this_week', $y - $yy, $assets, $windows[$yy][2]);
            }
        }
    }
    // 2b) Geziler (Yerler): yıldönümü bu haftaya düşen, evden uzakta geçen günler. Aynı yılın günlük anısı
    // gezinin içindeyse gezi anısı onun yerini alır ("Bozcaada'da bir hafta").
    // Geziler hesaplandıysa saklı geziler (ad, gizleme, albüm bağı) kullanılır; yoksa Yerler'in anlık gezileri.
    if (function_exists('trips_memories') && trips_computed($userId)) {
        $out = trips_memories($userId, $y, $mo, $d, $out);
    } elseif (function_exists('places_trips')) {
        $out = memories_with_trips($userId, $y, $mo, $d, $out);
    }
    usort($out, fn($a, $b) => $a['years_ago'] <=> $b['years_ago']);

    // 3) Favorilerinizden (en az 1 yıl önceki favoriler; gün başına sabit rastgele seçim)
    $seed = crc32($userId . ':' . $date) & 0x7FFFFFFF;
    $yearAgo = (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('-1 year')->format('Y-m-d 00:00:00');
    $fav = db_all("SELECT a.* FROM assets a WHERE $base AND a.is_favorite = 1 AND $local < ? ORDER BY RAND($seed) LIMIT 30", [$userId, $yearAgo]);
    if (count($fav) >= 3) {
        usort($fav, fn($a, $b) => [$a['taken_at'], $a['id']] <=> [$b['taken_at'], $b['id']]);
        $out[] = memories_make('favorites', null, $fav, $date);
    }
    return $out;
}

/** @return array<int, array<int, array>> yıl → asset satırları (yıl azalan değil, en yeni yıl önce) */
function memories_group_by_year(array $rows): array
{
    $by = [];
    foreach ($rows as $r) {
        $by[(int) $r['ly']][] = $r;
    }
    krsort($by);
    return $by;
}

function memories_make(string $kind, ?int $yearsAgo, array $rows, string $date, ?string $title = null, ?string $place = null): array
{
    $rows = array_slice($rows, 0, MEMORY_MAX_ASSETS);
    if ($title === null && $kind !== 'favorites' && function_exists('places_dominant')) {
        // Evden uzakta çekilmişse yer adı başlığa eklenir: "2 yıl önce bugün Roma'da".
        $dom = places_dominant(array_column($rows, 'id'));
        if ($dom !== null && places_is_away(places_home((int) $rows[0]['user_id']), $dom['city_id'])) {
            $place = $dom['name'];
            $at = locale() === 'tr' ? tr_locative($place) : $place;
            $title = t($kind === 'on_this_day' ? 'memories.years_ago_today_at' : 'memories.years_ago_week_at', ['n' => $yearsAgo, 'at' => $at, 'place' => $place]);
        }
    }
    $title ??= match ($kind) {
        'on_this_day' => t('memories.years_ago_today', ['n' => $yearsAgo]),
        'this_week' => t('memories.years_ago_week', ['n' => $yearsAgo]),
        default => t('memories.favorites'),
    };
    $cover = null;
    foreach ($rows as $r) {
        if ((int) $r['is_favorite'] && $r['type'] === 'photo') {
            $cover = $r['id'];
            break;
        }
    }
    if ($cover === null) {
        $photos = array_values(array_filter($rows, fn($r) => $r['type'] === 'photo'));
        $cover = $photos ? $photos[intdiv(count($photos), 2)]['id'] : $rows[0]['id'];
    }
    return [
        'id' => $kind . ':' . $date,
        'kind' => $kind,
        'title' => $title,
        'place' => $place,
        'years_ago' => $yearsAgo,
        'date' => $date,
        'count' => count($rows),
        'cover_asset_id' => $cover,
        'asset_ids' => array_column($rows, 'id'),
        'assets' => assets_json(array_map(function ($r) {
            unset($r['ly'], $r['loc']);
            return $r;
        }, $rows)),
    ];
}

/**
 * Yıldönümü bugünün ±3 gününe düşen gezileri anılara ekler; aynı yılın günlük anısı gezinin içindeyse onun yerine geçer.
 */
function memories_with_trips(int $userId, int $y, int $mo, int $d, array $out): array
{
    $local = memories_local_expr();
    foreach (places_trips($userId, $local, memories_base_where()) as $trip) {
        $ty = (int) substr($trip['start'], 0, 4);
        if ($ty >= $y) {
            continue;
        }
        $dim = (int) gmdate('t', gmmktime(0, 0, 0, $mo, 1, $ty));
        $center = gmmktime(0, 0, 0, $mo, min($d, $dim), $ty);
        $from = gmdate('Y-m-d', $center - 3 * 86400);
        $to = gmdate('Y-m-d', $center + 3 * 86400);
        if ($trip['end'] < $from || $trip['start'] > $to) {
            continue;
        }
        $yearsAgo = $y - $ty;
        $ids = $trip['ids'];
        if (count($ids) > MEMORY_MAX_ASSETS) {
            // Gezinin tamamından eşit aralıklı seçim (yalnız ilk günler değil)
            $step = count($ids) / MEMORY_MAX_ASSETS;
            $ids = array_map(fn($i) => $ids[(int) floor($i * $step)], range(0, MEMORY_MAX_ASSETS - 1));
        }
        $rows = db_all('SELECT a.* FROM assets a WHERE a.id IN (' . db_in($ids) . ') ORDER BY a.taken_at, a.id', $ids);
        if (!$rows) {
            continue;
        }
        $mem = memories_make('trip', $yearsAgo, $rows, $trip['start'], places_trip_title($trip), (string) $trip['place']['name']);
        $mem['id'] = 'trip:' . $trip['start'];
        $mem['end_date'] = $trip['end'];
        $mem['days'] = $trip['days'];
        $replaced = false;
        foreach ($out as $i => $m) {
            if ($m['years_ago'] === $yearsAgo && in_array($m['kind'], ['on_this_day', 'this_week'], true) && $m['date'] >= $trip['start'] && $m['date'] <= $trip['end']) {
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
