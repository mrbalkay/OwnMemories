<?php
/**
 * OwnMemories — herkese açık paylaşım ucu (/s/...). Ön denetleyici: web/s/index.php.
 *
 * share_http() saf bir işleyicidir (test edilebilir): istek dizisi alır, yanıt dizisi döner:
 *   ['status', 'headers' => [ad => değer], 'cookies' => [...], 'body' => html]
 *   ['status', 'headers', 'file' => ['path', 'mime', 'cache', 'download']]   (Range destekli sunum)
 *   ['status', 'headers', 'stream' => fn(callable $write)]                  (zip, GPS'i silinmiş orijinal)
 *
 * Yollar (başka hiçbir şey yanıtlanmaz; ana uygulama/API/oturum bu uçtan erişilemez):
 *   GET  /s/<belirteç>                      galeri | parola sayfası | süresi doldu / iptal sayfası
 *   POST /s/<belirteç>                      parola (IP ve bağlantı başına sınırlı) → çerez (yalnız bu yol)
 *   GET  /s/<belirteç>/m/<anahtar>/t|p|v|o  küçük resim | önizleme | video | orijinal (indirme izinliyse)
 *   GET  /s/<belirteç>/zip                  tümü (indirme izinliyse), geçici dosyasız akış
 *   GET  /s/<belirteç>/og.jpg               WhatsApp/iMessage önizleme görseli (parolasız bağlantılarda)
 *   GET  /s/assets/share.css|share.js|icon.svg
 */
declare(strict_types=1);

const SHARE_STATIC = ['share.css' => 'text/css; charset=utf-8', 'share.js' => 'text/javascript; charset=utf-8', 'icon.svg' => 'image/svg+xml'];
const SHARE_UNLOCK_COOKIE = 'om_su';
const SHARE_VIEW_COOKIE = 'om_sv';
const SHARE_BOT_UA = '/bot|crawl|spider|whatsapp|facebookexternalhit|telegram|slack|discord|preview|embedly|skype|linkedin/i';

/* -------------------------------------------------------- hız sınırı */

/** Pencere içinde sınır aşıldı mı (sayaç artırmadan). */
function share_rl_blocked(string $key, int $max, int $windowSec): bool
{
    $row = db_row('SELECT hits, window_start FROM rate_limits WHERE `key` = ?', [hash('sha256', $key)]);
    return $row !== null && time() - (int) $row['window_start'] < $windowSec && (int) $row['hits'] >= $max;
}

function share_rl_hit(string $key, int $windowSec): void
{
    rate_limit($key, PHP_INT_MAX, $windowSec);
}

/* ----------------------------------------------------- parola çerezi */

function share_unlock_mac(array $link, int $exp): string
{
    return b64url(hash_hmac('sha256', 'unlock|' . $link['id'] . '|' . $link['token_hash'] . '|' . $link['password_hash'] . '|' . $exp, share_secret(), true));
}

function share_unlocked(array $link, array $cookies): bool
{
    if ($link['password_hash'] === null) {
        return true;
    }
    $v = (string) ($cookies[SHARE_UNLOCK_COOKIE] ?? '');
    if (!preg_match('/^(\d{1,12})\.([A-Za-z0-9_-]{43})$/', $v, $m) || (int) $m[1] < time()) {
        return false;
    }
    return hash_equals(share_unlock_mac($link, (int) $m[1]), $m[2]);
}

function share_cookie(string $name, string $value, string $path, int $maxAge, bool $secure): array
{
    return ['name' => $name, 'value' => $value, 'path' => $path, 'expires' => time() + $maxAge, 'httponly' => true, 'secure' => $secure, 'samesite' => 'Lax'];
}

/* ----------------------------------------------------------- başlıklar */

function share_base_headers(): array
{
    return [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex, nofollow, noarchive, noimageindex',
        'X-Frame-Options' => 'DENY',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
    ];
}

function share_html_headers(): array
{
    return share_base_headers() + [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'private, no-store',
        'Content-Security-Policy' => "default-src 'none'; img-src 'self' blob: data:; media-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; font-src 'self'; manifest-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
    ];
}

