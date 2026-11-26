<?php
/**
 * OwnMemories REST API ön denetleyicisi — /api/v1/...
 * .htaccess ile (/api/v1/* → api/index.php?r=/v1/...) veya doğrudan api/index.php?r=/v1/... ile çağrılır.
 * Sözleşme: docs/ARCHITECTURE.md
 */
declare(strict_types=1);

define('OM_API', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function (Throwable $e) {
    if ($e instanceof ApiError) {
        json_out(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra], $e->status);
    }
    app_log('api', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['error' => ['code' => 'server_error', 'message' => t('common.server_error')]], 500);
});

$route = $_GET['r'] ?? null;
if (!is_string($route) || $route === '') {
    $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $route = preg_match('#/api(/v\d+/.*)$#', $uri, $m) ? $m[1] : '/';
}
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'HEAD') {
    $method = 'GET';
}
$query = $_GET;
unset($query['r']);

// Kimlik: Bearer (iOS) veya oturum çerezi (web; durum değiştiren isteklerde CSRF zorunlu).
$user = null;
if (api_bearer_token() !== null) {
    $user = api_user_from_bearer();
    if ($user === null) {
        throw new ApiError(401, 'invalid_token', 'Oturum geçersiz; yeniden giriş yapın.');
    }
    $GLOBALS['__om_auth'] = 'bearer';
} elseif (session_status() === PHP_SESSION_ACTIVE && current_user() !== null) {
    $user = current_user();
    $GLOBALS['__om_auth'] = 'session';
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        csrf_verify();
    }
}

$body = [];
$stream = null;
if ($method === 'PUT') {
    $stream = fopen('php://input', 'rb');
} elseif (in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
    $raw = (string) file_get_contents('php://input');
    if ($raw !== '') {
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw new ApiError(400, 'invalid_json', 'Geçersiz JSON gövdesi.');
        }
    }
}

$res = api_route($method, $route, $user, $body, $query, $stream);
if (isset($res['file'])) {
    serve_file($res['file']['path'], $res['file']['mime'], $res['file']['cache'], $res['file']['download'] ?? null);
}
if (isset($res['svg'])) {
    http_response_code($res['status']);
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-store');
    echo $res['svg'];
    exit;
}
json_out($res['json'], $res['status']);
