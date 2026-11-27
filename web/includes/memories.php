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

function memories_make(string $kind, ?int $yearsAgo, array $rows, string $date): array
{
    $rows = array_slice($rows, 0, MEMORY_MAX_ASSETS);
    $title = match ($kind) {
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
        'years_ago' => $yearsAgo,
        'date' => $date,
        'count' => count($rows),
        'cover_asset_id' => $cover,
        'asset_ids' => array_column($rows, 'id'),
        'assets' => array_map(function ($r) {
            unset($r['ly']);
            return asset_to_json($r);
        }, $rows),
    ];
}
