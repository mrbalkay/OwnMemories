<?php
/**
 * OwnMemories — medya türü, meta veri okuma, küçük resim/önizleme ve video posteri.
 *
 * Araç sırası: meta veri exiftool → exif_read_data (foto) / ffprobe (video);
 * görüntü üretimi vipsthumbnail → Imagick → GD; video karesi ffmpeg.
 * Hiçbiri olmazsa 'placeholder' döner (asset yine içe aktarılır).
 */
declare(strict_types=1);

const THUMB_EDGE = 360;
const PREVIEW_EDGE = 2048;

const MEDIA_PHOTO_EXT = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'heic', 'heif', 'avif', 'dng', 'tif', 'tiff', 'bmp', 'cr2', 'nef', 'arw', 'raf', 'orf', 'rw2'];
const MEDIA_VIDEO_EXT = ['mp4', 'mov', 'm4v', '3gp', '3g2', 'avi', 'mkv', 'mts', 'm2ts', 'wmv', 'webm', 'mpg', 'mpeg'];

/** 'photo' | 'video' | null. Önce verilen dosya adının (yoksa yolun) uzantısı, uzantı yoksa içerik. */
function media_kind(string $path, ?string $filename = null): ?string
{
    $ext = strtolower(pathinfo($filename ?? $path, PATHINFO_EXTENSION));
    if ($ext !== '') {
        if (in_array($ext, MEDIA_PHOTO_EXT, true)) {
            return 'photo';
        }
        if (in_array($ext, MEDIA_VIDEO_EXT, true)) {
            return 'video';
        }
        return null;
    }
    if (!is_file($path)) {
        return null;
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (str_starts_with($mime, 'image/')) {
        return 'photo';
    }
    if (str_starts_with($mime, 'video/')) {
        return 'video';
    }
    return null;
}

function media_mime(string $path, ?string $filename = null): string
{
    $ext = strtolower(pathinfo($filename ?? $path, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif', 'avif' => 'image/avif', 'dng' => 'image/x-adobe-dng',
        'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'bmp' => 'image/bmp',
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', '3gp' => 'video/3gpp', '3g2' => 'video/3gpp2',
        'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska', 'mts' => 'video/mp2t', 'm2ts' => 'video/mp2t',
        'wmv' => 'video/x-ms-wmv', 'webm' => 'video/webm', 'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg',
    ];
    if (isset($map[$ext])) {
        return $map[$ext];
    }
    return is_file($path) ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($path) : 'application/octet-stream';
}

/** Boş meta veri şablonu. taken_utc yalnız saat dilimi bilinmeyen ama UTC olduğu bilinen zamanlarda (QuickTime) dolar. */
function media_probe_empty(): array
{
    return [
        'mime' => null, 'width' => null, 'height' => null, 'duration_ms' => null,
        'taken_local' => null, 'tz_offset' => null, 'taken_utc' => null,
        'lat' => null, 'lng' => null, 'alt' => null,
        'make' => null, 'model' => null, 'lens' => null, 'orientation' => null, 'description' => null,
    ];
}

/** Meta veriyi okur; hiçbir zaman istisna fırlatmaz. */
function media_probe(string $path, string $kind, ?string $filename = null): array
{
    $out = media_probe_empty();
    $out['mime'] = media_mime($path, $filename);
    if (!is_file($path)) {
        return $out;
    }
    $tools = tools_detect();
    try {
        if ($tools['exiftool']) {
            $out = media_merge($out, media_probe_exiftool($path, $tools['exiftool']));
        }
        if ($kind === 'photo' && ($out['taken_local'] === null || $out['width'] === null)) {
            $out = media_merge($out, media_probe_php($path));
        }
        if ($kind === 'video' && $tools['ffprobe'] && ($out['width'] === null || $out['duration_ms'] === null)) {
            $out = media_merge($out, media_probe_ffprobe($path, $tools['ffprobe']));
        }
    } catch (Throwable $e) {
        app_log('media', 'probe ' . basename($path) . ': ' . $e->getMessage());
    }
    // Ekranda görünen boyut: 90°/270° döndürülmüş görüntülerde en/boy yer değiştirir.
    if (in_array((int) $out['orientation'], [5, 6, 7, 8], true) && $out['width'] !== null && empty($out['__display_dims'])) {
        [$out['width'], $out['height']] = [$out['height'], $out['width']];
    }
    unset($out['__display_dims']);
    return $out;
}

/** $b'deki null olmayan değerleri $a'da boş olan alanlara yazar. */
function media_merge(array $a, array $b): array
{
    foreach ($b as $k => $v) {
        if ($v !== null && $v !== '' && (!array_key_exists($k, $a) || $a[$k] === null)) {
            $a[$k] = $v;
        }
    }
    return $a;
}

/** "2019:07:01 15:04:05" (+ isteğe bağlı ofset) → ['Y-m-d H:i:s', ?ofsetDakika] */
function media_parse_exif_date(?string $s): array
{
    if ($s === null) {
        return [null, null];
    }
    $s = trim($s);
    if (!preg_match('/^(\d{4})[:\-](\d{2})[:\-](\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?\s*(Z|[+\-]\d{2}:?\d{2})?/', $s, $m)) {
        return [null, null];
    }
    if ((int) $m[1] < 1900 || (int) $m[2] < 1 || (int) $m[2] > 12 || (int) $m[3] < 1) {
        return [null, null]; // "0000:00:00 00:00:00" gibi boş tarihler
    }
    $local = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
    return [$local, isset($m[7]) ? media_parse_offset($m[7]) : null];
}

function media_parse_offset(?string $s): ?int
{
    if ($s === null || $s === '') {
        return null;
    }
    if ($s === 'Z') {
        return 0;
    }
    if (!preg_match('/^([+\-])(\d{2}):?(\d{2})$/', trim($s), $m)) {
        return null;
    }
    $min = (int) $m[2] * 60 + (int) $m[3];
    return $m[1] === '-' ? -$min : $min;
}

function media_probe_exiftool(string $path, string $bin): array
{
    $r = tool_run([$bin, '-json', '-n', '-api', 'LargeFileSupport=1', '-q', '-q', $path], 120);
    $j = json_decode($r['out'], true);
    if (!is_array($j) || !isset($j[0]) || !is_array($j[0])) {
        return [];
    }
    $x = $j[0];
    $g = fn(string ...$keys) => array_reduce($keys, fn($c, $k) => $c ?? (isset($x[$k]) && $x[$k] !== '' ? $x[$k] : null), null);
    $o = media_probe_empty();
    $o['mime'] = $g('MIMEType');

    // Yerel çekim zamanı (+ofset). Apple videolarında CreationDate ofset içerir.
    [$local, $off] = media_parse_exif_date($g('DateTimeOriginal', 'CreationDate', 'SubSecDateTimeOriginal'));
    $off ??= media_parse_offset($g('OffsetTimeOriginal', 'OffsetTime'));
    if ($local === null && !str_starts_with((string) $o['mime'], 'video/')) {
        [$local, $off2] = media_parse_exif_date($g('CreateDate', 'DateCreated', 'DateTimeCreated', 'ModifyDate'));
        $off ??= $off2;
    }
    $o['taken_local'] = $local;
    $o['tz_offset'] = $local !== null ? $off : null;
    if ($local === null && str_starts_with((string) $o['mime'], 'video/')) {
        // QuickTime CreateDate standarda göre UTC'dir.
        [$utc] = media_parse_exif_date($g('CreateDate', 'MediaCreateDate', 'TrackCreateDate'));
        $o['taken_utc'] = $utc;
    }

    $lat = $g('GPSLatitude');
    $lng = $g('GPSLongitude');
    if ($lat === null && is_string($g('GPSCoordinates'))) {
        $parts = preg_split('/[\s,]+/', trim((string) $g('GPSCoordinates')));
        $lat = $parts[0] ?? null;
        $lng = $parts[1] ?? null;
        $o['alt'] = isset($parts[2]) && is_numeric($parts[2]) ? (float) $parts[2] : null;
    }
    if (is_numeric($lat) && is_numeric($lng) && !((float) $lat == 0.0 && (float) $lng == 0.0)) {
        // -n ile Ref uygulanmış işaretli değerler gelir; Ref ayrı geldiyse uygula.
        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($g('GPSLatitudeRef') === 'S' && $lat > 0) {
            $lat = -$lat;
        }
        if ($g('GPSLongitudeRef') === 'W' && $lng > 0) {
            $lng = -$lng;
        }
        $o['lat'] = $lat;
        $o['lng'] = $lng;
    }
    if ($o['alt'] === null && is_numeric($g('GPSAltitude'))) {
        $o['alt'] = (float) $g('GPSAltitude') * ((int) $g('GPSAltitudeRef') === 1 ? -1 : 1);
    }
    $o['make'] = media_clean_str($g('Make'));
    $o['model'] = media_clean_str($g('Model'));
    $o['lens'] = media_clean_str($g('LensModel', 'Lens'));
    $o['orientation'] = is_numeric($g('Orientation')) ? (int) $g('Orientation') : null;
    $o['description'] = media_clean_str($g('ImageDescription', 'Description', 'Caption-Abstract'));
    $w = $g('ImageWidth', 'ExifImageWidth', 'SourceImageWidth');
    $h = $g('ImageHeight', 'ExifImageHeight', 'SourceImageHeight');
    if (is_numeric($w) && is_numeric($h)) {
        $o['width'] = (int) $w;
        $o['height'] = (int) $h;
        $rot = (int) $g('Rotation');
        if (str_starts_with((string) $o['mime'], 'video/') && in_array($rot, [90, 270], true)) {
            [$o['width'], $o['height']] = [$o['height'], $o['width']];
        }
    }
    if (is_numeric($g('Duration'))) {
        $o['duration_ms'] = (int) round((float) $g('Duration') * 1000);
    }
    return $o;
}

function media_clean_str($v): ?string
{
    if (!is_string($v) && !is_numeric($v)) {
        return null;
    }
    $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $v) ?? '');
    return $v === '' ? null : mb_substr($v, 0, 1000);
}

