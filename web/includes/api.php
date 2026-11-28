<?php
/**
 * OwnMemories — REST API yönlendiricisi ve uç işleyicileri (/api/v1/...).
 * Sözleşme: docs/ARCHITECTURE.md. api_route() test edilebilir: yanıtı dizi olarak döner.
 *
 * Yanıt biçimleri:
 *   ['status' => 200, 'json' => [...]]
 *   ['status' => 200, 'file' => ['path','mime','cache','download'=>?string]]
 *   ['status' => 202, 'svg' => '<svg…>']  (küçük resim henüz hazır değil)
 */
declare(strict_types=1);

final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message = '', public readonly array $extra = [])
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}

function api_ok(array $json, int $status = 200): array
{
    return ['status' => $status, 'json' => $json];
}

function api_not_found(): never
{
    throw new ApiError(404, 'not_found', t('common.not_found'));
}

function api_need_user(?array $user): array
{
    if ($user === null) {
        throw new ApiError(401, 'unauthorized', 'Giriş gerekli.');
    }
    return $user;
}

function api_bool($v): bool
{
    return $v === true || $v === 1 || $v === '1' || $v === 'true';
}

/** ISO 8601 → 'Y-m-d H:i:s' (UTC) veya null. */
function api_iso_to_utc($v): ?string
{
    if (!is_string($v) || $v === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($v);
    } catch (Throwable $e) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Kimlik doğrulama yöntemi: 'bearer' (iOS) veya 'session' (web). */
function api_auth_kind(): string
{
    return $GLOBALS['__om_auth'] ?? 'bearer';
}

/** Ana yönlendirici. Doğrulama hataları 422 ApiError'a çevrilir. */
function api_route(string $method, string $path, ?array $user, array $body = [], array $query = [], $stream = null): array
{
    try {
        return api_dispatch($method, $path, $user, $body, $query, $stream);
    } catch (ValidationError $e) {
        $code = $e->getMessage() === 'quota_exceeded' ? 'quota_exceeded' : 'validation_failed';
        throw new ApiError($code === 'quota_exceeded' ? 413 : 422, $code, $e->getMessage(), ['field' => $e->field]);
    }
}

function api_dispatch(string $method, string $path, ?array $user, array $body, array $query, $stream): array
{
    $method = strtoupper($method);
    $path = '/' . trim($path, '/');
    $uuid = '([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})';
    $routes = [
        ['GET', '#^/v1/ping$#', fn() => api_ok(['ok' => true, 'version' => OM_VERSION, 'schema' => SCHEMA_VERSION])],
        ['POST', '#^/v1/auth/login$#', fn() => api_login($body)],
        ['POST', '#^/v1/auth/logout$#', fn() => api_logout(api_need_user($user))],
        ['GET', '#^/v1/me$#', fn() => api_ok(['user' => user_public(api_need_user($user))])],
        ['PATCH', '#^/v1/me$#', fn() => api_me_update(api_need_user($user), $body)],
        ['GET', '#^/v1/assets$#', fn() => api_assets_list(api_need_user($user), $query)],
        ['GET', '#^/v1/assets/summary$#', fn() => api_assets_summary(api_need_user($user), $query)],
        ['GET', '#^/v1/assets/days$#', fn() => api_assets_days(api_need_user($user), $query)],
        ['GET', '#^/v1/assets/random$#', fn() => api_assets_random(api_need_user($user), $query)],
        ['GET', '#^/v1/assets/map$#', fn() => api_assets_map(api_need_user($user), $query)],
        ['POST', '#^/v1/assets/check$#', fn() => api_assets_check(api_need_user($user), $body)],
        ['POST', '#^/v1/assets/lookup$#', fn() => api_assets_lookup(api_need_user($user), $body)],
        ['POST', '#^/v1/assets/(trash|restore|purge)$#', fn($m) => api_assets_bulk(api_need_user($user), $m[1], $body)],
        ['GET', "#^/v1/assets/$uuid$#", fn($m) => api_asset_get(api_need_user($user), $m[1])],
        ['PATCH', "#^/v1/assets/$uuid$#", fn($m) => api_asset_patch(api_need_user($user), $m[1], $body)],
        ['GET', "#^/v1/assets/$uuid/(thumb|preview|original|video)$#", fn($m) => api_asset_file(api_need_user($user), $m[1], $m[2])],
        ['POST', '#^/v1/uploads$#', fn() => api_upload_start(api_need_user($user), $body)],
        ['GET', "#^/v1/uploads/$uuid$#", fn($m) => api_ok(['received' => upload_received($m[1], (int) api_need_user($user)['id'])])],
        ['PUT', "#^/v1/uploads/$uuid$#", fn($m) => api_upload_chunk(api_need_user($user), $m[1], $query, $stream)],
        ['POST', "#^/v1/uploads/$uuid/complete$#", fn($m) => api_ok(upload_complete($m[1], (int) api_need_user($user)['id']))],
        ['GET', '#^/v1/albums$#', fn() => api_ok(['albums' => album_list((int) api_need_user($user)['id'])])],
        ['POST', '#^/v1/albums$#', fn() => api_album_create(api_need_user($user), $body)],
        ['GET', "#^/v1/albums/$uuid$#", fn($m) => api_ok(['album' => album_detail($m[1], (int) api_need_user($user)['id'])])],
        ['PATCH', "#^/v1/albums/$uuid$#", fn($m) => api_album_update(api_need_user($user), $m[1], $body)],
        ['DELETE', "#^/v1/albums/$uuid$#", fn($m) => api_ok(['ok' => album_delete($m[1], (int) api_need_user($user)['id'])])],
        ['POST', "#^/v1/albums/$uuid/assets$#", fn($m) => api_ok(['added' => album_add_assets($m[1], (int) api_need_user($user)['id'], (array) ($body['ids'] ?? []))])],
        ['DELETE', "#^/v1/albums/$uuid/assets$#", fn($m) => api_ok(['removed' => album_remove_assets($m[1], (int) api_need_user($user)['id'], (array) ($body['ids'] ?? []))])],
        ['POST', "#^/v1/albums/$uuid/members$#", fn($m) => api_ok(['member' => album_share($m[1], (int) api_need_user($user)['id'], (string) ($body['email'] ?? ''), (string) ($body['role'] ?? 'viewer'))])],
        ['DELETE', "#^/v1/albums/$uuid/members/(\d+)$#", fn($m) => api_ok(['ok' => album_unshare($m[1], (int) api_need_user($user)['id'], (int) $m[2])])],
        ['GET', '#^/v1/users/lookup$#', fn() => api_user_lookup(api_need_user($user), $query)],
        ['GET', '#^/v1/search$#', fn() => api_search(api_need_user($user), $query)],
        ['GET', '#^/v1/search/status$#', fn() => api_search_status(api_need_user($user))],
        ['GET', '#^/v1/memories$#', fn() => api_memories(api_need_user($user), $query)],
        ['GET', '#^/v1/people$#', fn() => api_people_list(api_need_user($user), $query)],
        ['GET', '#^/v1/people/status$#', fn() => api_people_status(api_need_user($user))],
        ['GET', '#^/v1/people/(\d+)$#', fn($m) => api_person_get(api_need_user($user), $m[1])],
        ['PATCH', '#^/v1/people/(\d+)$#', fn($m) => api_person_patch(api_need_user($user), $m[1], $body)],
        ['DELETE', '#^/v1/people/(\d+)$#', fn($m) => api_person_delete(api_need_user($user), $m[1])],
        ['POST', '#^/v1/people/(\d+)/merge$#', fn($m) => api_person_merge(api_need_user($user), $m[1], $body)],
        ['POST', '#^/v1/assets/people$#', fn() => api_assets_people_bulk(api_need_user($user), $body)],
        ['GET', "#^/v1/assets/$uuid/people$#", fn($m) => api_asset_people_get(api_need_user($user), $m[1])],
        ['POST', "#^/v1/assets/$uuid/people$#", fn($m) => api_asset_people_add(api_need_user($user), $m[1], $body)],
        ['DELETE', "#^/v1/assets/$uuid/people/(\d+)$#", fn($m) => api_asset_people_remove(api_need_user($user), $m[1], $m[2])],
        ['PATCH', '#^/v1/faces/(\d+)$#', fn($m) => api_face_patch(api_need_user($user), $m[1], $body)],
        ['GET', '#^/v1/faces/(\d+)/thumb$#', fn($m) => api_face_thumb(api_need_user($user), $m[1])],
        ['GET', '#^/v1/cleanup$#', fn() => api_cleanup(api_need_user($user))],
        ['POST', '#^/v1/cleanup/resolve$#', fn() => api_cleanup_resolve(api_need_user($user), $body)],
        ['POST', '#^/v1/cleanup/dismiss$#', fn() => api_cleanup_dismiss(api_need_user($user), $body)],
        ['POST', '#^/v1/cleanup/reset$#', fn() => api_cleanup_reset(api_need_user($user))],
        ['POST', '#^/v1/devices/report$#', fn() => api_ok(device_report((int) api_need_user($user)['id'], $body))],
    ];
    $methodMismatch = false;
    foreach ($routes as [$verb, $re, $fn]) {
        if (preg_match($re, $path, $m)) {
            if ($verb !== $method) {
                $methodMismatch = true;
                continue;
            }
            return $fn($m);
        }
    }
    if ($methodMismatch) {
        throw new ApiError(405, 'method_not_allowed', 'Yöntem desteklenmiyor.');
    }
    api_not_found();
}

/* ------------------------------------------------------------------ auth */

function api_login(array $b): array
{
    $email = trim((string) ($b['email'] ?? ''));
    if (!rate_limit('api-login:' . client_ip() . ':' . mb_strtolower($email), 10, 900)) {
        throw new ApiError(429, 'rate_limited', t('auth.too_many'));
    }
    $u = user_verify_password($email, (string) ($b['password'] ?? ''));
    if ($u === null) {
        throw new ApiError(401, 'invalid_credentials', t('auth.invalid_credentials'));
    }
    $platform = (string) ($b['platform'] ?? 'ios');
    $token = api_token_issue((int) $u['id'], (string) ($b['device_name'] ?? 'iPhone'), $platform);
    db_exec('UPDATE users SET last_seen_at = ? WHERE id = ?', [now_utc(), $u['id']]);
    return api_ok(['token' => $token, 'user' => user_public($u)]);
}

function api_logout(array $user): array
{
    if (api_auth_kind() === 'session') {
        logout_user();
    } else {
        api_token_revoke_current();
    }
    return api_ok(['ok' => true]);
}

function api_user_lookup(array $user, array $q): array
{
    if (!rate_limit('lookup:' . $user['id'], 60, 3600)) {
        throw new ApiError(429, 'rate_limited', t('auth.too_many'));
    }
    $u = user_find_by_email((string) ($q['email'] ?? ''));
    return api_ok(['exists' => $u !== null && $u['status'] === 'active', 'name' => $u !== null && $u['status'] === 'active' ? $u['name'] : null]);
}

/* ---------------------------------------------------------------- assets */

/** Zaman çizelgesi koşulları: [sql, args]. 'a' takma adlı assets tablosu için. */
function api_asset_filters(array $user, array $q): array
{
    $uid = (int) $user['id'];
    $where = [];
    $args = [];
    $albumId = (string) ($q['album'] ?? '');
    if ($albumId !== '') {
        if (!is_uuid($albumId) || !album_can($uid, $albumId, 'view')) {
            api_not_found();
        }
        $where[] = 'a.id IN (SELECT asset_id FROM album_assets WHERE album_id = ?)';
        $args[] = $albumId;
        $where[] = 'a.trashed_at IS NULL';
    } else {
        $where[] = 'a.user_id = ?';
        $args[] = $uid;
        if (api_bool($q['trashed'] ?? null)) {
            $where[] = 'a.trashed_at IS NOT NULL';
        } else {
            $where[] = 'a.trashed_at IS NULL';
            $where[] = api_bool($q['archived'] ?? null) ? 'a.is_archived = 1' : 'a.is_archived = 0';
            // Tek kare: Live Photo videosu ve düzenlenmiş kopyası olan orijinal gizlenir.
            $where[] = "NOT (a.type = 'video' AND a.live_pair_id IS NOT NULL AND EXISTS (SELECT 1 FROM assets lp WHERE lp.id = a.live_pair_id AND lp.type = 'photo' AND lp.trashed_at IS NULL))";
            $where[] = 'NOT EXISTS (SELECT 1 FROM assets ed WHERE ed.edited_of_id = a.id AND ed.trashed_at IS NULL)';
        }
    }
    $personId = (string) ($q['person'] ?? '');
    if ($personId !== '') {
        if (!ctype_digit($personId) || db_val('SELECT 1 FROM people WHERE id = ? AND user_id = ?', [(int) $personId, $uid]) === null) {
            api_not_found();
        }
        $where[] = 'a.id IN (SELECT asset_id FROM asset_person WHERE person_id = ?)';
        $args[] = (int) $personId;
    }
    if (api_bool($q['favorite'] ?? null)) {
        $where[] = 'a.is_favorite = 1';
    }
    if (api_bool($q['has_location'] ?? null)) {
        $where[] = 'a.latitude IS NOT NULL';
    }
    if (($q['type'] ?? '') === 'video' || ($q['type'] ?? '') === 'photo') {
        $where[] = 'a.type = ?';
        $args[] = $q['type'];
    }
    if (!empty($q['from']) && ($from = api_iso_to_utc((string) $q['from'])) !== null) {
        $where[] = 'a.taken_at >= ?';
        $args[] = $from;
    }
    if (!empty($q['to']) && ($to = api_iso_to_utc((string) $q['to'])) !== null) {
        $where[] = 'a.taken_at <= ?';
        $args[] = $to;
    }
    $text = trim((string) ($q['q'] ?? ''));
    if ($text !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($text, 0, 100)) . '%';
        $where[] = '(a.original_filename LIKE ? OR a.description LIKE ? OR a.camera_model LIKE ? OR a.camera_make LIKE ? OR EXISTS (SELECT 1 FROM asset_person ap JOIN people p ON p.id = ap.person_id WHERE ap.asset_id = a.id AND p.name LIKE ?))';
        array_push($args, $like, $like, $like, $like, $like);
    }
    return [implode(' AND ', $where), $args];
}

