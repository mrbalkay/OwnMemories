<?php
/**
 * OwnMemories — albümler ve ortak albümler (album_members: viewer / contributor).
 * Albümden çıkarmak asset'i silmez; eklenen fotoğraf ekleyenin kütüphanesinde kalır.
 */
declare(strict_types=1);

/** Kullanıcının albümdeki rolü: 'owner' | 'contributor' | 'viewer' | null */
function album_role(int $userId, string $albumId): ?string
{
    $al = db_row('SELECT user_id FROM albums WHERE id = ?', [$albumId]);
    if ($al === null) {
        return null;
    }
    if ((int) $al['user_id'] === $userId) {
        return 'owner';
    }
    $r = db_val('SELECT role FROM album_members WHERE album_id = ? AND user_id = ?', [$albumId, $userId]);
    return $r === null ? null : (string) $r;
}

/** $perm: view | contribute | own */
function album_can(int $userId, string $albumId, string $perm): bool
{
    $role = album_role($userId, $albumId);
    return match ($perm) {
        'view' => $role !== null,
        'contribute' => $role === 'owner' || $role === 'contributor',
        'own' => $role === 'owner',
        default => false,
    };
}

function album_require(int $userId, string $albumId, string $perm): void
{
    $role = album_role($userId, $albumId);
    if ($role === null) {
        api_not_found();
    }
    if (!album_can($userId, $albumId, $perm)) {
        throw new ApiError(403, 'forbidden', t('common.forbidden'));
    }
}

function album_touch(string $albumId): void
{
    $owner = (int) db_val('SELECT user_id FROM albums WHERE id = ?', [$albumId]);
    db_exec('UPDATE albums SET updated_at = ?, version = ? WHERE id = ?', [now_utc(), asset_bump_version($owner), $albumId]);
}

function album_create(int $userId, string $title, ?string $description = null): string
{
    $title = trim($title);
    if ($title === '') {
        throw new ValidationError('Albüm adı gerekli.', 'title');
    }
    $id = uuid4();
    db_exec('INSERT INTO albums (id, user_id, title, description, source, created_at, updated_at, version) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $id, $userId, mb_substr($title, 0, 255), $description !== null && trim($description) !== '' ? trim($description) : null, 'user', now_utc(), now_utc(), asset_bump_version($userId),
    ]);
    return $id;
}

function album_update(string $albumId, int $userId, array $f): void
{
    album_require($userId, $albumId, 'own');
    if (array_key_exists('title', $f)) {
        $t = trim((string) $f['title']);
        if ($t === '') {
            throw new ValidationError('Albüm adı gerekli.', 'title');
        }
        db_exec('UPDATE albums SET title = ? WHERE id = ?', [mb_substr($t, 0, 255), $albumId]);
    }
    if (array_key_exists('description', $f)) {
        $d = trim((string) $f['description']);
        db_exec('UPDATE albums SET description = ? WHERE id = ?', [$d === '' ? null : $d, $albumId]);
    }
    if (array_key_exists('cover_asset_id', $f)) {
        $c = (string) $f['cover_asset_id'];
        if (db_val('SELECT 1 FROM album_assets WHERE album_id = ? AND asset_id = ?', [$albumId, $c]) === null) {
            throw new ValidationError('Kapak albümde olmalı.', 'cover_asset_id');
        }
        db_exec('UPDATE albums SET cover_asset_id = ? WHERE id = ?', [$c, $albumId]);
    }
    album_touch($albumId);
}

function album_delete(string $albumId, int $userId): bool
{
    album_require($userId, $albumId, 'own');
    db_exec('DELETE FROM album_assets WHERE album_id = ?', [$albumId]);
    db_exec('DELETE FROM album_members WHERE album_id = ?', [$albumId]);
    db_exec('DELETE FROM albums WHERE id = ?', [$albumId]);
    asset_bump_version($userId);
    return true;
}

/** Yalnız kullanıcının kendi asset'leri eklenir. Eklenen sayısını döner. */
function album_add_assets(string $albumId, int $userId, array $ids): int
{
    album_require($userId, $albumId, 'contribute');
    $own = asset_owned_ids($userId, $ids);
    $n = 0;
    $max = (int) db_val('SELECT COALESCE(MAX(sort_order), 0) FROM album_assets WHERE album_id = ?', [$albumId]);
    foreach ($own as $aid) {
        $n += db_exec('INSERT IGNORE INTO album_assets (album_id, asset_id, added_by, sort_order, added_at) VALUES (?, ?, ?, ?, ?)', [$albumId, $aid, $userId, ++$max, now_utc()]);
    }
    if ($n > 0) {
        db_exec('UPDATE albums SET cover_asset_id = COALESCE(cover_asset_id, ?) WHERE id = ?', [$own[0], $albumId]);
        album_touch($albumId);
    }
    return $n;
}

