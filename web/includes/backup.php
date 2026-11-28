<?php
/**
 * OwnMemories — sunucu yedeği durumu (salt okunur) ve "Şimdi yedekle" isteği.
 *
 * Yedeği root olarak server/backup.sh (restic) alır ve sonucu makine okunur bir JSON dosyasına yazar
 * (varsayılan /var/lib/ownmemories/backup-status.json, root:www-data 0640). Web tarafı bu dosyayı yalnızca okur;
 * parola ve anahtarlar hiçbir zaman bu dosyada ya da veritabanında bulunmaz (yalnızca /etc/ownmemories/backup.env).
 *
 * "Şimdi yedekle": PHP, istek klasörüne (varsayılan /var/lib/ownmemories/backup-spool, root:www-data 2770)
 * run-request dosyası yazar; systemd ownmemories-backup.path birimi dosyayı görünce yedeği root olarak başlatır.
 * İçerik root tarafında yorumlanmaz (yalnızca varlığına bakılır).
 *
 * Yapılandırma (isteğe bağlı, config.php): 'backup' => ['status_file' => …, 'spool_dir' => …]
 */
declare(strict_types=1);

const BACKUP_STATUS_FILE = '/var/lib/ownmemories/backup-status.json';
const BACKUP_SPOOL_DIR = '/var/lib/ownmemories/backup-spool';
const BACKUP_SETUP_COMMAND = 'sudo bash ~/OwnMemories/server/setup-backup.sh';
/** Son başarılı yedekten bu kadar saat sonra sarı (gecikti) ve kırmızı (yedek yok). */
const BACKUP_LATE_HOURS = 30;
const BACKUP_STALE_HOURS = 48;

/** Durum JSON'undan dışarı verilen hedef alanları (izin listesi; başka hiçbir şey sızmaz). */
const BACKUP_TARGET_FIELDS = [
    'id', 'type', 'label', 'location', 'last_status', 'last_attempt_at', 'last_success_at', 'last_failure_at', 'duration_s',
    'snapshot_id', 'snapshot_count', 'data_added', 'files_total', 'files_new', 'files_changed', 'repo_size', 'error',
    'last_check_at', 'last_check_ok', 'last_check_error', 'upload_limit_kib',
];

function backup_status_file(): string
{
    return (string) config('backup.status_file', BACKUP_STATUS_FILE);
}

function backup_spool_dir(): string
{
    return rtrim((string) config('backup.spool_dir', BACKUP_SPOOL_DIR), '/');
}