function api_assets_list(array $user, array $q): array
{
    [$where, $args] = api_asset_filters($user, $q);
    $limit = max(1, min(500, (int) ($q['limit'] ?? 200)));
    $before = api_iso_to_utc($q['before'] ?? null);
    if ($before !== null) {
        $bid = (string) ($q['before_id'] ?? '');
        if ($bid !== '' && is_uuid($bid)) {
            $where .= ' AND (a.taken_at < ? OR (a.taken_at = ? AND a.id < ?))';
            array_push($args, $before, $before, $bid);
        } else {
            $where .= ' AND a.taken_at < ?';
            $args[] = $before;
        }
    }
    $order = api_bool($q['trashed'] ?? null) ? 'a.taken_at DESC, a.id DESC' : 'a.taken_at DESC, a.id DESC';
    $rows = db_all("SELECT a.* FROM assets a WHERE $where ORDER BY $order LIMIT " . ($limit + 1), $args);
    $next = null;
    if (count($rows) > $limit) {
        array_pop($rows);
        $last = end($rows);
        $next = ['before' => iso_utc($last['taken_at']), 'before_id' => $last['id']];
    }
    return api_ok(['assets' => array_map('asset_to_json', $rows), 'next' => $next]);
}

function api_assets_summary(array $user, array $q): array
{
    [$where, $args] = api_asset_filters($user, $q);
    $local = API_LOCAL_TAKEN;
    $rows = db_all("SELECT DATE_FORMAT($local, '%Y-%m') AS month, COUNT(*) AS n FROM assets a WHERE $where GROUP BY month ORDER BY month DESC", $args);
    return api_ok(['months' => array_map(fn($r) => ['month' => $r['month'], 'count' => (int) $r['n']], $rows)]);
}

