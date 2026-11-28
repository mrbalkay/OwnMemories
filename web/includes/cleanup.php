<?php
/**
 * OwnMemories — Temizlik: benzer/yinelenen fotoğraflar, bulanık/karanlık kareler, ekran görüntüleri, kısa videolar.
 *
 * Birebir aynı dosyalar yüklemede SHA-1 ile zaten engellenir; burada YAKIN kopyalar ve gereksiz kareler bulunur.
 *   - Benzer gruplar: ownmemories-ml POST /cleanup/groups (CLIP vektörleri + çekim zamanı + algısal özet; bkz.
 *     ml/ownmemories_ml/cleanup.py). Grup içinde tutulacak "en iyi" kare burada seçilir (cleanup_pick_best).
 *   - Kalite listeleri: asset_quality (ML dizinleyicisinin yazdığı netlik/parlaklık) + meta veri kuralları.
 *   - "Bunlar farklı" / "Bu iyi": cleanup_dismissed; gizlenen öneri bir daha gelmez (POST /v1/cleanup/reset geri getirir).
 *   - Uygulama yalnızca ÇÖP KUTUSUNA taşır (30 gün sonra kendiliğinden silinir); asla kalıcı silmez.
 */
declare(strict_types=1);

const CLEANUP_BLUR_MAX = 25.0;      // asset_quality.sharpness bunun altındaysa bulanık (bkz. cleanup.py ölçümü)
const CLEANUP_BLUR_MIN_EDGE = 256;  // daha küçük görüntülerde netlik ölçümü güvenilmez
const CLEANUP_DARK_MAX = 22.0;      // ortalama parlaklık (0..255) bunun altında: çok karanlık
const CLEANUP_BRIGHT_MIN = 236.0;   // bunun üstünde: aşırı pozlanmış
const CLEANUP_SHORT_VIDEO_MS = 1000;
const CLEANUP_LIST_MAX = 300;       // liste başına dönen en fazla öğe (sayılar stats'ta tam)
const CLEANUP_ML_TIMEOUT = 30.0;
const CLEANUP_KINDS = ['group', 'blurry', 'dark', 'screenshot', 'video'];

/** Ekran boyutları (dikey); yatay hali de sayılır. ml/ownmemories_ml/cleanup.py SCREEN_SIZES ile aynı. */
const CLEANUP_SCREEN_SIZES = [
    [640, 1136], [750, 1334], [1242, 2208], [1080, 1920], [1125, 2436], [828, 1792], [1242, 2688],
    [1080, 2340], [1170, 2532], [1284, 2778], [1179, 2556], [1290, 2796], [1206, 2622], [1320, 2868],
    [1536, 2048], [1668, 2224], [1668, 2388], [1620, 2160], [1640, 2360], [2048, 2732], [1488, 2266],
    [1080, 2400], [1440, 3200], [1440, 3120], [1080, 2280], [720, 1600], [1440, 2960], [1080, 2408],
];

/** Ekran görüntüsü kuralı (dosya adı / PNG ve kamera bilgisi yok / ekran boyutu). cleanup.py is_screenshot ile aynı. */
function cleanup_is_screenshot(array $a): bool
{
    $name = trim((string) ($a['original_filename'] ?? ''));
    if (preg_match('/(screenshot|screen[ _-]?shot|ekran[ _-]?(resmi|görüntüsü|goruntusu|kaydı)|^scr_|^screen_|simulator screen)/iu', $name)) {
        return true;
    }
    if (!empty($a['camera_make'])) {
        return false;
    }
    if (strtolower((string) ($a['mime'] ?? '')) === 'image/png') {
        return true;
    }
    $w = (int) ($a['width'] ?? 0);
    $h = (int) ($a['height'] ?? 0);
    return in_array([min($w, $h), max($w, $h)], CLEANUP_SCREEN_SIZES, true);
}