function share_res_html(int $status, string $html, array $cookies = [], array $extra = []): array
{
    return ['status' => $status, 'headers' => $extra + share_html_headers(), 'cookies' => $cookies, 'body' => $html];
}

function share_res_text(int $status, string $text): array
{
    return ['status' => $status, 'headers' => share_base_headers() + ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'body' => $text];
}

/* -------------------------------------------------------------- yönlendirici */

/**
 * $req: method, path (ham URL yolu, sorgu yok), cookies, post, ip, https, host, ua?
 */
function share_http(array $req): array
{
    $method = strtoupper((string) ($req['method'] ?? 'GET'));
    $path = (string) ($req['path'] ?? '/');
    $ip = (string) ($req['ip'] ?? '0.0.0.0');
    $T = SHARE_TOKEN_RE;
    $K = SHARE_KEY_RE;
    try {
        if (preg_match('#^/s/assets/([a-z]+\.(?:css|js|svg))$#', $path, $m) && isset(SHARE_STATIC[$m[1]])) {
            return ['status' => 200, 'headers' => share_base_headers(), 'file' => ['path' => OM_WEB . '/s/assets/' . $m[1], 'mime' => SHARE_STATIC[$m[1]], 'cache' => 'public, max-age=86400', 'download' => null]];
        }
        if (preg_match("#^/s/($T)/?$#", $path, $m)) {
            return $method === 'POST' ? share_unlock_post($m[1], $req) : share_page($m[1], $req);
        }
        if (preg_match("#^/s/($T)/m/($K)/(t|p|v|o)$#", $path, $m)) {
            return share_media($m[1], $m[2], $m[3], $req);
        }
        if (preg_match("#^/s/($T)/zip$#", $path, $m)) {
            return share_zip($m[1], $req);
        }
        if (preg_match("#^/s/($T)/og\.jpg$#", $path, $m)) {
            return share_og_image($m[1], $req);
        }
    } catch (Throwable $e) {
        app_log('share', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        return share_res_html(500, share_state_html('error'));
    }
    // Arama motorları hiçbir şeyi dizinlemesin.
    if ($path === '/robots.txt') {
        return share_res_text(200, "User-agent: *\nDisallow: /\n");
    }
    // Belirteç biçiminde olmayan yollar (favicon, /wp-login.php, /.env gibi tarayıcı ve tarayıcı-bot yoklamaları)
    // tahmin sayılmaz: Funnel adresi herkese açık sertifika kayıtlarında görünür ve taranır; bunlar (aynı IP'yle
    // görünebilen) aileyi kilitlememeli. Bilinmeyen belirteçler share_gate()'te sayılır.
    return share_res_html(404, share_state_html('not_found'));
}

/**
 * Belirteç → bağlantı (sınırlar ve durum). Dönüş: ['link' => satır] veya ['res' => yanıt].
 * Bilinmeyen belirteçler IP başına sayılır; sınır aşılınca geçerli olanlar dahil 429 (tahmin edilemez).
 */
function share_gate(string $token, array $req, bool $html): array
{
    $ip = (string) ($req['ip'] ?? '0.0.0.0');
    if (share_rl_blocked('share-miss:' . $ip, 40, 600)) {
        return ['res' => $html ? share_res_html(429, share_state_html('limited'), [], ['Retry-After' => '600']) : share_res_text(429, 'Too many requests')];
    }
    $r = share_resolve($token);
    if ($r['state'] === 'not_found') {
        share_rl_hit('share-miss:' . $ip, 600);
        return ['res' => $html ? share_res_html(404, share_state_html('not_found')) : share_res_text(404, 'Not found')];
    }
    if ($r['state'] !== 'ok') {
        return ['res' => $html ? share_res_html(410, share_state_html($r['state'])) : share_res_text(404, 'Not found')];
    }
    return ['link' => $r['link']];
}

/* ------------------------------------------------------------------- sayfalar */

function share_page(string $token, array $req): array
{
    $g = share_gate($token, $req, true);
    if (isset($g['res'])) {
        return $g['res'];
    }
    $link = $g['link'];
    if (!share_unlocked($link, (array) ($req['cookies'] ?? []))) {
        return share_res_html(401, share_password_html($token, null));
    }
    $cookies = [];
    if (empty($req['cookies'][SHARE_VIEW_COOKIE]) && empty($req['head']) && !preg_match(SHARE_BOT_UA, (string) ($req['ua'] ?? ''))) {
        db_exec('UPDATE share_links SET views = views + 1, last_viewed_at = ? WHERE id = ?', [now_utc(), $link['id']]);
        $cookies[] = share_cookie(SHARE_VIEW_COOKIE, '1', '/s/' . $token, 6 * 3600, !empty($req['https']));
    }
    return share_res_html(200, share_gallery_html($token, $link, $req), $cookies);
}

function share_unlock_post(string $token, array $req): array
{
    $g = share_gate($token, $req, true);
    if (isset($g['res'])) {
        return $g['res'];
    }
    $link = $g['link'];
    if ($link['password_hash'] === null) {
        return ['status' => 303, 'headers' => share_html_headers() + ['Location' => '/s/' . $token], 'body' => ''];
    }
    $ip = (string) ($req['ip'] ?? '0.0.0.0');
    $kIp = 'share-pw:' . $link['id'] . ':' . $ip;
    $kLink = 'share-pw-link:' . $link['id'];
    // Önce say, sonra denetle (sayaç artışı atomik): eşzamanlı bir istek seli sınırı aşamasın.
    share_rl_hit($kIp, 900);
    share_rl_hit($kLink, 3600);
    if (share_rl_blocked($kIp, 9, 900) || share_rl_blocked($kLink, 41, 3600)) {
        return share_res_html(429, share_password_html($token, 'limited'), [], ['Retry-After' => '900']);
    }
    $pw = (string) ($req['post']['password'] ?? '');
    if ($pw === '' || strlen($pw) > 400 || !password_verify($pw, (string) $link['password_hash'])) {
        usleep(random_int(150000, 350000)); // yavaşlat; süre yanıta bilgi sızdırmasın
        return share_res_html(401, share_password_html($token, 'wrong'));
    }
    $maxAge = 30 * 86400;
    if ($link['expires_at'] !== null) {
        $maxAge = max(60, min($maxAge, (int) strtotime($link['expires_at'] . ' UTC') - time()));
    }
    $exp = time() + $maxAge;
    $c = share_cookie(SHARE_UNLOCK_COOKIE, $exp . '.' . share_unlock_mac($link, $exp), '/s/' . $token, $maxAge, !empty($req['https']));
    return ['status' => 303, 'headers' => share_html_headers() + ['Location' => '/s/' . $token], 'cookies' => [$c], 'body' => ''];
}

/* ---------------------------------------------------------------------- medya */

function share_media(string $token, string $key, string $variant, array $req): array
{
    $g = share_gate($token, $req, false);
    if (isset($g['res'])) {
        return $g['res'];
    }
    $link = $g['link'];
    if (!share_unlocked($link, (array) ($req['cookies'] ?? []))) {
        return share_res_text(401, 'Locked');
    }
    $assetId = share_item_asset($token, $key);
    $a = $assetId !== null ? share_item_row($link, $assetId) : null;
    if ($a === null) {
        return share_res_text(404, 'Not found');
    }
    $hide = !(int) $link['show_location'];
    $h = share_base_headers();
    if ($variant === 't' || $variant === 'p') {
        $p = derived_find($a['id'], $variant === 't' ? 'thumb' : 'preview') ?? derived_find($a['id'], 'thumb');
        if ($p === null) {
            return ['status' => 200, 'headers' => $h + ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-store'], 'body' => api_placeholder_svg($a['type'])];
        }
        return ['status' => 200, 'headers' => $h, 'file' => ['path' => $p, 'mime' => str_ends_with($p, '.webp') ? 'image/webp' : 'image/jpeg', 'cache' => 'private, max-age=3600', 'download' => null]];
    }
    if ($variant === 'v') {
        if ($a['type'] !== 'video') {
            return share_res_text(404, 'Not found');
        }
        $p = $hide ? share_video_clean($a, 'web') : (derived_find_video($a['id']) ?? (share_video_playable($a) ? blob_path($a['blob_sha1']) : null));
        if ($p === null || !is_file($p)) {
            return share_res_text(503, 'Video unavailable');
        }
        $mime = str_ends_with($p, '.mov') ? 'video/quicktime' : (str_ends_with($p, '.mp4') ? 'video/mp4' : ($a['mime'] ?: 'video/mp4'));
        return ['status' => 200, 'headers' => $h, 'file' => ['path' => $p, 'mime' => $mime, 'cache' => 'private, max-age=3600', 'download' => null]];
    }
    // o: orijinal
    if (!(int) $link['allow_download']) {
        return share_res_text(403, 'Download disabled');
    }
    $name = share_safe_filename((string) $a['original_filename'], 'foto');
    if (!$hide) {
        $p = blob_path($a['blob_sha1']);
        return is_file($p)
            ? ['status' => 200, 'headers' => $h, 'file' => ['path' => $p, 'mime' => $a['mime'] ?: 'application/octet-stream', 'cache' => 'private, no-store', 'download' => $name]]
            : share_res_text(404, 'Not found');
    }
    if ($a['type'] === 'video') {
        $p = share_video_clean($a, 'orig');
        if ($p === null) {
            return share_res_text(503, 'Video unavailable');
        }
        return ['status' => 200, 'headers' => $h, 'file' => ['path' => $p, 'mime' => $a['mime'] ?: 'video/mp4', 'cache' => 'private, no-store', 'download' => share_download_name($name, $p)]];
    }
    $src = share_clean_photo_source($a, $name);
    if ($src === null) {
        return share_res_text(503, 'Unavailable');
    }
    return ['status' => 200, 'headers' => $h + [
        'Content-Type' => $src['mime'],
        'Cache-Control' => 'private, no-store',
        'Content-Disposition' => share_disposition($src['name']),
    ], 'stream' => function (callable $write) use ($src) {
        [$fh, $close, $head] = $src['open'];
        if ($head !== '') {
            $write($head);
        }
        while (!feof($fh)) {
            $buf = fread($fh, 1048576);
            if ($buf === false || $buf === '') {
                if (feof($fh)) {
                    break;
                }
                continue;
            }
            $write($buf);
        }
        $close();
    }];
}

/** Uzantı, sunulan dosyaya uysun (ör. .MOV → metadatasız .mp4). */
function share_download_name(string $name, string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $cur = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== '' && $cur !== $ext && !($ext === 'jpg' && $cur === 'jpeg')) {
        return pathinfo($name, PATHINFO_FILENAME) . '.' . $ext;
    }
    return $name;
}

/**
 * Konumu gizli fotoğraf kaynağı (hemen açılır): GPS'i silinmiş orijinal; araç yoksa veya exiftool dosyayı
 * yazamazsa (BMP, bozuk dosya) metadatasız önizleme türevi. Dönüş: ['open' => [kaynak, kapat, ilk parça],
 * 'mime', 'name'] veya null (hiçbiri yok).
 */
function share_clean_photo_source(array $a, string $name): ?array
{
    $o = share_photo_clean_open($a);
    if ($o !== null) {
        return ['open' => $o, 'mime' => $a['mime'] ?: 'application/octet-stream', 'name' => $name];
    }
    $p = derived_find($a['id'], 'preview') ?? derived_find($a['id'], 'thumb');
    $f = $p !== null ? @fopen($p, 'rb') : false;
    if ($f === false) {
        return null;
    }
    $ext = str_ends_with((string) $p, '.webp') ? 'webp' : 'jpg';
    return ['open' => [$f, fn() => fclose($f), ''], 'mime' => $ext === 'webp' ? 'image/webp' : 'image/jpeg', 'name' => pathinfo($name, PATHINFO_FILENAME) . '.' . $ext];
}

function share_disposition(string $name): string
{
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    return "attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name);
}

/* ------------------------------------------------------------------------ zip */

function share_zip(string $token, array $req): array
{
    $g = share_gate($token, $req, false);
    if (isset($g['res'])) {
        return $g['res'];
    }
    $link = $g['link'];
    if (!share_unlocked($link, (array) ($req['cookies'] ?? []))) {
        return share_res_text(401, 'Locked');
    }
    if (!(int) $link['allow_download']) {
        return share_res_text(403, 'Download disabled');
    }
    $items = share_items($link);
    if (!$items) {
        return share_res_text(404, 'Not found');
    }
    $hide = !(int) $link['show_location'];
    $zipName = share_safe_filename(($link['title'] !== '' ? $link['title'] : 'Fotograflar'), 'Fotograflar') . '.zip';
    return ['status' => 200, 'headers' => share_base_headers() + [
        'Content-Type' => 'application/zip',
        'Cache-Control' => 'private, no-store',
        'Content-Disposition' => share_disposition($zipName),
    ], 'stream' => function (callable $write) use ($items, $hide) {
        $used = [];
        $entries = (function () use ($items, $hide, &$used) {
            foreach ($items as $a) {
                $name = share_safe_filename((string) $a['original_filename'], 'foto');
                $open = null;
                if (!$hide) {
                    $p = blob_path($a['blob_sha1']);
                    $open = fn() => is_file($p) ? fopen($p, 'rb') : null;
                } elseif ($a['type'] === 'video') {
                    $open = function () use ($a) {
                        $p = share_video_clean($a, 'orig');
                        return $p !== null ? fopen($p, 'rb') : null;
                    };
                    $p = derived_path($a['id'], 'share-orig', in_array(strtolower(pathinfo((string) $a['original_filename'], PATHINFO_EXTENSION)), ['mov', 'qt'], true) ? 'mov' : 'mp4');
                    $name = share_download_name($name, $p);
                } else {
                    $src = share_clean_photo_source($a, $name);
                    if ($src === null) {
                        continue;
                    }
                    $name = $src['name'];
                    $open = fn() => $src['open'];
                }
                // Aynı adlı dosyalar: "IMG_1 (2).jpg"
                $base = $name;
                $i = 1;
                while (isset($used[mb_strtolower($name)])) {
                    $i++;
                    $name = pathinfo($base, PATHINFO_FILENAME) . " ($i)" . (pathinfo($base, PATHINFO_EXTENSION) !== '' ? '.' . pathinfo($base, PATHINFO_EXTENSION) : '');
                }
                $used[mb_strtolower($name)] = true;
                $ts = (int) strtotime(($a['taken_at'] ?? now_utc()) . ' UTC') + (int) ($a['taken_tz_offset'] ?? 0) * 60;
                yield ['name' => $name, 'mtime' => $ts, 'open' => $open, 'size_hint' => (int) $a['size']];
            }
        })();
        share_zip_write($entries, $write);
    }];
}

/* ---------------------------------------------------------------- og görseli */

function share_og_image(string $token, array $req): array
{
    $g = share_gate($token, $req, false);
    if (isset($g['res'])) {
        return $g['res'];
    }
    $link = $g['link'];
    if ($link['password_hash'] !== null) {
        return share_res_text(404, 'Not found');
    }
    $items = share_items($link);
    $cover = share_cover_id($link, $items);
    $src = $cover !== null ? (derived_find($cover, 'preview') ?? derived_find($cover, 'thumb')) : null;
    if ($src === null) {
        return share_res_text(404, 'Not found');
    }
    $dest = derived_path($cover, 'og', 'jpg');
    if (!is_file($dest) || filemtime($dest) < filemtime($src)) {
        if (media_render($src, 'photo', $dest, 1200, 'jpeg') !== 'ready') {
            return ['status' => 200, 'headers' => share_base_headers(), 'file' => ['path' => $src, 'mime' => str_ends_with($src, '.webp') ? 'image/webp' : 'image/jpeg', 'cache' => 'private, max-age=3600', 'download' => null]];
        }
    }
    return ['status' => 200, 'headers' => share_base_headers(), 'file' => ['path' => $dest, 'mime' => 'image/jpeg', 'cache' => 'private, max-age=3600', 'download' => null]];
}

/* ------------------------------------------------------------------ HTML */

function share_asset_url(string $file): string
{
    $p = OM_WEB . '/s/assets/' . $file;
    return '/s/assets/' . $file . (is_file($p) ? '?v=' . filemtime($p) : '');
}

function share_origin(array $req): string
{
    $cfg = (string) config('shares.public_url', '');
    if (preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?#', $cfg, $m)) {
        return $m[0];
    }
    $host = (string) ($req['host'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host)) {
        return '';
    }
    return (!empty($req['https']) ? 'https://' : 'http://') . $host;
}

function share_doc(string $title, string $body, string $bodyClass, string $head = ''): string
{
    $css = e(share_asset_url('share.css'));
    $icon = e(share_asset_url('icon.svg'));
    return '<!doctype html><html lang="tr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="robots" content="noindex, nofollow, noarchive, noimageindex">'
        . '<meta name="referrer" content="no-referrer">'
        . '<meta name="color-scheme" content="light">'
        . '<meta name="theme-color" content="#f6f5f1">'
        . '<title>' . e($title) . '</title>'
        . '<link rel="icon" href="' . $icon . '" type="image/svg+xml">'
        . '<link rel="stylesheet" href="' . $css . '">' . $head
        . '</head><body class="' . e($bodyClass) . '">' . $body . '</body></html>';
}

const SHARE_STATES = [
    'not_found' => ['Bağlantı bulunamadı', 'Adres eksik ya da hatalı olabilir. Bağlantıyı size gönderen kişiden yenisini isteyin.'],
    'expired' => ['Bu bağlantının süresi dolmuş', 'Paylaşım süresi sona erdi. Fotoğrafları yeniden görmek için gönderen kişiden yeni bir bağlantı isteyin.'],
    'revoked' => ['Bu bağlantı artık geçerli değil', 'Paylaşan kişi bu bağlantıyı kapattı. İsterseniz kendisinden yeni bir bağlantı isteyebilirsiniz.'],
    'limited' => ['Çok fazla deneme', 'Güvenliğiniz için kısa bir süre bekletiyoruz. Lütfen birkaç dakika sonra yeniden deneyin.'],
    'error' => ['Bir sorun oluştu', 'Sayfa şu an açılamadı. Lütfen biraz sonra yeniden deneyin.'],
];

function share_state_html(string $state): string
{
    [$h, $p] = SHARE_STATES[$state] ?? SHARE_STATES['not_found'];
    $icon = $state === 'expired'
        ? '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>'
        : ($state === 'limited' ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6L12 3Z"/><path d="M12 8v4.6M12 15.8v.1"/></svg>'
        : '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="m4 16 4.5-4 3.5 3 3-2.5 5 4.5"/><path d="M3 3l18 18"/></svg>');
    $body = '<main class="state" role="main"><div class="state-card" data-state="' . e($state) . '">'
        . '<div class="state-ic">' . $icon . '</div><h1>' . e($h) . '</h1><p>' . e($p) . '</p></div>'
        . share_footer_html(null) . '</main>';
    return share_doc($h, $body, 'is-state');
}

function share_password_html(string $token, ?string $error): string
{
    $msg = match ($error) {
        'wrong' => 'Parola yanlış. Lütfen yeniden deneyin.',
        'limited' => 'Çok fazla yanlış deneme yapıldı. Lütfen 15 dakika sonra yeniden deneyin.',
        default => '',
    };
    $body = '<main class="state" role="main"><form class="state-card lock" method="post" action="/s/' . e($token) . '" autocomplete="on">'
        . '<div class="state-ic"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/><path d="M12 14.5v2"/></svg></div>'
        . '<h1>Parolayla korunan paylaşım</h1>'
        . '<p>Fotoğrafları görmek için size iletilen parolayı yazın.</p>'
        . '<label class="field" for="pw"><span>Parola</span>'
        . '<input id="pw" name="password" type="password" autocomplete="current-password" required maxlength="200" autofocus'
        . ($msg !== '' ? ' aria-invalid="true" aria-describedby="pw-err"' : '') . '></label>'
        . '<p class="err" id="pw-err" role="alert"' . ($msg === '' ? ' hidden' : '') . '>' . e($msg) . '</p>'
        . '<button class="btn primary" type="submit"' . ($error === 'limited' ? ' disabled' : '') . '>Fotoğrafları aç</button>'
        . '</form>' . share_footer_html(null) . '</main>';
    return share_doc('Parolayla korunan paylaşım', $body, 'is-state is-lock');
}

function share_footer_html(?array $link): string
{
    $exp = '';
    if ($link !== null && $link['expires_at'] !== null) {
        $ts = (int) strtotime($link['expires_at'] . ' UTC') + 3 * 3600; // Türkiye saati
        $d = (int) gmdate('j', $ts) . ' ' . SHARE_MONTHS_TR[(int) gmdate('n', $ts) - 1] . ' ' . gmdate('Y', $ts);
        $exp = '<span>Bağlantı ' . e($d) . ' tarihine kadar açık</span>';
    }
    return '<footer class="foot"><span class="foot-brand"><img src="' . e(share_asset_url('icon.svg')) . '" alt="" width="16" height="16">OwnMemories ile özel olarak paylaşıldı</span>' . $exp . '</footer>';
}

/** Izgarada büyük karolar için önizleme (2048) seçilebilsin: küçük resim ve önizlemenin gerçek genişlikleri. */
function share_srcset(string $base, string $k, int $w, int $h): string
{
    $long = max($w, $h, 1);
    $tw = (int) max(1, round(min(THUMB_EDGE, $long) * $w / $long));
    $pw = (int) max(1, round(min(PREVIEW_EDGE, $long) * $w / $long));
    if ($pw <= $tw * 1.2) {
        return '';
    }
    return ' srcset="' . e("$base/m/$k/t") . " {$tw}w, " . e("$base/m/$k/p") . " {$pw}w" . '" sizes="(max-width: 720px) 50vw, 300px"';
}

/** Sunucunun HTML olarak verdiği karo sayısı; gerisini share.js ekler. */
const SHARE_SSR_TILES = 240;

function share_gallery_html(string $token, array $link, array $req): string
{
    if (share_count($link) > 20000) {
        @ini_set('memory_limit', '512M'); // çok büyük albümler (yüz binlerce öğe) 128 MB'a sığmaz
    }
    $items = share_items($link);
    $base = '/s/' . $token;
    $owner = share_first_name((string) db_val('SELECT name FROM users WHERE id = ?', [$link['user_id']]));
    $title = $link['title'] !== '' ? $link['title'] : 'Fotoğraflar';
    $photos = $videos = 0;
    $bytes = 0;
    $days = [];
    $places = [];
    if ((int) $link['show_location'] && $items) {
        $ids = array_column($items, 'id');
        foreach (db_all('SELECT asset_id, name, city, district, country FROM asset_places WHERE asset_id IN (' . db_in($ids) . ')', $ids) as $p) {
            $parts = array_values(array_unique(array_filter([$p['district'] ?: $p['name'], $p['city'], $p['country'] !== 'Türkiye' ? $p['country'] : null])));
            $places[$p['asset_id']] = implode(', ', $parts);
        }
    }
    $data = [];
    $tiles = '';
    foreach ($items as $i => $a) {
        $k = share_item_key($token, $a['id']);
        $isVideo = $a['type'] === 'video';
        $isVideo ? $videos++ : $photos++;
        $bytes += (int) $a['size'];
        $day = share_local_day($a);
        if ($day !== null) {
            $days[] = $day;
        }
        $w = max(1, (int) ($a['width'] ?: 4));
        $hgt = max(1, (int) ($a['height'] ?: 3));
        $label = $day !== null ? share_date_range($day, $day) : '';
        $item = ['k' => $k, 't' => $isVideo ? 'v' : 'p', 'w' => $w, 'h' => $hgt, 'd' => $label, 'tm' => share_local_time($a)];
        if ($isVideo && $a['duration_ms'] !== null) {
            $item['dur'] = (int) round($a['duration_ms'] / 1000);
        }
        if (isset($places[$a['id']]) && $places[$a['id']] !== '') {
            $item['pl'] = $places[$a['id']];
        }
        $data[] = $item;
        if ($i >= SHARE_SSR_TILES) {
            continue; // kalan karoları share.js kaydırdıkça ekler (büyük albümlerde sayfa hafif kalır)
        }
        $alt = ($isVideo ? 'Video' : 'Fotoğraf') . ' ' . ($i + 1) . ($label !== '' ? ', ' . $label : '');
        $dur = $isVideo && isset($item['dur']) ? sprintf('%d:%02d', intdiv($item['dur'], 60), $item['dur'] % 60) : '';
        $tiles .= '<a class="tile' . ($isVideo ? ' is-video' : '') . '" href="' . e("$base/m/$k/p") . '" data-i="' . $i . '" aria-label="' . e($alt) . '">'
            . '<img src="' . e("$base/m/$k/t") . '"' . share_srcset($base, $k, $w, $hgt) . ' width="' . $w . '" height="' . $hgt . '" alt="" loading="lazy" decoding="async">'
            . ($isVideo ? '<span class="tile-badge"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5Z"/></svg>' . e($dur) . '</span>' : '')
            . '</a>';
    }
    sort($days);
    $range = $days ? share_date_range($days[0], end($days)) : '';
    $counts = implode(' · ', array_filter([$photos ? $photos . ' fotoğraf' : '', $videos ? $videos . ' video' : '']));
    $cover = share_cover_id($link, $items);
    $coverKey = $cover !== null ? share_item_key($token, $cover) : null;
    $allow = (bool) (int) $link['allow_download'];
    $json = json_encode([
        'base' => $base, 'title' => $title, 'dl' => $allow, 'te' => THUMB_EDGE, 'pe' => PREVIEW_EDGE, 'items' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $eyebrow = $owner !== '' ? $owner . ' sizinle paylaştı' : 'Sizinle paylaşıldı';
    $hero = '<header class="hero' . ($coverKey ? '' : ' no-cover') . '">'
        . ($coverKey ? '<div class="hero-media"><img src="' . e("$base/m/$coverKey/p") . '" alt="" decoding="async" fetchpriority="high"></div><div class="hero-shade"></div>' : '')
        . '<div class="hero-text"><p class="eyebrow">' . e($eyebrow) . '</p><h1>' . e($title) . '</h1>'
        . '<p class="meta">' . ($range !== '' ? '<span>' . e($range) . '</span>' : '') . ($counts !== '' ? '<span>' . e($counts) . '</span>' : '') . '</p>'
        . ($allow && $items ? '<div class="hero-actions"><a class="btn primary" href="' . e("$base/zip") . '" download><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 19h14"/></svg>Tümünü indir<small>' . e(format_bytes($bytes)) . '</small></a></div>' : '')
        . '</div></header>';
    $grid = $items
        ? '<main class="gallery" id="gallery" aria-label="Fotoğraflar"><div class="grid" id="grid">' . $tiles . '</div></main>'
        : '<main class="gallery"><p class="empty">Bu paylaşımda henüz fotoğraf yok.</p></main>';
    $body = $hero . $grid . share_footer_html($link)
        . '<script type="application/json" id="share-data">' . $json . '</script>'
        . '<script src="' . e(share_asset_url('share.js')) . '" defer></script>';

    $origin = share_origin($req);
    $desc = ($owner !== '' ? $owner . ' · ' : '') . implode(' · ', array_filter([$counts, $range]));
    $head = '<meta property="og:type" content="website"><meta property="og:site_name" content="OwnMemories">'
        . '<meta property="og:title" content="' . e($title) . '"><meta property="og:description" content="' . e($desc) . '">'
        . '<meta name="description" content="' . e($desc) . '">';
    if ($cover !== null && $link['password_hash'] === null && $origin !== '') {
        $head .= '<meta property="og:image" content="' . e("$origin$base/og.jpg") . '"><meta property="og:image:type" content="image/jpeg">'
            . '<meta name="twitter:card" content="summary_large_image">';
    }
    return share_doc($title . ($owner !== '' ? ' · ' . $owner : ''), $body, 'is-gallery', $head);
}