/**
 * Yerel çekim zamanı (SQL ifadesi): zaman çizelgesinin gün başlıklarıyla aynı mantık
 * (taken_at + taken_tz_offset; dengeleme yoksa UTC). Bkz. util.js dayKey().
 */
const API_LOCAL_TAKEN = 'DATE_ADD(a.taken_at, INTERVAL COALESCE(a.taken_tz_offset, 0) MINUTE)';

/**
 * Takvim: ?month=YYYY-MM için fotoğraflı yerel günler, sayıları, kapak öğesi ve
 * o güne atlamak için 'before' imleci (günün en yeni taken_at'i + 1 sn).
 */
function api_assets_days(array $user, array $q): array
{
    $month = is_string($q['month'] ?? null) ? $q['month'] : '';
    if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m) || (int) $m[2] < 1 || (int) $m[2] > 12 || (int) $m[1] < 1900 || (int) $m[1] > 9999) {
        throw new ValidationError('Geçersiz ay (YYYY-MM).', 'month');
    }
    [$where, $args] = api_asset_filters($user, $q);
    $local = API_LOCAL_TAKEN;
    $start = sprintf('%04d-%02d-01 00:00:00', (int) $m[1], (int) $m[2]);
    // Ay sonu SQL'de hesaplanır (9999-12 gibi uçlarda PHP 5 haneli yıl üretmesin; MySQL NULL verir → boş).
    $end = 'DATE_ADD(CAST(? AS DATETIME), INTERVAL 1 MONTH)';
    // taken_at aralığı dizini kullanır (±14 sa dengeleme payı); yerel ifade kesin süzer.
    $where .= " AND a.taken_at >= DATE_SUB(?, INTERVAL 14 HOUR) AND a.taken_at < DATE_ADD($end, INTERVAL 14 HOUR) AND $local >= ? AND $local < $end";
    array_push($args, $start, $start, $start, $start);
    $rows = db_all(
        "SELECT day, n, last_at, id FROM (
            SELECT DATE($local) AS day, a.id, a.taken_at,
                   COUNT(*) OVER (PARTITION BY DATE($local)) AS n,
                   MAX(a.taken_at) OVER (PARTITION BY DATE($local)) AS last_at,
                   ROW_NUMBER() OVER (PARTITION BY DATE($local) ORDER BY $local DESC, a.id DESC) AS rn
            FROM assets a WHERE $where
        ) d WHERE rn = 1 ORDER BY day DESC",
        $args
    );
    return api_ok(['month' => $month, 'days' => array_map(fn($r) => [
        'day' => (string) $r['day'],
        'count' => (int) $r['n'],
        'cover_asset_id' => $r['id'],
        'before' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($r['last_at'] . ' UTC') + 1),
    ], $rows)]);
}

