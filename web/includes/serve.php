<?php
/**
 * OwnMemories — dosya sunumu (HTTP Range ile; videolarda ileri sarma için).
 */
declare(strict_types=1);

/** Range başlığına göre yanıt planı. Çoklu aralık desteklenmez (tamamı gönderilir). */
function serve_plan(int $size, ?string $range): array
{
    $full = ['status' => 200, 'start' => 0, 'end' => max(0, $size - 1), 'length' => $size, 'total' => $size];
    if ($range === null || $range === '' || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
        return $full;
    }
    if ($m[1] === '' && $m[2] === '') {
        return $full;
    }
    if ($m[1] === '') {
        $len = min((int) $m[2], $size);
        $start = $size - $len;
        $end = $size - 1;
    } else {
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
    }
    if ($start >= $size || $start > $end) {
        return ['status' => 416, 'start' => 0, 'end' => 0, 'length' => 0, 'total' => $size];
    }
    return ['status' => 206, 'start' => $start, 'end' => $end, 'length' => $end - $start + 1, 'total' => $size];
}

/** Dosyanın [start, end] aralığını çıktıya akıtır. */
function serve_stream(string $path, int $start, int $end): void
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return;
    }
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) {
        $buf = fread($fh, (int) min(1048576, $left));
        if ($buf === false || $buf === '') {
            break;
        }
        echo $buf;
        $left -= strlen($buf);
        if (!defined('OM_TESTING')) {
            flush();
        }
    }
    fclose($fh);
}

/** Dosyayı başlıklarıyla gönderir ve çıkar. */
function serve_file(string $path, string $mime, string $cacheControl, ?string $downloadName = null): never
{
    $size = (int) filesize($path);
    $mtime = (int) filemtime($path);
    $etag = '"' . dechex($size) . '-' . dechex($mtime) . '"';
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: ' . $cacheControl);
        exit;
    }
    $plan = serve_plan($size, $_SERVER['HTTP_RANGE'] ?? null);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($plan['status']);
    header('Accept-Ranges: bytes');
    header('Cache-Control: ' . $cacheControl);
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    if ($plan['status'] === 416) {
        header('Content-Range: bytes */' . $size);
        exit;
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $plan['length']);
    if ($plan['status'] === 206) {
        header(sprintf('Content-Range: bytes %d-%d/%d', $plan['start'], $plan['end'], $size));
    }
    if ($downloadName !== null) {
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
        header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($downloadName));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD' && $plan['length'] > 0) {
        @set_time_limit(0);
        serve_stream($path, $plan['start'], $plan['end']);
    }
    exit;
}