/** Dosya adı düzenlenmiş/çoğaltılmış kopyayı andırıyor mu ("IMG_1 (1).jpg", "-edited", "kopya"). */
function cleanup_looks_copy(string $name): bool
{
    return (bool) preg_match('/(\(\d+\)|[-_ ](edited|edit|copy|kopya|kopyası|düzenlenmiş))\.[a-z0-9]+$/iu', $name);
}

/** Grup anahtarı: sıralı üye kimliklerinin SHA-1'i. */
function cleanup_group_key(array $ids): string
{
    $ids = array_values(array_unique(array_map('strval', $ids)));
    sort($ids);
    return sha1(implode(',', $ids));
}

/**
 * Grupta tutulacak en iyi kare: favori > çözünürlük > netlik > konum > kamera bilgisi > dosya boyutu; düzenlenmiş
 * kopya ve kopya adı geride kalır. $rows: assets satırları + q_sharpness. Dönüş: [keep_id, gerekçeler].
 */
function cleanup_pick_best(array $rows): array
{
    $maxPx = max(1, ...array_map(fn($r) => (int) $r['width'] * (int) $r['height'], $rows));
    $maxSharp = max(1.0, ...array_map(fn($r) => (float) ($r['q_sharpness'] ?? 0), $rows));
    $maxSize = max(1, ...array_map(fn($r) => (int) $r['size'], $rows));
    $best = null;
    $bestScore = -INF;
    foreach ($rows as $r) {
        $px = (int) $r['width'] * (int) $r['height'];
        $sharp = $r['q_sharpness'] !== null ? (float) $r['q_sharpness'] : $maxSharp * 0.5;
        $score = ((int) $r['is_favorite'] ? 1000 : 0)
            + 100 * $px / $maxPx
            + 60 * min(1.0, $sharp / $maxSharp)
            + ($r['latitude'] !== null ? 12 : 0)
            + (!empty($r['camera_make']) ? 8 : 0)
            + 10 * (int) $r['size'] / $maxSize
            - ($r['edited_of_id'] !== null ? 25 : 0)
            - (cleanup_looks_copy((string) $r['original_filename']) ? 25 : 0);
        if ($score > $bestScore + 1e-9 || ($best !== null && abs($score - $bestScore) <= 1e-9 && strcmp($r['taken_at'] . $r['id'], $best['taken_at'] . $best['id']) < 0)) {
            $best = $r;
            $bestScore = $score;
        }
    }
    $reasons = [];
    $others = array_filter($rows, fn($r) => $r['id'] !== $best['id']);
    if ((int) $best['is_favorite'] && !array_filter($others, fn($r) => (int) $r['is_favorite'])) {
        $reasons[] = 'favorite';
    }
    $bpx = (int) $best['width'] * (int) $best['height'];
    $opx = array_map(fn($r) => (int) $r['width'] * (int) $r['height'], $others);
    if ($opx && $bpx >= max($opx) && $bpx > min($opx)) {
        $reasons[] = 'resolution';
    }
    if ($best['q_sharpness'] !== null && $others && (float) $best['q_sharpness'] >= max(array_map(fn($r) => (float) ($r['q_sharpness'] ?? 0), $others))) {
        $reasons[] = 'sharpest';
    }
    if ($best['latitude'] !== null && array_filter($others, fn($r) => $r['latitude'] === null)) {
        $reasons[] = 'location';
    }
    if (!$reasons && ($best['edited_of_id'] === null && !cleanup_looks_copy((string) $best['original_filename']))) {
        $reasons[] = 'original';
    }
    return [$best['id'], $reasons];
}

/** Kullanıcının gizlediği öneriler: ['group' => [[ids…], …], 'blurry' => [id => true], …]. */
function cleanup_dismissed(int $uid): array
{
    $out = array_fill_keys(CLEANUP_KINDS, []);
    foreach (db_all('SELECT kind, item_key, asset_ids FROM cleanup_dismissed WHERE user_id = ?', [$uid]) as $r) {
        if ($r['kind'] === 'group') {
            $out['group'][] = array_flip(explode(',', (string) $r['asset_ids']));
        } elseif (isset($out[$r['kind']])) {
            $out[$r['kind']][$r['item_key']] = true;
        }
    }
    return $out;
}

