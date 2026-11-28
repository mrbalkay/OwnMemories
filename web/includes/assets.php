<?php
/**
 * OwnMemories — asset oluşturma, yineleme kontrolü, çöp kutusu, kalıcı silme.
 */
declare(strict_types=1);

const OM_TRASH_DAYS = 30;

/** Kullanıcının senkron sürümünü artırır ve yeni değeri döner. */
function asset_bump_version(int $userId): int
{
    db_exec('UPDATE users SET sync_version = LAST_INSERT_ID(sync_version + 1) WHERE id = ?', [$userId]);
    return (int) db()->query('SELECT LAST_INSERT_ID()')->fetchColumn();
}

/**
 * Bir dosyayı kullanıcının kütüphanesine ekler.
 * $in: filename, source (takeout|ios|web), source_ref?, sha1?, taken_at_utc?, tz_offset?, date_source?,
 *      lat?, lng?, alt?, description?, is_favorite?, is_archived?, shared_from_others?, move?, probe?
 * @return array{status:string, asset_id:?string}  status: created | duplicate | deleted_before
 */
function asset_ingest(int $userId, string $srcPath, array $in): array
{
    $filename = (string) ($in['filename'] ?? basename($srcPath));
    $move = !empty($in['move']);
    $kind = media_kind($srcPath, $filename);
    if ($kind === null) {
        throw new ValidationError('Desteklenmeyen dosya türü: ' . $filename, 'file');
    }
    $sha1 = (string) ($in['sha1'] ?? sha1_file($srcPath));

    if (db_val('SELECT 1 FROM deleted_checksums WHERE user_id = ? AND sha1 = ?', [$userId, $sha1]) !== null) {
        return ['status' => 'deleted_before', 'asset_id' => null];
    }
    $existing = db_val('SELECT id FROM assets WHERE user_id = ? AND blob_sha1 = ?', [$userId, $sha1]);
    if ($existing !== null) {
        asset_link_source_ref($userId, (string) $existing, $in);
        return ['status' => 'duplicate', 'asset_id' => (string) $existing];
    }

    $probe = $in['probe'] ?? media_probe($srcPath, $kind, $filename);
    if (!empty($in['taken_at_utc'])) {
        $time = ['taken_at_utc' => (string) $in['taken_at_utc'], 'tz_offset' => $in['tz_offset'] ?? null, 'date_source' => (string) ($in['date_source'] ?? 'takeout')];
        $time['tz_offset'] ??= tz_offset_at_utc((int) strtotime($time['taken_at_utc'] . ' UTC'));
    } else {
        $time = capture_time_resolve(null, $probe, $filename, @filemtime($srcPath) ?: null);
    }

    // İkincil yineleme kontrolü: aynı çekim anı + aynı boyut + aynı dosya adı + benzer dosya büyüklüğü.
    // Yalnız güvenilir tarihlerde (EXIF / Google) uygulanır; dosya zamanı veya addan gelen tarih zayıftır.
    $sizeNow = (int) filesize($srcPath);
    if ($probe['width'] !== null && in_array($time['date_source'], ['exif', 'takeout'], true)) {
        $dupe = db_val("SELECT id FROM assets WHERE user_id = ? AND taken_at = ? AND width = ? AND height = ? AND LOWER(original_filename) = ?
                AND date_source IN ('exif','takeout') AND size BETWEEN ? AND ? LIMIT 1", [
            $userId, $time['taken_at_utc'], $probe['width'], $probe['height'], mb_strtolower($filename), (int) floor($sizeNow * 0.9), (int) ceil($sizeNow * 1.1),
        ]);
        if ($dupe !== null) {
            asset_link_source_ref($userId, (string) $dupe, $in);
            if ($move) {
                @unlink($srcPath);
            }
            return ['status' => 'duplicate', 'asset_id' => (string) $dupe];
        }
    }

    $size = (int) filesize($srcPath);
    $user = db_row('SELECT quota_bytes, used_bytes FROM users WHERE id = ?', [$userId]);
    if ($user === null) {
        throw new ValidationError('Kullanıcı yok', 'user');
    }
    if ($user['quota_bytes'] !== null && (int) $user['used_bytes'] + $size > (int) $user['quota_bytes']) {
        throw new ValidationError('quota_exceeded', 'quota');
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    blob_put($srcPath, $sha1, $ext, $move);
    $id = uuid4();
    $lat = $in['lat'] ?? $probe['lat'];
    $lng = $in['lng'] ?? $probe['lng'];
    $pdo = db();
    if (isset($GLOBALS['__om_before_asset_insert'])) {
        ($GLOBALS['__om_before_asset_insert'])(); // yalnız testler: eşzamanlı yüklemeyi taklit eder
    }
    try {
        $pdo->beginTransaction();
        $version = asset_bump_version($userId);
        $now = now_utc();
        db_exec('INSERT INTO assets (id, user_id, blob_sha1, type, original_filename, mime, size, width, height, duration_ms,
                taken_at, taken_tz_offset, date_source, latitude, longitude, altitude, camera_make, camera_model, lens, description,
                is_favorite, is_archived, shared_from_others, source, source_ref, thumb_state, created_at, updated_at, version)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $id, $userId, $sha1, $kind, mb_substr($filename, 0, 255), media_mime($srcPath, $filename) ?: $probe['mime'], $size,
            $probe['width'], $probe['height'], $probe['duration_ms'],
            $time['taken_at_utc'], $time['tz_offset'], $time['date_source'],
            $lat !== null ? round((float) $lat, 6) : null, $lng !== null ? round((float) $lng, 6) : null,
            ($in['alt'] ?? $probe['alt']) !== null ? round((float) ($in['alt'] ?? $probe['alt']), 2) : null,
            $probe['make'] !== null ? mb_substr($probe['make'], 0, 80) : null,
            $probe['model'] !== null ? mb_substr($probe['model'], 0, 120) : null,
            $probe['lens'] !== null ? mb_substr($probe['lens'], 0, 120) : null,
            $in['description'] ?? $probe['description'],
            !empty($in['is_favorite']) ? 1 : 0, !empty($in['is_archived']) ? 1 : 0, !empty($in['shared_from_others']) ? 1 : 0,
            $in['source'] ?? 'web', isset($in['source_ref']) ? mb_substr((string) $in['source_ref'], 0, 512) : null,
            'pending', $now, $now, $version,
        ]);
        db_exec('UPDATE users SET used_bytes = used_bytes + ? WHERE id = ?', [$size, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        blob_release($sha1);
        // Aynı dosya aynı anda başka bir istekle eklendiyse (benzersiz anahtar çakışması) yinelenen say.
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
            $existing = db_val('SELECT id FROM assets WHERE user_id = ? AND blob_sha1 = ?', [$userId, $sha1]);
            if ($existing !== null) {
                return ['status' => 'duplicate', 'asset_id' => (string) $existing];
            }
        }
        throw $e;
    }
    job_enqueue('render', ['asset_id' => $id], $in['source'] === 'takeout' ? 7 : 3);
    if ($lat !== null && $lng !== null && function_exists('places_enqueue_asset')) {
        places_enqueue_asset($id);
    }
    return ['status' => 'created', 'asset_id' => $id];
}

