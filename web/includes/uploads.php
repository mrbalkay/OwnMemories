<?php
/**
 * OwnMemories — parçalı, devam ettirilebilir yükleme (Cloudflare 100 MB istek sınırı için ≤ 50 MB parçalar).
 * POST /uploads → PUT /uploads/{id}?offset=N (ham bayt) … → POST /uploads/{id}/complete
 */
declare(strict_types=1);

const UPLOAD_CHUNK_SIZE = 52428800; // 50 MB
const UPLOAD_TTL = 172800;          // 48 saat
const UPLOAD_MAX_SIZE = 64424509440; // 60 GB

function upload_file(string $id): string
{
    return storage_path('uploads') . '/' . $id . '.part';
}

function upload_row(string $id, int $userId): array
{
    $row = is_uuid($id) ? db_row('SELECT * FROM uploads WHERE id = ? AND user_id = ?', [$id, $userId]) : null;
    if ($row === null) {
        api_not_found();
    }
    return $row;
}

function upload_start(int $userId, string $filename, int $size, ?string $sha1, array $meta, string $source = 'web'): array
{
    $filename = trim(str_replace(['/', '\\', "\0"], '_', $filename));
    if ($filename === '' || media_kind($filename, $filename) === null) {
        throw new ValidationError('Desteklenmeyen dosya türü.', 'filename');
    }
    if ($size <= 0 || $size > UPLOAD_MAX_SIZE) {
        throw new ValidationError('Geçersiz boyut.', 'size');
    }
    if ($sha1 !== null && !preg_match('/^[0-9a-f]{40}$/', strtolower($sha1))) {
        throw new ValidationError('Geçersiz SHA-1.', 'sha1');
    }
    $u = db_row('SELECT quota_bytes, used_bytes FROM users WHERE id = ?', [$userId]);
    if ($u['quota_bytes'] !== null && (int) $u['used_bytes'] + $size > (int) $u['quota_bytes']) {
        throw new ValidationError('quota_exceeded', 'quota');
    }
    $id = uuid4();
    db_exec('INSERT INTO uploads (id, user_id, filename, size, sha1, received, source, meta_json, created_at, expires_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)', [
        $id, $userId, mb_substr($filename, 0, 255), $size, $sha1 !== null ? strtolower($sha1) : null, $source === 'ios' ? 'ios' : 'web',
        json_encode($meta, JSON_UNESCAPED_UNICODE), now_utc(), gmdate('Y-m-d H:i:s', time() + UPLOAD_TTL),
    ]);
    touch(upload_file($id));
    return ['id' => $id, 'chunk_size' => UPLOAD_CHUNK_SIZE, 'received' => 0];
}

function upload_received(string $id, int $userId): int
{
    return (int) upload_row($id, $userId)['received'];
}

/** Bir parçayı ekler; toplam alınan baytı döner. */
function upload_chunk(string $id, int $userId, int $offset, $stream): int
{
    $row = upload_row($id, $userId);
    $received = (int) $row['received'];
    if ($offset !== $received) {
        throw new ApiError(409, 'offset_mismatch', 'Beklenen konum: ' . $received, ['received' => $received]);
    }
    $file = upload_file($id);
    $out = fopen($file, 'c+b');
    if ($out === false || !flock($out, LOCK_EX)) {
        throw new RuntimeException('Yükleme dosyası açılamadı');
    }
    try {
        ftruncate($out, $received);
        fseek($out, $received);
        $limit = min(UPLOAD_CHUNK_SIZE + 1024, (int) $row['size'] - $received);
        $written = 0;
        while (!feof($stream)) {
            $buf = fread($stream, 1048576);
            if ($buf === false || $buf === '') {
                break;
            }
            $written += strlen($buf);
            if ($written > $limit) {
                ftruncate($out, $received);
                throw new ValidationError('Parça beklenenden büyük.', 'body');
            }
            if (fwrite($out, $buf) !== strlen($buf)) {
                ftruncate($out, $received);
                throw new RuntimeException('Yükleme diske yazılamadı (disk dolu olabilir)');
            }
        }
        if (!fflush($out)) {
            ftruncate($out, $received);
            throw new RuntimeException('Yükleme diske yazılamadı (disk dolu olabilir)');
        }
    } finally {
        flock($out, LOCK_UN);
        fclose($out);
    }
    $new = $received + $written;
    db_exec('UPDATE uploads SET received = ?, expires_at = ? WHERE id = ?', [$new, gmdate('Y-m-d H:i:s', time() + UPLOAD_TTL), $id]);
    return $new;
}

/** Yüklemeyi doğrular ve kütüphaneye ekler: {status, asset}. */
function upload_complete(string $id, int $userId): array
{
    $row = upload_row($id, $userId);
    if ((int) $row['received'] !== (int) $row['size']) {
        throw new ApiError(409, 'incomplete', 'Yükleme tamamlanmadı.', ['received' => (int) $row['received']]);
    }
    $file = upload_file($id);
    $sha1 = sha1_file($file);
    if ($row['sha1'] !== null && $row['sha1'] !== $sha1) {
        @unlink($file);
        db_exec('DELETE FROM uploads WHERE id = ?', [$id]);
        throw new ApiError(422, 'checksum_mismatch', 'Dosya özeti tutmuyor; yükleme baştan yapılmalı.');
    }
    $meta = json_decode((string) $row['meta_json'], true) ?: [];
    $in = ['filename' => $row['filename'], 'source' => $row['source'], 'sha1' => $sha1, 'move' => true];
    if (!empty($meta['taken_at']) && ($t = api_iso_to_utc($meta['taken_at'])) !== null) {
        $in['taken_at_utc'] = $t;
        $in['date_source'] = 'exif';
        if (isset($meta['tz_offset']) && is_numeric($meta['tz_offset'])) {
            $in['tz_offset'] = (int) $meta['tz_offset'];
        }
    }
    if (isset($meta['latitude'], $meta['longitude']) && is_numeric($meta['latitude']) && is_numeric($meta['longitude'])) {
        $in['lat'] = (float) $meta['latitude'];
        $in['lng'] = (float) $meta['longitude'];
    }
    foreach (['is_favorite', 'description', 'source_ref'] as $k) {
        if (isset($meta[$k])) {
            $in[$k] = $meta[$k];
        }
    }
    try {
        $r = asset_ingest($userId, $file, $in);
    } finally {
        @unlink($file);
        db_exec('DELETE FROM uploads WHERE id = ?', [$id]);
    }
    $asset = $r['asset_id'] !== null ? db_row('SELECT * FROM assets WHERE id = ?', [$r['asset_id']]) : null;
    return ['status' => $r['status'], 'asset' => $asset !== null ? asset_to_json($asset) : null];
}

function uploads_cleanup(): int
{
    $n = 0;
    foreach (db_all('SELECT id FROM uploads WHERE expires_at < ?', [now_utc()]) as $r) {
        @unlink(upload_file($r['id']));
        $n += db_exec('DELETE FROM uploads WHERE id = ?', [$r['id']]);
    }
    return $n;
}