/** PHP exif + getimagesize geri düşüşü (yalnız fotoğraflar). */
function media_probe_php(string $path): array
{
    $o = media_probe_empty();
    $size = @getimagesize($path);
    if (is_array($size)) {
        $o['width'] = (int) $size[0];
        $o['height'] = (int) $size[1];
        $o['mime'] = $size['mime'] ?? null;
    }
    if (!function_exists('exif_read_data')) {
        return $o;
    }
    $x = @exif_read_data($path, null, true, false);
    if (!is_array($x)) {
        return $o;
    }
    $flat = [];
    foreach ($x as $section) {
        if (is_array($section)) {
            $flat += $section;
        }
    }
    [$local, $off] = media_parse_exif_date($flat['DateTimeOriginal'] ?? $flat['DateTimeDigitized'] ?? $flat['DateTime'] ?? null);
    $off ??= media_parse_offset($flat['OffsetTimeOriginal'] ?? $flat['UndefinedTag:0x9011'] ?? $flat['OffsetTime'] ?? $flat['UndefinedTag:0x9010'] ?? null);
    $o['taken_local'] = $local;
    $o['tz_offset'] = $local !== null ? $off : null;
    if (isset($flat['GPSLatitude'], $flat['GPSLongitude'])) {
        $lat = media_gps_to_float($flat['GPSLatitude'], (string) ($flat['GPSLatitudeRef'] ?? 'N'));
        $lng = media_gps_to_float($flat['GPSLongitude'], (string) ($flat['GPSLongitudeRef'] ?? 'E'));
        if ($lat !== null && $lng !== null && !($lat == 0.0 && $lng == 0.0)) {
            $o['lat'] = $lat;
            $o['lng'] = $lng;
        }
    }
    if (isset($flat['GPSAltitude'])) {
        $alt = media_rational($flat['GPSAltitude']);
        $o['alt'] = $alt !== null ? $alt * ((int) ($flat['GPSAltitudeRef'] ?? 0) === 1 ? -1 : 1) : null;
    }
    $o['make'] = media_clean_str($flat['Make'] ?? null);
    $o['model'] = media_clean_str($flat['Model'] ?? null);
    $o['lens'] = media_clean_str($flat['UndefinedTag:0xA434'] ?? $flat['LensModel'] ?? null);
    $o['orientation'] = isset($flat['Orientation']) ? (int) $flat['Orientation'] : null;
    $o['description'] = media_clean_str($flat['ImageDescription'] ?? null);
    return $o;
}

