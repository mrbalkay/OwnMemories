<?php
/**
 * OwnMemories — iOS cihazlarının yedekleme durumu bildirimi.
 */
declare(strict_types=1);

/** Cihaz durumunu kaydeder/günceller. */
function device_report(int $userId, array $b): array
{
    $deviceId = trim((string) ($b['device_id'] ?? ''));
    if ($deviceId === '' || mb_strlen($deviceId) > 100) {
        throw new ValidationError('device_id gerekli.', 'device_id');
    }
    $str = fn(string $k, int $max) => isset($b[$k]) && $b[$k] !== null && trim((string) $b[$k]) !== '' ? mb_substr(trim((string) $b[$k]), 0, $max) : null;
    $last = isset($b['last_backup_at']) ? api_iso_to_utc($b['last_backup_at']) : null;
    $now = now_utc();
    db_exec('INSERT INTO devices (user_id, device_id, name, model, os_version, app_version, pending, uploaded_total, last_backup_at, last_error, last_seen_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), model = VALUES(model), os_version = VALUES(os_version), app_version = VALUES(app_version),
               pending = VALUES(pending), uploaded_total = VALUES(uploaded_total), last_backup_at = COALESCE(VALUES(last_backup_at), last_backup_at),
               last_error = VALUES(last_error), last_seen_at = VALUES(last_seen_at)', [
        $userId, $deviceId, $str('name', 120) ?? '', $str('model', 60), $str('os_version', 30), $str('app_version', 30),
        max(0, (int) ($b['pending'] ?? 0)), max(0, (int) ($b['uploaded_total'] ?? 0)), $last, $str('last_error', 500), $now, $now,
    ]);
    return ['ok' => true, 'server_time' => iso_utc($now)];
}