/** Yinelenen bir yüklemede iOS localIdentifier'ı mevcut asset'e bağlar (boşsa). */
function asset_link_source_ref(int $userId, string $assetId, array $in): void
{
    if (($in['source'] ?? '') === 'ios' && !empty($in['source_ref'])) {
        db_exec("UPDATE assets SET source_ref = ? WHERE id = ? AND user_id = ? AND (source_ref IS NULL OR source <> 'ios')", [mb_substr((string) $in['source_ref'], 0, 512), $assetId, $userId]);
    }
}

/** Sahibine ait asset id'lerini süzer. */
function asset_owned_ids(int $userId, array $ids): array
{
    $ids = array_values(array_filter(array_map('strval', $ids), 'is_uuid'));
    if (!$ids) {
        return [];
    }
    return array_column(db_all('SELECT id FROM assets WHERE user_id = ? AND id IN (' . db_in($ids) . ')', array_merge([$userId], $ids)), 'id');
}

function asset_trash(int $userId, array $ids): int
{
    $ids = asset_owned_ids($userId, $ids);
    if (!$ids) {
        return 0;
    }
    $v = asset_bump_version($userId);
    return db_exec('UPDATE assets SET trashed_at = ?, updated_at = ?, version = ? WHERE user_id = ? AND trashed_at IS NULL AND id IN (' . db_in($ids) . ')', array_merge([now_utc(), now_utc(), $v, $userId], $ids));
}

function asset_restore(int $userId, array $ids): int
{
    $ids = asset_owned_ids($userId, $ids);
    if (!$ids) {
        return 0;
    }
    $v = asset_bump_version($userId);
    return db_exec('UPDATE assets SET trashed_at = NULL, updated_at = ?, version = ? WHERE user_id = ? AND trashed_at IS NOT NULL AND id IN (' . db_in($ids) . ')', array_merge([now_utc(), $v, $userId], $ids));
}

