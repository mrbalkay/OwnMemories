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
    $local = memories_local_expr();
    $base = memories_base_where();

    // 1) X yıl önce bugün
    $rows = db_all("SELECT a.*, YEAR($local) AS ly FROM assets a WHERE $base AND MONTH($local) = ? AND DAY($local) = ? AND YEAR($local) < ?
        ORDER BY a.taken_at, a.id", [$userId, $mo, $d, $y]);
    $out = [];
    foreach (memories_group_by_year($rows) as $year => $assets) {
        $out[] = memories_make('on_this_day', $y - $year, $assets, sprintf('%04d-%02d-%02d', $year, $mo, $d));
    }

    // 2) Bugün yoksa: X yıl önce bu hafta (±3 gün)
    if (!$out) {
        $minYear = (int) (db_val("SELECT MIN(YEAR($local)) FROM assets a WHERE $base", [$userId]) ?: $y);
        $conds = [];
        $args = [$userId];
        for ($yy = $minYear; $yy < $y; $yy++) {
            $center = DateTimeImmutable::createFromFormat('!Y-n-j', "$yy-$mo-$d", new DateTimeZone('UTC'));
            if ($center === false) {
                continue;
            }
            $conds[] = "($local >= ? AND $local < ?)";
            $args[] = $center->modify('-3 days')->format('Y-m-d 00:00:00');
            $args[] = $center->modify('+4 days')->format('Y-m-d 00:00:00');
        }
        if ($conds) {
            $rows = db_all("SELECT a.*, YEAR($local) AS ly FROM assets a WHERE $base AND (" . implode(' OR ', $conds) . ') ORDER BY a.taken_at, a.id', $args);
            foreach (memories_group_by_year($rows) as $year => $assets) {
                $out[] = memories_make('this_week', $y - $year, $assets, sprintf('%04d-%02d-%02d', $year, $mo, $d));
            }
        }
    }
    usort($out, fn($a, $b) => $a['years_ago'] <=> $b['years_ago']);

    // 3) Favorilerinizden (en az 1 yıl önceki favoriler; gün başına sabit rastgele seçim)
    $seed = crc32($userId . ':' . $date) & 0x7FFFFFFF;
    $fav = db_all("SELECT a.* FROM assets a WHERE $base AND a.is_favorite = 1 AND YEAR($local) < ? ORDER BY RAND($seed) LIMIT 30", [$userId, $y]);
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
