<?php
/**
 * OwnMemories — herkese açık paylaşım ön denetleyicisi (/s/...). Yalnızca paylaşım bağlantılarını sunar;
 * oturum açmaz, ana uygulamaya/API'ye/yönetime erişim vermez. İşleyici: web/includes/share_public.php.
 *
 * Üç biçimde çalışır:
 *   - Ana Apache sitesi (tailnet içi): web/s/.htaccess her isteği buraya yönlendirir.
 *   - Ayrı paylaşım sitesi (Tailscale Funnel ile dışarı açılan tek port): DocumentRoot = web/s,
 *     server/apache-ownmemories-share.conf (server/setup-share.sh kurar).
 *   - Geliştirme:  php -S 127.0.0.1:8186 -t web/s web/s/index.php
 */
declare(strict_types=1);

define('OM_SHARE', true);
require dirname(__DIR__) . '/includes/core.php';

header_remove('X-Powered-By');
if (!is_installed()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not available';
    exit;
}
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', storage_path('logs/php-error.log'));
migrations_run();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    exit;
}
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$res = share_http([
    'method' => $method === 'HEAD' ? 'GET' : $method,
    'head' => $method === 'HEAD',
    'path' => (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'),
    'cookies' => $_COOKIE,
    'post' => $method === 'POST' ? $_POST : [],
    // Yalnızca REMOTE_ADDR: paylaşım sitesinde mod_remoteip yerel vekilin (tailscaled) X-Forwarded-For'unu
    // uygular; istemcinin gönderdiği CF-Connecting-IP gibi başlıklara güvenilmez (hız sınırı atlatılmasın).
    'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
    'https' => $https,
    'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
    'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
]);

http_response_code($res['status']);
foreach ($res['headers'] ?? [] as $k => $v) {
    header($k . ': ' . $v);
}
foreach ($res['cookies'] ?? [] as $c) {
    setcookie($c['name'], $c['value'], ['expires' => $c['expires'], 'path' => $c['path'], 'secure' => $c['secure'], 'httponly' => $c['httponly'], 'samesite' => $c['samesite']]);
}
if (isset($res['file'])) {
    serve_file($res['file']['path'], $res['file']['mime'], $res['file']['cache'], $res['file']['download'] ?? null);
}
if (isset($res['stream'])) {
    if ($method === 'HEAD') {
        exit;
    }
    @set_time_limit(0);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ($res['stream'])(function (string $chunk) {
        echo $chunk;
        flush();
    });
    exit;
}
if ($method !== 'HEAD') {
    echo $res['body'] ?? '';
}