function api_assets_map(array $user, array $q): array
{
    [$where, $args] = api_asset_filters($user, $q);
    $where .= ' AND a.latitude IS NOT NULL AND a.longitude IS NOT NULL';
    $bbox = array_map('floatval', explode(',', (string) ($q['bbox'] ?? '')));
    if (count($bbox) === 4 && ($q['bbox'] ?? '') !== '') {
        [$minLng, $minLat, $maxLng, $maxLat] = $bbox;
        $where .= ' AND a.latitude BETWEEN ? AND ? AND a.longitude BETWEEN ? AND ?';
        array_push($args, $minLat, $maxLat, $minLng, $maxLng);
    }
    $rows = db_all("SELECT a.id, a.latitude, a.longitude FROM assets a WHERE $where ORDER BY a.taken_at DESC LIMIT 5000", $args);
    return api_ok(['points' => array_map(fn($r) => ['id' => $r['id'], 'lat' => (float) $r['latitude'], 'lng' => (float) $r['longitude']], $rows)]);
}

/** Kimlik listesiyle öğeleri getirir (haritadaki bir kümenin fotoğrafları gibi): yalnızca kullanıcının
 *  çöpte olmayan öğeleri, istenen sırada, tekrarsız. En fazla 500 kimlik. */
function api_assets_lookup(array $user, array $b): array
{
    $ids = (array) ($b['ids'] ?? []);
    if (count($ids) > 500) {
        throw new ValidationError('En fazla 500 öğe.', 'ids');
    }
    $ids = array_values(array_unique(array_filter($ids, fn($v) => is_string($v) && preg_match('/^[0-9a-f-]{36}$/', $v))));
    if (!$ids) {
        return api_ok(['assets' => []]);
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = db_all("SELECT a.* FROM assets a WHERE a.user_id = ? AND a.trashed_at IS NULL AND a.id IN ($in)", array_merge([(int) $user['id']], $ids));
    $byId = [];
    foreach ($rows as $r) {
        $byId[$r['id']] = $r;
    }
    $out = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $out[] = asset_to_json($byId[$id]);
        }
    }
    return api_ok(['assets' => $out]);
}