function media_rational($v): ?float
{
    if (is_numeric($v)) {
        return (float) $v;
    }
    if (is_string($v) && preg_match('#^(-?\d+)/(\d+)$#', $v, $m)) {
        return (int) $m[2] === 0 ? null : (int) $m[1] / (int) $m[2];
    }
    return null;
}

function media_gps_to_float($parts, string $ref): ?float
{
    if (!is_array($parts) || count($parts) < 3) {
        return null;
    }
    [$d, $m, $s] = array_map('media_rational', array_slice($parts, 0, 3));
    if ($d === null || $m === null || $s === null) {
        return null;
    }
    $v = $d + $m / 60 + $s / 3600;
    return in_array(strtoupper($ref), ['S', 'W'], true) ? -$v : $v;
}

/** ffprobe ile video boyutu, süresi, UTC oluşturma zamanı ve ISO6709 konumu. */
function media_probe_ffprobe(string $path, string $bin): array
{
    $r = tool_run([$bin, '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $path], 120);
    $j = json_decode($r['out'], true);
    $o = media_probe_empty();
    if (!is_array($j)) {
        return $o;
    }
    foreach ($j['streams'] ?? [] as $s) {
        if (($s['codec_type'] ?? '') === 'video' && isset($s['width'])) {
            $o['width'] = (int) $s['width'];
            $o['height'] = (int) $s['height'];
            $rot = (int) ($s['tags']['rotate'] ?? 0);
            foreach ($s['side_data_list'] ?? [] as $sd) {
                if (isset($sd['rotation'])) {
                    $rot = (int) $sd['rotation'];
                }
            }
            if (in_array(abs($rot) % 360, [90, 270], true)) {
                [$o['width'], $o['height']] = [$o['height'], $o['width']];
            }
            $o['__display_dims'] = true;
            break;
        }
    }
    if (isset($j['format']['duration']) && is_numeric($j['format']['duration'])) {
        $o['duration_ms'] = (int) round((float) $j['format']['duration'] * 1000);
    }
    $tags = $j['format']['tags'] ?? [];
    $apple = $tags['com.apple.quicktime.creationdate'] ?? null;
    if (is_string($apple)) {
        [$local, $off] = media_parse_exif_date($apple);
        $o['taken_local'] = $local;
        $o['tz_offset'] = $off;
    } elseif (isset($tags['creation_time'])) {
        $ts = strtotime((string) $tags['creation_time']);
        if ($ts !== false && $ts > 0) {
            $o['taken_utc'] = gmdate('Y-m-d H:i:s', $ts);
        }
    }
    $loc = $tags['com.apple.quicktime.location.ISO6709'] ?? $tags['location'] ?? null;
    if (is_string($loc) && preg_match('/^([+\-]\d+(?:\.\d+)?)([+\-]\d+(?:\.\d+)?)([+\-]\d+(?:\.\d+)?)?/', $loc, $m)) {
        $o['lat'] = (float) $m[1];
        $o['lng'] = (float) $m[2];
        $o['alt'] = isset($m[3]) ? (float) $m[3] : null;
    }
    $o['make'] = media_clean_str($tags['com.apple.quicktime.make'] ?? null);
    $o['model'] = media_clean_str($tags['com.apple.quicktime.model'] ?? null);
    return $o;
}

/* ---------------------------------------------------------------- render */

/** Önizlemeler için tercih edilen biçim: WebP yazılabiliyorsa 'webp', değilse 'jpeg'. */
function media_preferred_format(): string
{
    $t = tools_detect();
    if ($t['vipsthumbnail'] || ($t['imagick'] && in_array('WEBP', array_map('strtoupper', Imagick::queryFormats()), true)) || $t['gd_webp']) {
        return 'webp';
    }
    return 'jpeg';
}

/**
 * $src'den uzun kenarı en fazla $maxEdge olan, yönü düzeltilmiş görüntüyü $dest'e yazar.
 * Video için ffmpeg ile bir kare alınır. Dönüş: 'ready' | 'placeholder'.
 */
function media_render(string $src, string $kind, string $dest, int $maxEdge, string $format = 'webp'): string
{
    $format = $format === 'webp' ? 'webp' : 'jpeg';
    @mkdir(dirname($dest), 0750, true);
    if ($kind === 'video') {
        $frame = media_video_frame($src);
        if ($frame === null) {
            return 'placeholder';
        }
        try {
            return media_render($frame, 'photo', $dest, $maxEdge, $format);
        } finally {
            @unlink($frame);
        }
    }
    $tools = tools_detect();
    $tmp = $dest . '.tmp-' . bin2hex(random_bytes(4)) . ($format === 'webp' ? '.webp' : '.jpg');
    $ok = false;
    if ($tools['vipsthumbnail']) {
        $ok = media_render_vips($tools['vipsthumbnail'], $src, $tmp, $maxEdge, $format);
    }
    if (!$ok && $tools['imagick']) {
        $ok = media_render_imagick($src, $tmp, $maxEdge, $format);
    }
    if (!$ok && $tools['gd']) {
        $ok = media_render_gd($src, $tmp, $maxEdge, $format);
    }
    if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        return 'placeholder';
    }
    rename($tmp, $dest);
    return 'ready';
}

function media_render_vips(string $bin, string $src, string $tmp, int $edge, string $format): bool
{
    $q = $format === 'webp' ? '[Q=80,strip]' : '[Q=82,strip,optimize_coding]';
    $r = tool_run([$bin, $src, '--size', $edge . 'x' . $edge . '>', '-o', $tmp . $q], 300);
    return $r['code'] === 0 && is_file($tmp) && filesize($tmp) > 0;
}

function media_render_imagick(string $src, string $tmp, int $edge, string $format): bool
{
    try {
        $im = new Imagick();
        $im->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        $im->readImage($src . '[0]');
        if (method_exists($im, 'autoOrient')) {
            $im->autoOrient();
        } else {
            media_imagick_orient($im);
        }
        if ($im->getImageWidth() > $edge || $im->getImageHeight() > $edge) {
            $im->thumbnailImage($edge, $edge, true);
        }
        $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $im->stripImage();
        $im->setImageFormat($format === 'webp' ? 'webp' : 'jpeg');
        $im->setImageCompressionQuality($format === 'webp' ? 80 : 82);
        $ok = $im->writeImage($tmp);
        $im->clear();
        return $ok && is_file($tmp);
    } catch (Throwable $e) {
        return false;
    }
}

function media_imagick_orient(Imagick $im): void
{
    switch ($im->getImageOrientation()) {
        case Imagick::ORIENTATION_BOTTOMRIGHT: $im->rotateImage('#000', 180); break;
        case Imagick::ORIENTATION_RIGHTTOP: $im->rotateImage('#000', 90); break;
        case Imagick::ORIENTATION_LEFTBOTTOM: $im->rotateImage('#000', -90); break;
    }
    $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
}

function media_render_gd(string $src, string $tmp, int $edge, string $format): bool
{
    $info = @getimagesize($src);
    if (!is_array($info) || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_BMP], true)) {
        return false;
    }
    // Bellek koruması: ~4 bayt/piksel; çok büyük görüntüleri GD ile açma.
    if ($info[0] * $info[1] > 60_000_000) {
        return false;
    }
    $img = @imagecreatefromstring((string) file_get_contents($src));
    if ($img === false) {
        return false;
    }
    $orientation = 1;
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $x = @exif_read_data($src);
        $orientation = (int) ($x['Orientation'] ?? 1);
    }
    $img = media_gd_orient($img, $orientation);
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, $edge / max($w, $h));
    if ($scale < 1) {
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
    }
    $ok = $format === 'webp' && function_exists('imagewebp') ? imagewebp($img, $tmp, 80) : imagejpeg($img, $tmp, 82);
    imagedestroy($img);
    return $ok;
}