/** Sahip herkesinkini, katkıda bulunan yalnız kendi asset'lerini çıkarabilir. */
function album_remove_assets(string $albumId, int $userId, array $ids): int
{
    album_require($userId, $albumId, 'contribute');
    $ids = array_values(array_filter(array_map('strval', $ids), 'is_uuid'));
    if (!$ids) {
        return 0;
    }
    if (!album_can($userId, $albumId, 'own')) {
        $own = asset_owned_ids($userId, $ids);
        if (count($own) !== count($ids)) {
            throw new ApiError(403, 'forbidden', t('common.forbidden'));
        }
    }
    $n = db_exec('DELETE FROM album_assets WHERE album_id = ? AND asset_id IN (' . db_in($ids) . ')', array_merge([$albumId], $ids));
    db_exec('UPDATE albums SET cover_asset_id = NULL WHERE id = ? AND cover_asset_id IN (' . db_in($ids) . ')', array_merge([$albumId], $ids));
    db_exec('UPDATE albums SET cover_asset_id = (SELECT asset_id FROM album_assets WHERE album_id = ? ORDER BY sort_order LIMIT 1) WHERE id = ? AND cover_asset_id IS NULL', [$albumId, $albumId]);
    if ($n > 0) {
        album_touch($albumId);
    }
    return $n;
}

function album_share(string $albumId, int $ownerId, string $email, string $role): array
{
    album_require($ownerId, $albumId, 'own');
    if (!in_array($role, ['viewer', 'contributor'], true)) {
        throw new ValidationError('Geçersiz rol.', 'role');
    }
    $u = user_find_by_email($email);
    if ($u === null || $u['status'] !== 'active') {
        throw new ApiError(404, 'user_not_found', 'Bu e-postayla bir kullanıcı yok.');
    }
    if ((int) $u['id'] === $ownerId) {
        throw new ApiError(422, 'validation_failed', 'Albüm zaten sizin.', ['field' => 'email']);
    }
    db_exec('INSERT INTO album_members (album_id, user_id, role, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)', [$albumId, $u['id'], $role, now_utc()]);
    album_touch($albumId);
    return ['user_id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $role];
}

/** Sahip bir üyeyi çıkarır veya üye kendisi ayrılır. */
function album_unshare(string $albumId, int $actorId, int $memberId): bool
{
    if ($actorId !== $memberId) {
        album_require($actorId, $albumId, 'own');
    } elseif (album_role($actorId, $albumId) === null) {
        api_not_found();
    }
    db_exec('DELETE FROM album_members WHERE album_id = ? AND user_id = ?', [$albumId, $memberId]);
    album_touch($albumId);
    return true;
}

function album_to_json(array $al, int $viewerId): array
{
    $role = (int) $al['user_id'] === $viewerId ? 'owner' : (string) ($al['role'] ?? album_role($viewerId, $al['id']));
    return [
        'id' => $al['id'],
        'title' => $al['title'],
        'description' => $al['description'],
        'cover_asset_id' => $al['cover_asset_id'],
        'count' => (int) ($al['n'] ?? db_val('SELECT COUNT(*) FROM album_assets WHERE album_id = ?', [$al['id']])),
        'role' => $role,
        'owner_id' => (int) $al['user_id'],
        'owner_name' => $al['owner_name'] ?? db_val('SELECT name FROM users WHERE id = ?', [$al['user_id']]),
        'shared' => (int) ($al['members'] ?? db_val('SELECT COUNT(*) FROM album_members WHERE album_id = ?', [$al['id']])) > 0 || $role !== 'owner',
        'source' => $al['source'],
        'updated_at' => iso_utc($al['updated_at']),
        'version' => (int) $al['version'],
    ];
}

/** Sahip olunan ve paylaşılan albümler (son güncellenen önce). */
function album_list(int $userId): array
{
    $rows = db_all("SELECT al.*, u.name AS owner_name, m.role,
            (SELECT COUNT(*) FROM album_assets x WHERE x.album_id = al.id) AS n,
            (SELECT COUNT(*) FROM album_members y WHERE y.album_id = al.id) AS members
        FROM albums al JOIN users u ON u.id = al.user_id
        LEFT JOIN album_members m ON m.album_id = al.id AND m.user_id = ?
        WHERE al.user_id = ? OR m.user_id IS NOT NULL
        ORDER BY al.updated_at DESC", [$userId, $userId]);
    return array_map(fn($r) => album_to_json($r, $userId), $rows);
}

function album_detail(string $albumId, int $userId): array
{
    album_require($userId, $albumId, 'view');
    $al = db_row('SELECT * FROM albums WHERE id = ?', [$albumId]);
    $j = album_to_json($al, $userId);
    $j['members'] = array_map(fn($m) => ['user_id' => (int) $m['user_id'], 'name' => $m['name'], 'email' => $m['email'], 'role' => $m['role']],
        db_all('SELECT m.user_id, m.role, u.name, u.email FROM album_members m JOIN users u ON u.id = m.user_id WHERE m.album_id = ? ORDER BY u.name', [$albumId]));
    return $j;
}