function api_assets_check(array $user, array $b): array
{
    $raw = (array) ($b['sha1'] ?? []);
    if (count($raw) > 500) {
        throw new ValidationError('En fazla 500 özet gönderilebilir.', 'sha1');
    }
    $list = array_values(array_unique(array_map('strtolower', array_filter($raw, 'is_string'))));
    $list = array_values(array_filter($list, fn($s) => (bool) preg_match('/^[0-9a-f]{40}$/', $s)));
    if (!$list) {
        return api_ok(['missing' => []]);
    }
    $uid = (int) $user['id'];
    $in = db_in($list);
    $have = array_column(db_all("SELECT blob_sha1 AS s FROM assets WHERE user_id = ? AND blob_sha1 IN ($in)", array_merge([$uid], $list)), 's');
    $deleted = array_column(db_all("SELECT sha1 AS s FROM deleted_checksums WHERE user_id = ? AND sha1 IN ($in)", array_merge([$uid], $list)), 's');
    return api_ok(['missing' => array_values(array_diff($list, $have, $deleted))]);
}

function api_assets_bulk(array $user, string $action, array $b): array
{
    $ids = (array) ($b['ids'] ?? []);
    if (count($ids) > 1000) {
        throw new ValidationError('En fazla 1000 öğe.', 'ids');
    }
    $uid = (int) $user['id'];
    $n = match ($action) {
        'trash' => asset_trash($uid, $ids),
        'restore' => asset_restore($uid, $ids),
        'purge' => asset_purge($uid, $ids),
    };
    return api_ok(['count' => $n]);
}

