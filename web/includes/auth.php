<?php
/**
 * OwnMemories — hesaplar, web oturumu ve API token'ları.
 * Herkese açık kayıt yoktur; kullanıcıları yönetici oluşturur.
 */
declare(strict_types=1);

const OM_MIN_PASSWORD = 10;

function user_create(string $email, string $password, string $name, bool $isAdmin = false, ?int $quotaBytes = null): int
{
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ValidationError(t('auth.invalid_email'), 'email');
    }
    if (mb_strlen($password) < OM_MIN_PASSWORD) {
        throw new ValidationError(t('auth.weak_password', ['n' => OM_MIN_PASSWORD]), 'password');
    }
    if (db_val('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
        throw new ValidationError(t('auth.email_taken'), 'email');
    }
    db_exec('INSERT INTO users (email, password_hash, name, is_admin, quota_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
        $email, password_hash($password, PASSWORD_DEFAULT), trim($name), $isAdmin ? 1 : 0, $quotaBytes, now_utc(),
    ]);
    return (int) db()->lastInsertId();
}

function user_set_password(int $userId, string $password): void
{
    if (mb_strlen($password) < OM_MIN_PASSWORD) {
        throw new ValidationError(t('auth.weak_password', ['n' => OM_MIN_PASSWORD]), 'password');
    }
    db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
    db_exec("DELETE FROM api_tokens WHERE user_id = ?", [$userId]);
}

function user_find(int $id): ?array
{
    return db_row('SELECT * FROM users WHERE id = ?', [$id]);
}

function user_find_by_email(string $email): ?array
{
    return db_row('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
}

/** Doğru parola ve etkin hesapsa kullanıcı satırı, değilse null. */
function user_verify_password(string $email, string $password): ?array
{
    $u = user_find_by_email($email);
    if ($u === null) {
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // zamanlama eşitleme
        return null;
    }
    if (!password_verify($password, (string) $u['password_hash']) || $u['status'] !== 'active') {
        return null;
    }
    if (password_needs_rehash((string) $u['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    return $u;
}

/** Kullanıcının dışarıya gösterilen hali. */
/** Seçilebilir görünüm temaları; ilki varsayılandır. */
const UI_THEMES = ['sinema', 'galeri', 'yerel'];

function user_public(array $u): array
{
    return [
        'id' => (int) $u['id'],
        'email' => $u['email'],
        'name' => $u['name'],
        'is_admin' => (bool) $u['is_admin'],
        'locale' => $u['locale'],
        'timezone' => $u['timezone'],
        'quota_bytes' => $u['quota_bytes'] !== null ? (int) $u['quota_bytes'] : null,
        'used_bytes' => (int) $u['used_bytes'],
        'theme' => in_array($u['ui_theme'] ?? '', UI_THEMES, true) ? $u['ui_theme'] : 'sinema',
    ];
}

/* --------------------------------------------------------------- session */

function login_user(array $u): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['uid'] = (int) $u['id'];
    $_SESSION['locale'] = $u['locale'];
    db_exec('UPDATE users SET last_seen_at = ? WHERE id = ?', [now_utc(), $u['id']]);
    $GLOBALS['__om_user'] = $u;
}

function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        session_destroy();
    }
    $GLOBALS['__om_user'] = null;
}

function current_user(): ?array
{
    if (array_key_exists('__om_user', $GLOBALS)) {
        return $GLOBALS['__om_user'];
    }
    $uid = (int) ($_SESSION['uid'] ?? 0);
    $u = $uid > 0 ? user_find($uid) : null;
    if ($u !== null && $u['status'] !== 'active') {
        $u = null;
    }
    return $GLOBALS['__om_user'] = $u;
}

function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        redirect('/login.php?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/app/')));
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (!(int) $u['is_admin']) {
        http_response_code(403);
        exit(e(t('common.forbidden')));
    }
    return $u;
}

/* ------------------------------------------------------------ api tokens */

/** Yeni token üretir; düz hali yalnız burada döner, veritabanında sha256 saklanır. */
function api_token_issue(int $userId, string $name, string $platform): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    db_exec('INSERT INTO api_tokens (user_id, token_hash, name, platform, created_at, last_used_at) VALUES (?, ?, ?, ?, ?, ?)', [
        $userId, hash('sha256', $token), mb_substr($name, 0, 120), in_array($platform, ['ios', 'web'], true) ? $platform : 'ios', now_utc(), now_utc(),
    ]);
    return $token;
}

function api_bearer_token(): ?string
{
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $k => $val) {
            if (strcasecmp((string) $k, 'Authorization') === 0) {
                $h = (string) $val;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
}

function api_user_from_bearer(): ?array
{
    $tok = api_bearer_token();
    if ($tok === null) {
        return null;
    }
    $row = db_row('SELECT t.id AS token_id, t.last_used_at, u.* FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND (t.expires_at IS NULL OR t.expires_at > ?)', [hash('sha256', $tok), now_utc()]);
    if ($row === null || $row['status'] !== 'active') {
        return null;
    }
    if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at']) < time() - 300) {
        db_exec('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [now_utc(), $row['token_id']]);
    }
    $GLOBALS['__om_token_id'] = (int) $row['token_id'];
    unset($row['token_id'], $row['last_used_at']);
    return $row;
}

function api_token_revoke_current(): void
{
    if (!empty($GLOBALS['__om_token_id'])) {
        db_exec('DELETE FROM api_tokens WHERE id = ?', [$GLOBALS['__om_token_id']]);
    }
}