function cleanup_dismiss_group(int $uid, array $ids): bool
{
    $ids = array_values(array_unique($ids));
    sort($ids);
    if (count($ids) < 2) {
        return false;
    }
    db_exec('INSERT IGNORE INTO cleanup_dismissed (user_id, kind, item_key, asset_ids, created_at) VALUES (?, ?, ?, ?, ?)', [$uid, 'group', cleanup_group_key($ids), implode(',', $ids), now_utc()]);
    return true;
}

/** Asset JSON'u + kalite bilgisi. */
function cleanup_asset_json(array $r): array
{
    $j = asset_to_json($r);
    $j['quality'] = [
        'sharpness' => $r['q_sharpness'] !== null ? round((float) $r['q_sharpness'], 2) : null,
        'brightness' => $r['q_brightness'] !== null ? round((float) $r['q_brightness'], 2) : null,
    ];
    return $j;
}

/** Görünür (zaman çizelgesindeki) öğeler için temel koşul. */
function cleanup_visible_sql(): string
{
    return "a.user_id = ? AND a.trashed_at IS NULL AND a.is_archived = 0
        AND NOT (a.type = 'video' AND a.live_pair_id IS NOT NULL AND EXISTS (SELECT 1 FROM assets lp WHERE lp.id = a.live_pair_id AND lp.type = 'photo' AND lp.trashed_at IS NULL))
        AND NOT EXISTS (SELECT 1 FROM assets ed WHERE ed.edited_of_id = a.id AND ed.trashed_at IS NULL)";
}

/** ML'den gelen grupları doğrular, süzer ve en iyiyi seçer. */
function cleanup_groups(int $uid, ?array $mlGroups, array $dismissed): array
{
    $want = [];
    foreach ($mlGroups ?? [] as $g) {
        if (is_array($g) && is_array($g['assets'] ?? null)) {
            foreach ($g['assets'] as $id) {
                if (is_string($id) && is_uuid($id)) {
                    $want[$id] = true;
                }
            }
        }
    }
    $rows = [];
    foreach (array_chunk(array_keys($want), 500) as $chunk) {
        foreach (db_all('SELECT a.*, q.sharpness AS q_sharpness, q.brightness AS q_brightness FROM assets a
                LEFT JOIN asset_quality q ON q.asset_id = a.id
                WHERE ' . cleanup_visible_sql() . " AND a.type = 'photo' AND a.id IN (" . db_in($chunk) . ')', array_merge([$uid], $chunk)) as $r) {
            $rows[$r['id']] = $r;
        }
    }
    $out = [];
    $seen = [];
    foreach ($mlGroups ?? [] as $g) {
        if (!is_array($g) || !is_array($g['assets'] ?? null)) {
            continue;
        }
        $members = [];
        foreach ($g['assets'] as $id) {
            if (is_string($id) && isset($rows[$id]) && !isset($seen[$id])) {
                $members[$id] = $rows[$id];
            }
        }
        if (count($members) < 2) {
            continue;
        }
        $hidden = false;
        foreach ($dismissed['group'] as $set) {
            if (!array_diff_key($members, $set)) {
                $hidden = true;
                break;
            }
        }
        if ($hidden) {
            continue;
        }
        foreach ($members as $id => $_) {
            $seen[$id] = true;
        }
        usort($members, fn($a, $b) => [$a['taken_at'], $a['id']] <=> [$b['taken_at'], $b['id']]);
        [$keep, $reasons] = cleanup_pick_best($members);
        $bytes = 0;
        foreach ($members as $m) {
            if ($m['id'] !== $keep && !(int) $m['is_favorite']) { // favoriler varsayılan olarak silinmez
                $bytes += (int) $m['size'];
            }
        }
        $out[] = [
            'id' => substr(cleanup_group_key(array_column($members, 'id')), 0, 16),
            'reason' => ($g['reason'] ?? '') === 'duplicate' ? 'duplicate' : 'similar',
            'keep_id' => $keep,
            'keep_reasons' => $reasons,
            'bytes' => $bytes,
            'taken_at' => iso_utc($members[0]['taken_at']),
            'assets' => array_map('cleanup_asset_json', $members),
        ];
    }
    // En çok yer kazandıran gruplar önce.
    usort($out, fn($a, $b) => [$b['bytes'], $a['id']] <=> [$a['bytes'], $b['id']]);
    return $out;
}