/** 'own' | 'view' | null */
function asset_access(int $userId, string $assetId): ?array
{
    $a = db_row('SELECT * FROM assets WHERE id = ?', [$assetId]);
    if ($a === null) {
        return null;
    }
    if ((int) $a['user_id'] === $userId) {
        return ['own', $a];
    }
    if ($a['trashed_at'] === null) {
        $shared = db_val('SELECT 1 FROM album_assets aa JOIN albums al ON al.id = aa.album_id
                LEFT JOIN album_members m ON m.album_id = al.id AND m.user_id = ?
                WHERE aa.asset_id = ? AND (al.user_id = ? OR m.user_id IS NOT NULL) LIMIT 1', [$userId, $assetId, $userId]);
        if ($shared !== null) {
            return ['view', $a];
        }
    }
    return null;
}

function api_asset_get(array $user, string $id): array
{
    $acc = asset_access((int) $user['id'], $id);
    if ($acc === null) {
        api_not_found();
    }
    [$level, $a] = $acc;
    $json = asset_to_json($a);
    $json['access'] = $level;
    $json['people'] = asset_person_names($id);
    $json['albums'] = db_all('SELECT al.id, al.title FROM album_assets aa JOIN albums al ON al.id = aa.album_id
            LEFT JOIN album_members m ON m.album_id = al.id AND m.user_id = ?
            WHERE aa.asset_id = ? AND (al.user_id = ? OR m.user_id IS NOT NULL) ORDER BY al.title', [$user['id'], $id, $user['id']]);
    if ($level === 'view') {
        $json['owner_name'] = db_val('SELECT name FROM users WHERE id = ?', [$a['user_id']]);
    }
    return api_ok(['asset' => $json]);
}

function api_asset_patch(array $user, string $id, array $b): array
{
    $uid = (int) $user['id'];
    $acc = asset_access($uid, $id);
    if ($acc === null || $acc[0] !== 'own') {
        api_not_found();
    }
    $set = [];
    $args = [];
    foreach (['is_favorite', 'is_archived'] as $f) {
        if (array_key_exists($f, $b)) {
            $set[] = "$f = ?";
            $args[] = api_bool($b[$f]) ? 1 : 0;
        }
    }
    if (array_key_exists('description', $b)) {
        $d = $b['description'] === null ? null : trim((string) $b['description']);
        $set[] = 'description = ?';
        $args[] = $d === '' ? null : mb_substr((string) $d, 0, 5000);
    }
    if (array_key_exists('taken_at', $b)) {
        $t = api_iso_to_utc($b['taken_at']);
        if ($t === null) {
            throw new ValidationError('Geçersiz tarih.', 'taken_at');
        }
        $set[] = 'taken_at = ?';
        $set[] = "date_source = 'user'";
        $args[] = $t;
        if (array_key_exists('tz_offset', $b) && is_numeric($b['tz_offset']) && abs((int) $b['tz_offset']) <= 840) {
            $set[] = 'taken_tz_offset = ?';
            $args[] = (int) $b['tz_offset'];
        }
    }
    if (array_key_exists('latitude', $b) || array_key_exists('longitude', $b)) {
        $lat = $b['latitude'] ?? null;
        $lng = $b['longitude'] ?? null;
        if ($lat === null || $lng === null) {
            $set[] = 'latitude = NULL, longitude = NULL, altitude = NULL';
        } else {
            if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
                throw new ValidationError('Geçersiz konum.', 'latitude');
            }
            $set[] = 'latitude = ?, longitude = ?';
            array_push($args, round((float) $lat, 6), round((float) $lng, 6));
        }
    }
    if ($set) {
        $v = asset_bump_version($uid);
        $set[] = 'version = ?';
        $set[] = 'updated_at = ?';
        array_push($args, $v, now_utc(), $id, $uid);
        db_exec('UPDATE assets SET ' . implode(', ', $set) . ' WHERE id = ? AND user_id = ?', $args);
    }
    return api_asset_get($user, $id);
}

function api_placeholder_svg(string $type): string
{
    $icon = $type === 'video'
        ? '<path d="M44 38 L64 50 L44 62 Z" fill="#b9b2cf"/>'
        : '<circle cx="40" cy="40" r="7" fill="#b9b2cf"/><path d="M22 70 L42 50 L54 62 L62 54 L78 70 Z" fill="#b9b2cf"/>';
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="#ece8f6"/>' . $icon . '</svg>';
}

function api_asset_file(array $user, string $id, string $variant): array
{
    $acc = asset_access((int) $user['id'], $id);
    if ($acc === null) {
        api_not_found();
    }
    $a = $acc[1];
    if ($variant === 'thumb' || $variant === 'preview') {
        $p = derived_find($id, $variant);
        if ($p === null && $variant === 'preview') {
            $p = derived_find($id, 'thumb');
        }
        if ($p === null) {
            return ['status' => $a['thumb_state'] === 'pending' ? 202 : 200, 'svg' => api_placeholder_svg($a['type'])];
        }
        return ['status' => 200, 'file' => ['path' => $p, 'mime' => str_ends_with($p, '.webp') ? 'image/webp' : 'image/jpeg', 'cache' => 'private, max-age=31536000, immutable', 'download' => null]];
    }
    if ($variant === 'video' && ($web = derived_find_video($id)) !== null) {
        return ['status' => 200, 'file' => ['path' => $web, 'mime' => 'video/mp4', 'cache' => 'private, max-age=86400', 'download' => null]];
    }
    $path = blob_path($a['blob_sha1']);
    if (!is_file($path)) {
        api_not_found();
    }
    return ['status' => 200, 'file' => [
        'path' => $path, 'mime' => $a['mime'] ?: 'application/octet-stream', 'cache' => 'private, no-store',
        'download' => $variant === 'original' ? $a['original_filename'] : null,
    ]];
}

/* --------------------------------------------------------------- uploads */

function api_upload_start(array $user, array $b): array
{
    $source = api_auth_kind() === 'session' ? 'web' : 'ios';
    return api_ok(upload_start((int) $user['id'], (string) ($b['filename'] ?? ''), (int) ($b['size'] ?? 0), isset($b['sha1']) ? (string) $b['sha1'] : null, (array) ($b['meta'] ?? []), $source), 201);
}

function api_upload_chunk(array $user, string $id, array $q, $stream): array
{
    $offset = isset($q['offset']) ? (int) $q['offset'] : null;
    if ($offset === null) {
        $range = (string) ($_SERVER['HTTP_CONTENT_RANGE'] ?? '');
        if (preg_match('#^bytes (\d+)-\d+/\d+$#', $range, $m)) {
            $offset = (int) $m[1];
        }
    }
    if ($offset === null) {
        throw new ValidationError('offset veya Content-Range gerekli.', 'offset');
    }
    if (!is_resource($stream)) {
        throw new ValidationError('Gövde yok.', 'body');
    }
    $uid = (int) $user['id'];
    $received = upload_chunk($id, $uid, $offset, $stream);
    $out = ['received' => $received];
    // complete=1: son parça geldiyse yüklemeyi hemen tamamla (iOS arka plan yüklemesinde uygulama uyanmadan biter).
    if (api_bool($q['complete'] ?? null) && $received === (int) db_val('SELECT size FROM uploads WHERE id = ?', [$id])) {
        $out['complete'] = upload_complete($id, $uid);
    }
    return api_ok($out);
}

/* -------------------------------------------------------------- memories */

/** Widget'lar için rastgele fotoğraflar (son 30 gün hariç; zaman çizelgesi kuralları). */
function api_assets_random(array $user, array $q): array
{
    $n = max(1, min(50, (int) ($q['count'] ?? 12)));
    $rows = db_all('SELECT a.* FROM assets a WHERE ' . memories_base_where() . " AND a.type = 'photo' AND a.taken_at < ? ORDER BY RAND() LIMIT $n", [
        (int) $user['id'], gmdate('Y-m-d H:i:s', time() - 30 * 86400),
    ]);
    return api_ok(['assets' => array_map('asset_to_json', $rows)]);
}

function api_memories(array $user, array $q): array
{
    $date = (string) ($q['date'] ?? '');
    if ($date === '') {
        $date = memories_today((int) $user['id']);
    }
    return api_ok(['date' => $date, 'memories' => memories_for((int) $user['id'], $date)]);
}

/* ---------------------------------------------------------------- albums */

function api_album_create(array $user, array $b): array
{
    $id = album_create((int) $user['id'], (string) ($b['title'] ?? ''), isset($b['description']) ? (string) $b['description'] : null);
    if (!empty($b['ids'])) {
        album_add_assets($id, (int) $user['id'], (array) $b['ids']);
    }
    return api_ok(['album' => album_detail($id, (int) $user['id'])], 201);
}

/** Kullanıcının kendi profilini günceller: görünüm teması ve ad. */
function api_me_update(array $user, array $b): array
{
    $sets = [];
    $args = [];
    if (array_key_exists('theme', $b)) {
        if (!in_array($b['theme'], UI_THEMES, true)) {
            throw new ValidationError('Geçersiz tema.', 'theme');
        }
        $sets[] = 'ui_theme = ?';
        $args[] = $b['theme'];
    }
    if (array_key_exists('name', $b)) {
        $name = trim((string) $b['name']);
        if (mb_strlen($name) > 120) {
            throw new ValidationError('Ad en fazla 120 karakter olabilir.', 'name');
        }
        $sets[] = 'name = ?';
        $args[] = $name;
    }
    if ($sets) {
        $args[] = (int) $user['id'];
        db_exec('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);
    }
    return api_ok(['user' => user_public(user_find((int) $user['id']))]);
}

function api_album_update(array $user, string $id, array $b): array
{
    album_update($id, (int) $user['id'], $b);
    return api_ok(['album' => album_detail($id, (int) $user['id'])]);
}
