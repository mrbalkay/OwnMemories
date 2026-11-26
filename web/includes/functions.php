<?php
/**
 * OwnMemories — çekirdek yardımcılar: yapılandırma, veritabanı, kaçış, URL, oturum,
 * CSRF, CSP, flash mesajları, hız sınırı, günlük, küçük araçlar.
 */
declare(strict_types=1);

final class ValidationError extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}

/* ------------------------------------------------------------------ config */

/** Ham yapılandırma dizisi (config/config.php). Kurulmamışsa boş dizi. */
function config_all(?array $set = null): array
{
    static $cfg = null;
    if ($set !== null) {
        $cfg = $set;
    }
    if ($cfg === null) {
        $file = OM_WEB . '/config/config.php';
        $cfg = is_file($file) ? (array) (require $file) : [];
    }
    return $cfg;
}

/** Nokta notasyonlu yapılandırma: config('db.host'). */
function config(string $key, $default = null)
{
    $node = config_all();
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }
    return $node;
}

function is_installed(): bool
{
    return is_file(OM_WEB . '/config/config.php') && is_file(OM_WEB . '/config/installed.lock');
}

/** Medya deposunun kökü (web kökü dışında olmalı). */
function storage_root(): string
{
    $p = (string) config('storage.path', OM_ROOT . '/storage');
    return rtrim($p, '/');
}

/** Depo altında bir yol; klasörü yoksa oluşturur. */
function storage_path(string $sub = ''): string
{
    $base = storage_root();
    $path = $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
    $dir = pathinfo($path, PATHINFO_EXTENSION) !== '' ? dirname($path) : $path;
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $path;
}

/* ---------------------------------------------------------------------- db */

/** Paylaşılan PDO. Testler bir bağlantı verebilir: db($pdo). */
function db(?PDO $set = null): PDO
{
    static $pdo = null;
    if ($set !== null) {
        $pdo = $set;
    }
    if ($pdo === null) {
        $pdo = db_connect([
            'host' => config('db.host', '127.0.0.1'),
            'port' => (int) config('db.port', 3306),
            'name' => config('db.name', ''),
            'user' => config('db.user', ''),
            'pass' => config('db.pass', ''),
        ]);
    }
    return $pdo;
}

function db_connect(array $c): PDO
{
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int) ($c['port'] ?? 3306) . ';charset=utf8mb4';
    if (!empty($c['name'])) {
        $dsn .= ';dbname=' . $c['name'];
    }
    $pdo = new PDO($dsn, (string) $c['user'], (string) $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00', NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $pdo;
}

/** Tek satır veya null. */
function db_row(string $sql, array $args = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function db_all(string $sql, array $args = []): array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function db_val(string $sql, array $args = [])
{
    $st = db()->prepare($sql);
    $st->execute($args);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/** INSERT/UPDATE/DELETE; etkilenen satır sayısı. */
function db_exec(string $sql, array $args = []): int
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

/** IN (...) için yer tutucular. */
function db_in(array $values): string
{
    return implode(',', array_fill(0, max(1, count($values)), '?'));
}

/* ----------------------------------------------------------- site settings */

function site_setting_cache_clear(): void
{
    $GLOBALS['__om_site_settings'] = null;
}

function site_setting(string $key, $default = null)
{
    if (!isset($GLOBALS['__om_site_settings'])) {
        try {
            $GLOBALS['__om_site_settings'] = db()->query('SELECT `key`, value FROM site_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            return $default;
        }
    }
    return $GLOBALS['__om_site_settings'][$key] ?? $default;
}

function site_setting_set(string $key, ?string $value): void
{
    db_exec('INSERT INTO site_settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, $value]);
    site_setting_cache_clear();
}

/* ------------------------------------------------------------ small utils */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

function is_uuid(string $s): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $s);
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function app_log(string $channel, string $msg): void
{
    $file = storage_path('logs/' . preg_replace('/[^a-z0-9_-]/i', '', $channel) . '.log');
    @file_put_contents($file, '[' . gmdate('c') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function format_bytes(int|float $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($u) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) (int) $bytes : number_format((float) $bytes, 1, ',', '.')) . ' ' . $u[$i];
}

/* ------------------------------------------------------------------- urls */

function url(string $path = ''): string
{
    $base = rtrim((string) config('app.url', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $path = '/assets/' . ltrim($path, '/');
    $file = OM_WEB . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/** Giriş sonrası yönlendirme hedefi: yalnız bu sitedeki göreli yollar. */
function safe_next(string $next, string $default = '/app/'): string
{
    if ($next === '' || $next[0] !== '/' || (isset($next[1]) && ($next[1] === '/' || $next[1] === '\\')) || preg_match('/[\x00-\x1F\\\\]/', $next)) {
        return $default;
    }
    return $next;
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 302);
    exit;
}

/* ---------------------------------------------------------------- session */

function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('om_sid');
    session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
    ini_set('session.use_strict_mode', '1');
    $dir = storage_path('sessions');
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Form alanı veya X-CSRF-Token başlığı; geçersizse 419. */
function csrf_verify(): void
{
    $sent = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(419);
        if (defined('OM_API')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => ['code' => 'csrf_failed', 'message' => t('common.csrf_failed')]]);
        } else {
            echo e(t('common.csrf_failed'));
        }
        exit;
    }
}

function csp_nonce(): string
{
    static $n = null;
    return $n ??= base64_encode(random_bytes(16));
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** true = izin var. Anahtar başına pencere içinde en fazla $max deneme. */
function rate_limit(string $key, int $max, int $windowSec): bool
{
    $key = hash('sha256', $key);
    $now = time();
    $row = db_row('SELECT hits, window_start FROM rate_limits WHERE `key` = ?', [$key]);
    if ($row === null || $now - (int) $row['window_start'] >= $windowSec) {
        db_exec('REPLACE INTO rate_limits (`key`, hits, window_start) VALUES (?, 1, ?)', [$key, $now]);
        return true;
    }
    if ((int) $row['hits'] >= $max) {
        return false;
    }
    db_exec('UPDATE rate_limits SET hits = hits + 1 WHERE `key` = ?', [$key]);
    return true;
}

function client_ip(): string
{
    return (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function audit(?int $userId, string $action, string $details = ''): void
{
    try {
        db_exec('INSERT INTO audit_log (user_id, action, details, created_at) VALUES (?, ?, ?, ?)', [$userId, $action, mb_substr($details, 0, 2000), now_utc()]);
    } catch (Throwable $e) {
        app_log('app', 'audit failed: ' . $e->getMessage());
    }
}