/** GET /v1/cleanup */
function api_cleanup(array $user): array
{
    $uid = (int) $user['id'];
    $ml = ml_request('POST', '/cleanup/groups', ['user_id' => $uid], CLEANUP_ML_TIMEOUT);
    $mlOk = $ml !== null && is_array($ml['groups'] ?? null);
    $dismissed = cleanup_dismissed($uid);
    $groups = cleanup_groups($uid, $mlOk ? $ml['groups'] : [], $dismissed);
    $inGroup = [];
    foreach ($groups as $g) {
        foreach ($g['assets'] as $a) {
            $inGroup[$a['id']] = true;
        }
    }

    $lists = ['blurry' => [], 'dark' => [], 'screenshots' => [], 'short_videos' => []];
    $bytes = array_fill_keys(array_keys($lists), 0);
    $count = array_fill_keys(array_keys($lists), 0);
    $add = function (string $list, array $r, ?string $why = null) use (&$lists, &$bytes, &$count) {
        $count[$list]++;
        $bytes[$list] += (int) $r['size'];
        if (count($lists[$list]) < CLEANUP_LIST_MAX) {
            $j = cleanup_asset_json($r);
            if ($why !== null) {
                $j['why'] = $why;
            }
            $lists[$list][] = $j;
        }
    };
    // Aday satırlar: ekran görüntüsü kuralına uyanlar, kalite eşiklerini aşanlar, kısa videolar.
    $rows = db_all('SELECT a.*, q.sharpness AS q_sharpness, q.brightness AS q_brightness, q.is_screenshot AS q_shot FROM assets a
        LEFT JOIN asset_quality q ON q.asset_id = a.id
        WHERE ' . cleanup_visible_sql() . " AND a.is_favorite = 0 AND (
            q.is_screenshot = 1 OR q.sharpness < ? OR q.brightness < ? OR q.brightness > ?
            OR (a.type = 'video' AND a.duration_ms IS NOT NULL AND a.duration_ms < ?)
            OR (q.asset_id IS NULL AND a.type = 'photo' AND (a.mime = 'image/png' OR a.camera_make IS NULL OR a.original_filename LIKE '%creen%' OR a.original_filename LIKE '%kran%')))
        ORDER BY a.taken_at DESC, a.id", [$uid, CLEANUP_BLUR_MAX, CLEANUP_DARK_MAX, CLEANUP_BRIGHT_MIN, CLEANUP_SHORT_VIDEO_MS]);
    foreach ($rows as $r) {
        $id = $r['id'];
        if (isset($inGroup[$id])) {
            continue;
        }
        if ($r['type'] === 'video') {
            if ($r['duration_ms'] !== null && (int) $r['duration_ms'] < CLEANUP_SHORT_VIDEO_MS && $r['live_pair_id'] === null) {
                if (!isset($dismissed['video'][$id])) {
                    $add('short_videos', $r);
                }
            } elseif ($r['q_brightness'] !== null && (float) $r['q_brightness'] < CLEANUP_DARK_MAX && !isset($dismissed['dark'][$id])) {
                $add('dark', $r, 'dark'); // cepte kayıt: kapak karesi simsiyah
            }
            continue;
        }
        // Ölçülmüş öğede ML'in kararı (aynı kural), henüz ölçülmemişte PHP kuralı.
        if ($r['q_shot'] !== null ? (int) $r['q_shot'] === 1 : cleanup_is_screenshot($r)) {
            if (!isset($dismissed['screenshot'][$id])) {
                $add('screenshots', $r);
            }
            continue;
        }
        if ($r['q_brightness'] !== null && ((float) $r['q_brightness'] < CLEANUP_DARK_MAX || (float) $r['q_brightness'] > CLEANUP_BRIGHT_MIN)) {
            if (!isset($dismissed['dark'][$id])) {
                $add('dark', $r, (float) $r['q_brightness'] < CLEANUP_DARK_MAX ? 'dark' : 'bright');
            }
            continue;
        }
        if ($r['q_sharpness'] !== null && (float) $r['q_sharpness'] < CLEANUP_BLUR_MAX
            && min((int) $r['width'], (int) $r['height']) >= CLEANUP_BLUR_MIN_EDGE && !isset($dismissed['blurry'][$id])) {
            $add('blurry', $r);
        }
    }

    $analysis = db_row("SELECT COUNT(*) AS total, COUNT(q.asset_id) AS analyzed FROM assets a LEFT JOIN asset_quality q ON q.asset_id = a.id
        WHERE a.user_id = ? AND a.trashed_at IS NULL AND a.thumb_state IN ('ready', 'pending') AND NOT (a.type = 'video' AND a.live_pair_id IS NOT NULL)", [$uid]);
    $potential = array_sum(array_column($groups, 'bytes'));
    return api_ok([
        'ml' => ['available' => $mlOk, 'indexed' => $mlOk ? (int) ($ml['indexed'] ?? 0) : null],
        'analysis' => ['analyzed' => (int) $analysis['analyzed'], 'total' => (int) $analysis['total']],
        'groups' => $groups,
        'blurry' => $lists['blurry'],
        'dark' => $lists['dark'],
        'screenshots' => $lists['screenshots'],
        'short_videos' => $lists['short_videos'],
        'stats' => [
            'potential_bytes' => $potential,
            'groups' => count($groups),
            'group_assets' => count($inGroup),
            'blurry' => $count['blurry'], 'blurry_bytes' => $bytes['blurry'],
            'dark' => $count['dark'], 'dark_bytes' => $bytes['dark'],
            'screenshots' => $count['screenshots'], 'screenshots_bytes' => $bytes['screenshots'],
            'short_videos' => $count['short_videos'], 'short_videos_bytes' => $bytes['short_videos'],
        ],
    ]);
}

/** Gövdedeki kimlik listesini doğrular (yalnızca uuid; tekrarsız). */
function cleanup_ids($v, string $field): array
{
    if ($v === null) {
        return [];
    }
    if (!is_array($v)) {
        throw new ValidationError('Kimlik listesi bekleniyor.', $field);
    }
    return array_values(array_unique(array_filter(array_map('strval', $v), 'is_uuid')));
}

/** POST /v1/cleanup/resolve {keep, trash} | {groups: [{keep, trash}, …]} — çöpe taşır, tutulanları hatırlar. */
function api_cleanup_resolve(array $user, array $b): array
{
    $uid = (int) $user['id'];
    $parts = isset($b['groups']) && is_array($b['groups']) ? $b['groups'] : [['keep' => $b['keep'] ?? [], 'trash' => $b['trash'] ?? []]];
    $total = 0;
    $plan = [];
    foreach ($parts as $p) {
        if (!is_array($p)) {
            throw new ValidationError('Geçersiz grup.', 'groups');
        }
        $keep = cleanup_ids($p['keep'] ?? [], 'keep');
        $trash = cleanup_ids($p['trash'] ?? [], 'trash');
        $total += count($keep) + count($trash) + (is_array($p['trash'] ?? null) ? count($p['trash']) - count($trash) : 0);
        if (array_intersect($keep, $trash)) {
            throw new ValidationError('Aynı öğe hem tutulup hem silinemez.', 'trash');
        }
        $plan[] = [$keep, $trash];
    }
    if ($total > 1000) {
        throw new ValidationError('En fazla 1000 öğe.', 'trash');
    }
    // Bir grupta tutulan kare başka bir grupta çöpe gönderilemez.
    $allKeep = array_merge([], ...array_column($plan, 0));
    $allTrash = array_merge([], ...array_column($plan, 1));
    if (array_intersect($allKeep, $allTrash)) {
        throw new ValidationError('Aynı öğe hem tutulup hem silinemez.', 'trash');
    }
    $trashed = 0;
    $freed = 0;
    $skipped = 0;
    foreach ($plan as [$keep, $trash]) {
        // Eski sayfa/başka cihaz: tutulacak karelerin hiçbiri artık yerinde değilse (çöpte/silinmiş) grubun geri
        // kalanı da çöpe gitmesin; aksi halde o anın tek kopyası bile kalmaz. (keep boş = liste; denetim yok.)
        if ($keep && $trash && !db_val('SELECT 1 FROM assets WHERE user_id = ? AND trashed_at IS NULL AND id IN (' . db_in($keep) . ') LIMIT 1', array_merge([$uid], $keep))) {
            $skipped++;
            continue;
        }
        $ids = asset_owned_ids($uid, $trash);
        if ($ids) {
            $live = array_column(db_all("SELECT id FROM assets WHERE user_id = ? AND type = 'video' AND trashed_at IS NULL AND id IN (SELECT live_pair_id FROM assets WHERE user_id = ? AND type = 'photo' AND live_pair_id IS NOT NULL AND id IN (" . db_in($ids) . '))', array_merge([$uid, $uid], $ids)), 'id');
            $freed += (int) db_val('SELECT COALESCE(SUM(size), 0) FROM assets WHERE user_id = ? AND trashed_at IS NULL AND id IN (' . db_in($ids) . ')', array_merge([$uid], $ids));
            $trashed += asset_trash($uid, $ids);
            if ($live) {
                asset_trash($uid, $live);
            }
        }
        $kept = asset_owned_ids($uid, $keep);
        if (count($kept) >= 2) {
            cleanup_dismiss_group($uid, $kept);
        }
    }
    audit($uid, 'cleanup.resolve', 'çöpe: ' . $trashed . ($skipped ? ', atlanan grup: ' . $skipped : ''));
    return api_ok(['trashed' => $trashed, 'freed_bytes' => $freed, 'skipped' => $skipped]);
}

/** POST /v1/cleanup/dismiss {kind, ids} */
function api_cleanup_dismiss(array $user, array $b): array
{
    $uid = (int) $user['id'];
    $kind = (string) ($b['kind'] ?? '');
    if (!in_array($kind, CLEANUP_KINDS, true)) {
        throw new ValidationError('Geçersiz tür.', 'kind');
    }
    $ids = asset_owned_ids($uid, cleanup_ids($b['ids'] ?? [], 'ids'));
    if (count($ids) > 1000) {
        throw new ValidationError('En fazla 1000 öğe.', 'ids');
    }
    if ($kind === 'group') {
        if (!cleanup_dismiss_group($uid, $ids)) {
            throw new ValidationError('Grup en az iki öğe içermeli.', 'ids');
        }
        return api_ok(['dismissed' => 1]);
    }
    $n = 0;
    foreach ($ids as $id) {
        $n += db_exec('INSERT IGNORE INTO cleanup_dismissed (user_id, kind, item_key, asset_ids, created_at) VALUES (?, ?, ?, NULL, ?)', [$uid, $kind, $id, now_utc()]);
    }
    return api_ok(['dismissed' => $n]);
}

/** POST /v1/cleanup/reset — gizlenen tüm öneriler geri gelir. */
function api_cleanup_reset(array $user): array
{
    return api_ok(['restored' => db_exec('DELETE FROM cleanup_dismissed WHERE user_id = ?', [(int) $user['id']])]);
}

/** Kalıcı silmede kalite satırını siler (tablo henüz yoksa sessizce geçer). */
function cleanup_purge_asset(string $id): void
{
    try {
        db_exec('DELETE FROM asset_quality WHERE asset_id = ?', [$id]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) !== 1146) {
            throw $e;
        }
    }
}