/** Durum dosyasını okur; yoksa, okunamıyorsa veya bozuksa null. */
function backup_read_status(): ?array
{
    $f = backup_status_file();
    if (!is_file($f) || !is_readable($f)) {
        return null;
    }
    $raw = @file_get_contents($f);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

function backup_ts($iso): ?int
{
    if (!is_string($iso) || $iso === '') {
        return null;
    }
    $t = strtotime($iso);
    return $t === false ? null : $t;
}

/**
 * Bir hedefin sağlığı: ok (yeşil) | warn (sarı) | error (kırmızı) ve nedeni.
 * Kırmızı: 48 saattir başarılı yedek yok (ya da hiç yok ve kurulum 48 saatten eski / son deneme hatalı).
 */
function backup_target_health(array $t, int $now, ?int $configuredAt = null): array
{
    $success = backup_ts($t['last_success_at'] ?? null);
    $failedLast = ($t['last_status'] ?? '') === 'failed';
    if ($success === null) {
        if ($failedLast) {
            return ['level' => 'error', 'reason' => 'never_succeeded_failing'];
        }
        $young = $configuredAt !== null && $now - $configuredAt < BACKUP_STALE_HOURS * 3600;
        return $young ? ['level' => 'warn', 'reason' => 'first_pending'] : ['level' => 'error', 'reason' => 'never'];
    }
    $age = $now - $success;
    if ($age > BACKUP_STALE_HOURS * 3600) {
        return ['level' => 'error', 'reason' => 'stale'];
    }
    if ($failedLast) {
        return ['level' => 'warn', 'reason' => 'last_failed'];
    }
    if ($age > BACKUP_LATE_HOURS * 3600) {
        return ['level' => 'warn', 'reason' => 'late'];
    }
    if (($t['last_check_ok'] ?? null) === false) {
        return ['level' => 'warn', 'reason' => 'check_failed'];
    }
    return ['level' => 'ok', 'reason' => 'ok'];
}

/** "HH:MM" günlük planının (sunucu saat dilimi) bir sonraki çalışma anı, ISO 8601 UTC. */
function backup_next_run(string $schedule, string $tz, int $now): ?string
{
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($schedule), $m)) {
        return null;
    }
    try {
        $zone = new DateTimeZone($tz !== '' ? $tz : 'Europe/Istanbul');
    } catch (Throwable $e) {
        $zone = new DateTimeZone('Europe/Istanbul');
    }
    $local = (new DateTimeImmutable('@' . $now))->setTimezone($zone);
    $run = $local->setTime((int) $m[1], (int) $m[2]);
    if ($run->getTimestamp() <= $now) {
        $run = $run->modify('+1 day')->setTime((int) $m[1], (int) $m[2]);
    }
    return gmdate('Y-m-d\TH:i:s\Z', $run->getTimestamp());
}

function backup_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    // Yeniden başlatmadan sonra aynı PID başka bir sürece verilmiş olabilir: komut satırı okunabiliyorsa yedek mi bak.
    $cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (is_string($cmd) && $cmd !== '' && !str_contains($cmd, 'backup')) {
        return false;
    }
    if (function_exists('posix_kill')) {
        // EPERM (başka kullanıcının süreci) da "yaşıyor" demektir.
        return @posix_kill($pid, 0) || (function_exists('posix_get_last_error') && posix_get_last_error() === 1);
    }
    return is_dir('/proc/' . $pid);
}

/** Yönetim sayfası, API ve uyarı bandı için tek özet. */
function backup_overview(?int $now = null): array
{
    $now ??= time();
    $s = backup_read_status();
    $spool = backup_spool_dir();
    $base = [
        'configured' => false, 'level' => 'unconfigured', 'targets' => [], 'next_run_at' => null, 'running' => false,
        'running_since' => null, 'requested' => is_file($spool . '/run-request'), 'can_request' => false,
        'last_run' => null, 'restore_test' => null, 'updated_at' => null, 'schedule' => null, 'include_derived' => false,
        'retention' => null, 'setup_command' => BACKUP_SETUP_COMMAND,
    ];
    if ($s === null || empty($s['configured'])) {
        return $base;
    }
    $configuredAt = backup_ts($s['configured_at'] ?? null);
    $rank = ['ok' => 0, 'warn' => 1, 'error' => 2];
    $level = 'ok';
    $targets = [];
    foreach ((array) ($s['targets'] ?? []) as $t) {
        if (!is_array($t) || !isset($t['id'])) {
            continue;
        }
        $clean = array_intersect_key($t, array_flip(BACKUP_TARGET_FIELDS));
        $clean['health'] = backup_target_health($t, $now, $configuredAt);
        if ($rank[$clean['health']['level']] > $rank[$level]) {
            $level = $clean['health']['level'];
        }
        $targets[] = $clean;
    }
    if (!$targets) {
        $level = 'error';
    }
    $running = false;
    $runningSince = null;
    if (is_array($s['running'] ?? null)) {
        $since = backup_ts($s['running']['started_at'] ?? null);
        if ($since !== null && $now - $since < 24 * 3600 && backup_pid_alive((int) ($s['running']['pid'] ?? 0))) {
            $running = true;
            $runningSince = $s['running']['started_at'];
        }
    }
    $schedule = is_string($s['schedule'] ?? null) ? $s['schedule'] : '03:10';
    return [
        'configured' => true,
        'level' => $level,
        'targets' => $targets,
        'next_run_at' => backup_next_run($schedule, (string) ($s['timezone'] ?? 'Europe/Istanbul'), $now),
        'running' => $running,
        'running_since' => $runningSince,
        'can_request' => is_dir($spool) && is_writable($spool),
        'last_run' => is_array($s['last_run'] ?? null) ? array_intersect_key($s['last_run'], array_flip(['started_at', 'finished_at', 'trigger', 'ok', 'db_dump_bytes', 'source_bytes', 'source_files'])) : null,
        'restore_test' => is_array($s['restore_test'] ?? null) ? array_intersect_key($s['restore_test'], array_flip(['at', 'ok', 'target', 'files_checked', 'db_ok', 'message'])) : null,
        'schedule' => $schedule,
        'include_derived' => !empty($s['include_derived']),
        'retention' => is_array($s['retention'] ?? null) ? array_map('intval', $s['retention']) : null,
    ] + $base;
}

