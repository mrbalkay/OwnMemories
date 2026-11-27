<?php
/**
 * OwnMemories — yönetim paneli işlevleri (sayfalardan bağımsız, test edilebilir).
 */
declare(strict_types=1);

function admin_assert(?array $user): void
{
    if ($user === null || !(int) $user['is_admin']) {
        throw new ApiError(403, 'forbidden', t('common.forbidden'));
    }
}

/** Formdan kullanıcı oluşturur. quota_gb boşsa sınırsız. */
function admin_create_user(array $actor, array $f): int
{
    admin_assert($actor);
    $quota = trim((string) ($f['quota_gb'] ?? ''));
    $quotaBytes = $quota === '' ? null : (int) round((float) str_replace(',', '.', $quota) * 1024 ** 3);
    $id = user_create((string) ($f['email'] ?? ''), (string) ($f['password'] ?? ''), (string) ($f['name'] ?? ''), !empty($f['is_admin']), $quotaBytes);
    audit((int) $actor['id'], 'user_create', (string) $f['email']);
    return $id;
}

function admin_update_user(array $actor, int $userId, array $f): void
{
    admin_assert($actor);
    $u = user_find($userId);
    if ($u === null) {
        throw new ValidationError('Kullanıcı yok', 'id');
    }
    if (array_key_exists('quota_gb', $f)) {
        $q = trim((string) $f['quota_gb']);
        db_exec('UPDATE users SET quota_bytes = ? WHERE id = ?', [$q === '' ? null : (int) round((float) str_replace(',', '.', $q) * 1024 ** 3), $userId]);
    }
    if (!empty($f['password'])) {
        user_set_password($userId, (string) $f['password']);
    }
    if (isset($f['status']) && in_array($f['status'], ['active', 'suspended'], true)) {
        if ($userId === (int) $actor['id'] && $f['status'] === 'suspended') {
            throw new ValidationError('Kendi hesabınızı askıya alamazsınız.', 'status');
        }
        db_exec('UPDATE users SET status = ? WHERE id = ?', [$f['status'], $userId]);
        if ($f['status'] === 'suspended') {
            db_exec('DELETE FROM api_tokens WHERE user_id = ?', [$userId]);
        }
    }
    if (isset($f['name']) && trim((string) $f['name']) !== '') {
        db_exec('UPDATE users SET name = ? WHERE id = ?', [mb_substr(trim((string) $f['name']), 0, 120), $userId]);
    }
    audit((int) $actor['id'], 'user_update', $u['email'] . ' ' . implode(',', array_keys(array_filter($f, fn($v) => $v !== '' && $v !== null))));
}

function admin_overview(): array
{
    $users = db_all('SELECT u.id, u.name, u.email, u.is_admin, u.status, u.quota_bytes, u.used_bytes, u.last_seen_at,
            (SELECT COUNT(*) FROM assets a WHERE a.user_id = u.id AND a.trashed_at IS NULL) AS assets,
            (SELECT COUNT(*) FROM assets a WHERE a.user_id = u.id AND a.type = \'video\' AND a.trashed_at IS NULL) AS videos
        FROM users u ORDER BY u.id');
    $jobs = [];
    foreach (db_all('SELECT status, COUNT(*) n FROM jobs GROUP BY status') as $r) {
        $jobs[$r['status']] = (int) $r['n'];
    }
    $root = storage_root();
    return [
        'users' => $users,
        'jobs' => $jobs,
        'storage_bytes' => (int) db_val('SELECT COALESCE(SUM(size), 0) FROM blobs'),
        'blob_count' => (int) db_val('SELECT COUNT(*) FROM blobs'),
        'disk_free' => is_dir($root) ? (float) @disk_free_space($root) : null,
        'disk_total' => is_dir($root) ? (float) @disk_total_space($root) : null,
        'imports' => db_all('SELECT i.*, u.name AS user_name FROM imports i JOIN users u ON u.id = i.user_id ORDER BY i.id DESC LIMIT 5'),
        'devices' => db_all('SELECT d.*, u.name AS user_name FROM devices d JOIN users u ON u.id = d.user_id ORDER BY d.last_seen_at DESC'),
        'cron_last_run' => (int) site_setting('cron_last_run', '0'),
        'worker_last_seen' => (int) site_setting('worker_last_seen', '0'),
    ];
}

/** İçe aktarmanın tüm öğe sonuçları CSV olarak. */
function import_report_csv(int $importId): string
{
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, ['zip', 'path', 'result', 'asset_id', 'detail'], ',', '"', '');
    $st = db()->prepare('SELECT zip_name, entry_path, result, asset_id, detail FROM import_items WHERE import_id = ? ORDER BY id');
    $st->execute([$importId]);
    while ($r = $st->fetch(PDO::FETCH_NUM)) {
        fputcsv($fh, array_map(fn($v) => (string) $v, $r), ',', '"', '');
    }
    rewind($fh);
    $csv = (string) stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

/** Tüm asset'ler için küçük resimleri yeniden üretme işi kuyruğa koyar. */
function admin_rerender_all(): int
{
    $n = 0;
    foreach (db_all('SELECT id FROM assets WHERE trashed_at IS NULL') as $r) {
        job_enqueue('render', ['asset_id' => $r['id']], 8);
        $n++;
    }
    return $n;
}

function admin_retry_failed(): int
{
    return db_exec("UPDATE jobs SET status = 'queued', attempts = 0, run_after = ?, last_error = NULL WHERE status = 'failed'", [now_utc()]);
}

function admin_import_set_status(int $importId, string $action): void
{
    $imp = db_row('SELECT status FROM imports WHERE id = ?', [$importId]);
    if ($imp === null) {
        return;
    }
    if ($action === 'pause' && in_array($imp['status'], ['queued', 'scanning', 'importing', 'linking'], true)) {
        site_setting_set('import_' . $importId . '_resume', $imp['status']);
        db_exec("UPDATE imports SET status = 'paused' WHERE id = ?", [$importId]);
    } elseif ($action === 'resume' && in_array($imp['status'], ['paused', 'failed'], true)) {
        $to = (string) site_setting('import_' . $importId . '_resume', 'importing');
        db_exec('UPDATE imports SET status = ?, last_error = NULL WHERE id = ?', [$to === 'queued' ? 'scanning' : $to, $importId]);
        job_enqueue('import_step', ['import_id' => $importId], 6);
    }
}
