<?php
/**
 * OwnMemories — içerik özetine (SHA-1) göre blob deposu ve türev dosya yolları.
 *
 * Orijinaller: <depo>/blobs/aa/bb/<sha1>.<uzantı>  (aynı içerik tek kopya, ref_count ile)
 * Türevler:    <depo>/derived/<thumb|preview|poster>/<id'nin ilk 2 karakteri>/<asset id>.<uzantı>
 */
declare(strict_types=1);

function blob_rel_path(string $sha1, string $ext): string
{
    return 'blobs/' . substr($sha1, 0, 2) . '/' . substr($sha1, 2, 2) . '/' . $sha1 . ($ext !== '' ? '.' . $ext : '');
}

function blob_path(string $sha1): string
{
    $rel = db_val('SELECT path FROM blobs WHERE sha1 = ?', [$sha1]);
    return storage_root() . '/' . ($rel ?? blob_rel_path($sha1, ''));
}

/** Dosyayı akışla kopyalar (veya taşır); başarısızsa istisna. */
function file_transfer(string $src, string $dest, bool $move): void
{
    @mkdir(dirname($dest), 0750, true);
    $tmp = $dest . '.part-' . bin2hex(random_bytes(4));
    if ($move && @rename($src, $tmp)) {
        rename($tmp, $dest);
        return;
    }
    $in = fopen($src, 'rb');
    $out = fopen($tmp, 'wb');
    if ($in === false || $out === false) {
        throw new RuntimeException('Dosya kopyalanamadı: ' . basename($src));
    }
    $copied = stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    if ($copied === false || $copied !== filesize($src)) {
        @unlink($tmp);
        throw new RuntimeException('Dosya eksik kopyalandı: ' . basename($src));
    }
    rename($tmp, $dest);
    if ($move) {
        @unlink($src);
    }
}

/**
 * Blobu depoya koyar veya mevcut blobun referansını artırır. Blob satırını döner.
 * Mevcutsa $move=true kaynağı siler.
 */
function blob_put(string $srcPath, string $sha1, string $ext, bool $move = false): array
{
    $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext) ?? '');
    $row = db_row('SELECT * FROM blobs WHERE sha1 = ?', [$sha1]);
    if ($row !== null && is_file(storage_root() . '/' . $row['path'])) {
        db_exec('UPDATE blobs SET ref_count = ref_count + 1 WHERE sha1 = ?', [$sha1]);
        if ($move) {
            @unlink($srcPath);
        }
        $row['ref_count'] = (int) $row['ref_count'] + 1;
        return $row;
    }
    $rel = blob_rel_path($sha1, $ext);
    file_transfer($srcPath, storage_root() . '/' . $rel, $move);
    $size = (int) filesize(storage_root() . '/' . $rel);
    $mime = media_mime(storage_root() . '/' . $rel);
    if ($row !== null) {
        // Satır vardı ama dosya kaybolmuştu: yeniden yazıldı.
        db_exec('UPDATE blobs SET path = ?, size = ?, ref_count = ref_count + 1 WHERE sha1 = ?', [$rel, $size, $sha1]);
    } else {
        db_exec('INSERT INTO blobs (sha1, size, mime, ext, path, ref_count, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)', [$sha1, $size, $mime, $ext, $rel, now_utc()]);
    }
    return db_row('SELECT * FROM blobs WHERE sha1 = ?', [$sha1]);
}

/** Referansı azaltır; sıfırlanınca dosya ve satır silinir. */
function blob_release(string $sha1): void
{
    db_exec('UPDATE blobs SET ref_count = GREATEST(ref_count, 1) - 1 WHERE sha1 = ?', [$sha1]);
    $row = db_row('SELECT * FROM blobs WHERE sha1 = ? AND ref_count = 0', [$sha1]);
    if ($row !== null) {
        @unlink(storage_root() . '/' . $row['path']);
        db_exec('DELETE FROM blobs WHERE sha1 = ? AND ref_count = 0', [$sha1]);
    }
}

function derived_path(string $assetId, string $variant, string $ext): string
{
    return storage_root() . '/derived/' . $variant . '/' . substr($assetId, 0, 2) . '/' . $assetId . '.' . $ext;
}

/** Bir asset'in var olan türev dosyası (uzantı fark etmeksizin) veya null. */
function derived_find(string $assetId, string $variant): ?string
{
    foreach (['webp', 'jpg'] as $ext) {
        $p = derived_path($assetId, $variant, $ext);
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

/** Tarayıcı uyumlu (H.264 MP4) video kopyası veya null. */
function derived_find_video(string $assetId): ?string
{
    $p = derived_path($assetId, 'web', 'mp4');
    return is_file($p) && filesize($p) > 0 ? $p : null;
}

function derived_delete(string $assetId, bool $includeVideo = true): void
{
    foreach (['thumb', 'preview', 'poster'] as $v) {
        foreach (['webp', 'jpg'] as $ext) {
            @unlink(derived_path($assetId, $v, $ext));
        }
    }
    if ($includeVideo) {
        @unlink(derived_path($assetId, 'web', 'mp4'));
    }
    if (function_exists('share_derived_delete')) {
        share_derived_delete($assetId); // bağlantıyla paylaşım önbelleği (shares.php)
    }
}