/** Kalıcı siler: dosyalar, albüm üyelikleri; SHA-1 deleted_checksums'a yazılır. */
function asset_purge(int $userId, array $ids): int
{
    $ids = asset_owned_ids($userId, $ids);
    $n = 0;
    foreach ($ids as $id) {
        $a = db_row('SELECT id, blob_sha1, size FROM assets WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($a === null) {
            continue;
        }
        $pdo = db();
        $pdo->beginTransaction();
        db_exec('DELETE FROM assets WHERE id = ?', [$id]);
        db_exec('DELETE FROM album_assets WHERE asset_id = ?', [$id]);
        db_exec('DELETE FROM asset_people WHERE asset_id = ?', [$id]);
        people_purge_asset($id);
        asset_embedding_delete($id);
        asset_place_delete($id);
        cleanup_purge_asset($id);
        ocr_purge_asset($id);
        db_exec('UPDATE assets SET live_pair_id = NULL WHERE live_pair_id = ?', [$id]);
        db_exec('UPDATE assets SET edited_of_id = NULL WHERE edited_of_id = ?', [$id]);
        db_exec('UPDATE albums SET cover_asset_id = NULL WHERE cover_asset_id = ?', [$id]);
        db_exec('INSERT IGNORE INTO deleted_checksums (user_id, sha1, deleted_at) VALUES (?, ?, ?)', [$userId, $a['blob_sha1'], now_utc()]);
        db_exec('UPDATE users SET used_bytes = GREATEST(used_bytes, ?) - ? WHERE id = ?', [(int) $a['size'], (int) $a['size'], $userId]);
        $pdo->commit();
        derived_delete($id);
        blob_release($a['blob_sha1']);
        $n++;
    }
    if ($n > 0) {
        asset_bump_version($userId);
    }
    return $n;
}

/** Akıllı arama vektörünü siler (asset_embeddings henüz kurulmadıysa sessizce geçer). */
function asset_embedding_delete(string $id): void
{
    try {
        db_exec('DELETE FROM asset_embeddings WHERE asset_id = ?', [$id]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) !== 1146) { // ER_NO_SUCH_TABLE
            throw $e;
        }
    }
}

/** Yer satırını siler (asset_places henüz kurulmadıysa sessizce geçer). */
function asset_place_delete(string $id): void
{
    try {
        db_exec('DELETE FROM asset_places WHERE asset_id = ?', [$id]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) !== 1146) {
            throw $e;
        }
    }
    if (function_exists('places_cache_forget')) {
        places_cache_forget($id);
    }
}

/** Çöpte $days günden uzun duranları kalıcı siler. */
function trash_purge_expired(int $days = OM_TRASH_DAYS): int
{
    $cut = gmdate('Y-m-d H:i:s', time() - $days * 86400);
    $n = 0;
    foreach (db_all('SELECT user_id, id FROM assets WHERE trashed_at IS NOT NULL AND trashed_at < ? LIMIT 5000', [$cut]) as $r) {
        $n += asset_purge((int) $r['user_id'], [$r['id']]);
    }
    return $n;
}

function iso_utc(?string $dt): ?string
{
    return $dt === null ? null : str_replace(' ', 'T', $dt) . 'Z';
}

/** API nesnesi (docs/ARCHITECTURE.md §Asset). */
function asset_to_json(array $a): array
{
    $off = $a['taken_tz_offset'] !== null ? (int) $a['taken_tz_offset'] : null;
    $local = null;
    if ($off !== null) {
        $ts = (int) strtotime($a['taken_at'] . ' UTC') + $off * 60;
        $local = gmdate('Y-m-d\TH:i:s', $ts) . sprintf('%s%02d:%02d', $off < 0 ? '-' : '+', intdiv(abs($off), 60), abs($off) % 60);
    }
    return [
        'id' => $a['id'],
        'type' => $a['type'],
        'filename' => $a['original_filename'],
        'mime' => $a['mime'],
        'size' => (int) $a['size'],
        'width' => $a['width'] !== null ? (int) $a['width'] : null,
        'height' => $a['height'] !== null ? (int) $a['height'] : null,
        'duration_ms' => $a['duration_ms'] !== null ? (int) $a['duration_ms'] : null,
        'taken_at' => iso_utc($a['taken_at']),
        'taken_local' => $local,
        'tz_offset' => $off,
        'date_source' => $a['date_source'],
        'latitude' => $a['latitude'] !== null ? (float) $a['latitude'] : null,
        'longitude' => $a['longitude'] !== null ? (float) $a['longitude'] : null,
        'altitude' => $a['altitude'] !== null ? (float) $a['altitude'] : null,
        'place' => $a['latitude'] !== null && function_exists('place_json') ? place_json(asset_place_row((string) $a['id']), $a) : null,
        'camera_make' => $a['camera_make'],
        'camera_model' => $a['camera_model'],
        'lens' => $a['lens'],
        'description' => $a['description'],
        'is_favorite' => (bool) $a['is_favorite'],
        'is_archived' => (bool) $a['is_archived'],
        'shared_from_others' => (bool) $a['shared_from_others'],
        'trashed_at' => iso_utc($a['trashed_at']),
        'source' => $a['source'],
        'live_pair_id' => $a['live_pair_id'],
        'edited_of_id' => $a['edited_of_id'],
        'thumb_state' => $a['thumb_state'],
        'web_video' => $a['type'] === 'video' && derived_find_video($a['id']) !== null,
        'updated_at' => iso_utc($a['updated_at']),
        'version' => (int) $a['version'],
    ];
}