function media_gd_orient(GdImage $img, int $o): GdImage
{
    $map = [2 => [0, IMG_FLIP_HORIZONTAL], 3 => [180, null], 4 => [0, IMG_FLIP_VERTICAL], 5 => [-90, IMG_FLIP_HORIZONTAL], 6 => [-90, null], 7 => [90, IMG_FLIP_HORIZONTAL], 8 => [90, null]];
    if (!isset($map[$o])) {
        return $img;
    }
    [$angle, $flip] = $map[$o];
    if ($angle !== 0) {
        $r = imagerotate($img, $angle, 0);
        imagedestroy($img);
        $img = $r;
    }
    if ($flip !== null) {
        imageflip($img, $flip);
    }
    return $img;
}

/** Videodan bir kare (JPEG) çıkarır; geçici dosya yolu veya null. */
function media_video_frame(string $src): ?string
{
    $bin = tools_detect()['ffmpeg'];
    if (!$bin || !is_file($src)) {
        return null;
    }
    $out = sys_get_temp_dir() . '/om_frame_' . bin2hex(random_bytes(6)) . '.jpg';
    foreach (['1', '0'] as $ss) {
        $r = tool_run([$bin, '-v', 'error', '-y', '-ss', $ss, '-i', $src, '-frames:v', '1', '-q:v', '3', $out], 120);
        if ($r['code'] === 0 && is_file($out) && filesize($out) > 0) {
            return $out;
        }
    }
    @unlink($out);
    return null;
}