/** "Şimdi yedekle": istek dosyasını yazar. Yalnızca yönetici. */
function backup_request_run(?array $actor): array
{
    admin_assert($actor);
    $o = backup_overview();
    if (!$o['configured']) {
        throw new ApiError(409, 'backup_not_configured', t('admin.bk_not_configured_short'));
    }
    $spool = backup_spool_dir();
    $flag = $spool . '/run-request';
    if (is_file($flag) || $o['running']) {
        return ['requested' => true, 'already' => true, 'running' => $o['running']];
    }
    if (!is_dir($spool) || !is_writable($spool)) {
        throw new ApiError(503, 'backup_spool_unwritable', t('admin.bk_spool_unwritable', ['dir' => $spool]));
    }
    $tmp = $spool . '/.run-request-' . bin2hex(random_bytes(4));
    $body = json_encode(['requested_at' => gmdate('Y-m-d\TH:i:s\Z'), 'user_id' => (int) $actor['id']]);
    if (@file_put_contents($tmp, $body) === false || !@rename($tmp, $flag)) {
        @unlink($tmp);
        throw new ApiError(503, 'backup_spool_unwritable', t('admin.bk_spool_unwritable', ['dir' => $spool]));
    }
    @chmod($flag, 0660);
    audit((int) $actor['id'], 'backup_request');
    return ['requested' => true, 'already' => false, 'running' => false];
}

/**
 * Web uygulaması kenar çubuğu / ayarlar için uyarı (yalnızca yönetici). Sağlıklıysa null.
 * level: unconfigured | warn | error
 */
function backup_alert(?array $user): ?array
{
    if ($user === null || !(int) ($user['is_admin'] ?? 0)) {
        return null;
    }
    try {
        $o = backup_overview();
    } catch (Throwable $e) {
        return null;
    }
    if ($o['level'] === 'unconfigured') {
        return ['level' => 'unconfigured', 'title' => t('admin.bk_alert_unconfigured'), 'text' => t('admin.bk_alert_unconfigured_text')];
    }
    if ($o['level'] === 'error') {
        return ['level' => 'error', 'title' => t('admin.bk_alert_error'), 'text' => t('admin.bk_alert_error_text')];
    }
    foreach ($o['targets'] as $t) {
        if (in_array($t['health']['reason'], ['last_failed', 'check_failed'], true)) {
            return ['level' => 'warn', 'title' => t('admin.bk_alert_warn'), 'text' => t('admin.bk_alert_warn_text')];
        }
    }
    return null;
}

function api_admin_backup(array $user): array
{
    admin_assert($user);
    return api_ok(['backup' => backup_overview()]);
}

function api_admin_backup_run(array $user): array
{
    backup_request_run($user);
    return api_ok(['backup' => backup_overview()], 202);
}
