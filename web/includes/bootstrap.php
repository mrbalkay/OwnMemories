<?php
/**
 * OwnMemories — her sayfanın başlangıcı:  require __DIR__ . '/includes/bootstrap.php';
 *
 * Yapılandırmayı yükler (kurulmamışsa /setup.php'ye yönlendirir), migrasyonları uygular,
 * güvenli oturumu başlatır, güvenlik başlıklarını (nonce tabanlı CSP) gönderir.
 *
 * Bu dosyadan ÖNCE tanımlanabilen sabitler:
 *   OM_API    JSON API isteği: yönlendirme yok, oturum yalnız çerez varsa
 *   OM_ADMIN  yönetim paneli sayfası
 */
declare(strict_types=1);

require_once __DIR__ . '/core.php';

$__cli = PHP_SAPI === 'cli';
$__api = defined('OM_API');

if (!is_installed()) {
    if ($__cli) {
        fwrite(STDERR, "OwnMemories henüz kurulmadı. Tarayıcıda /setup.php adresini açın.\n");
        exit(1);
    }
    if ($__api) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => 'not_installed', 'message' => 'OwnMemories is not installed yet.']]);
        exit;
    }
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $prefix = rtrim((string) preg_replace('#/(admin|api|app)(/.*)?$#', '', dirname($script)), '/');
    header('Location: ' . $prefix . '/setup.php', true, 302);
    exit;
}

$__debug = (bool) config('app.debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $__debug && !$__api && !$__cli ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', storage_path('logs/php-error.log'));

migrations_run();

if (!$__cli) {
    if (!$__api || isset($_COOKIE['om_sid'])) {
        session_start_secure();
    }
    if (!empty($_SESSION['locale'])) {
        locale((string) $_SESSION['locale']);
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (!$__api) {
        $n = csp_nonce();
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$n' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: blob: https://tiles.openfreemap.org https://cdnjs.cloudflare.com; media-src 'self' blob:; connect-src 'self' https://tiles.openfreemap.org; worker-src 'self' blob:; child-src blob:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    }
}
unset($__cli, $__api, $__debug);