/* ------------------------------------------------------------ capture time */

function default_timezone(): string
{
    return (string) config('app.default_timezone', 'Europe/Istanbul');
}

/** Varsayılan saat diliminin verilen UTC anındaki ofseti (dakika). */
function tz_offset_at_utc(int $ts, ?string $tz = null): int
{
    $dt = new DateTimeImmutable('@' . $ts);
    return intdiv((new DateTimeZone($tz ?? default_timezone()))->getOffset($dt), 60);
}

/** Varsayılan saat dilimindeki yerel zamanı UTC'ye çevirir: [utc 'Y-m-d H:i:s', ofset dk]. */
function local_to_utc(string $local, ?int $offsetMin = null): array
{
    if ($offsetMin !== null) {
        $ts = (int) (new DateTimeImmutable($local, new DateTimeZone('UTC')))->format('U') - $offsetMin * 60;
        return [gmdate('Y-m-d H:i:s', $ts), $offsetMin];
    }
    $dt = new DateTimeImmutable($local, new DateTimeZone(default_timezone()));
    return [gmdate('Y-m-d H:i:s', (int) $dt->format('U')), intdiv($dt->getOffset(), 60)];
}

/** Dosya adındaki tarih: IMG_20180512_093011.jpg, 2018-05-12 09.30.11.jpg, VID-20180512-WA0001.mp4 ... */
function filename_datetime(string $filename): ?string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    if (preg_match('/(?<!\d)((?:19|20)\d{2})[-_.]?(\d{2})[-_.]?(\d{2})[-_ T.]?(\d{2})[-_.:]?(\d{2})[-_.:]?(\d{2})(?!\d)/', $base, $m)
        && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60) {
        return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
    }
    if (preg_match('/(?<!\d)((?:19|20)\d{2})[-_.]?(\d{2})[-_.]?(\d{2})(?!\d)/', $base, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return sprintf('%s-%s-%s 12:00:00', $m[1], $m[2], $m[3]);
    }
    return null;
}

/**
 * Çekim anını belirler. Öncelik: Takeout sidecar zamanı > EXIF/QuickTime > dosya adı > dosya zamanı.
 * @return array{taken_at_utc:string, tz_offset:int, date_source:string}
 */
function capture_time_resolve(?int $sidecarTs, array $probe, string $filename, ?int $mtime): array
{
    if ($sidecarTs !== null && $sidecarTs > 0) {
        $off = $probe['tz_offset'] ?? null;
        if ($off === null && !empty($probe['taken_local'])) {
            $localAsUtc = (int) (new DateTimeImmutable((string) $probe['taken_local'], new DateTimeZone('UTC')))->format('U');
            $diff = (int) (round(($localAsUtc - $sidecarTs) / 900) * 15);
            if (abs($diff) <= 14 * 60) {
                $off = $diff;
            }
        }
        return ['taken_at_utc' => gmdate('Y-m-d H:i:s', $sidecarTs), 'tz_offset' => $off ?? tz_offset_at_utc($sidecarTs), 'date_source' => 'takeout'];
    }
    if (!empty($probe['taken_local'])) {
        [$utc, $off] = local_to_utc((string) $probe['taken_local'], $probe['tz_offset'] ?? null);
        return ['taken_at_utc' => $utc, 'tz_offset' => $off, 'date_source' => 'exif'];
    }
    if (!empty($probe['taken_utc'])) {
        $ts = (int) (new DateTimeImmutable((string) $probe['taken_utc'], new DateTimeZone('UTC')))->format('U');
        return ['taken_at_utc' => gmdate('Y-m-d H:i:s', $ts), 'tz_offset' => tz_offset_at_utc($ts), 'date_source' => 'exif'];
    }
    $fromName = filename_datetime($filename);
    if ($fromName !== null) {
        [$utc, $off] = local_to_utc($fromName);
        return ['taken_at_utc' => $utc, 'tz_offset' => $off, 'date_source' => 'filename'];
    }
    $ts = $mtime ?? time();
    return ['taken_at_utc' => gmdate('Y-m-d H:i:s', $ts), 'tz_offset' => tz_offset_at_utc($ts), 'date_source' => 'file'];
}
