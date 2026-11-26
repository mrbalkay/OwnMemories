<?php
/**
 * OwnMemories — MySQL tabanlı iş kuyruğu (MySQL 5.7 uyumlu; SKIP LOCKED kullanılmaz).
 *
 * Tüketiciler: Ubuntu'da bin/worker.php (systemd), hostingde bin/cron.php veya web/cron.php (dakikalık).
 */
declare(strict_types=1);

const JOB_MAX_ATTEMPTS = 3;
const JOB_STALE_SECONDS = 1800;

function job_enqueue(string $type, array $payload, int $priority = 5, int $delaySec = 0): int
{
    $now = now_utc();
    db_exec('INSERT INTO jobs (type, payload, status, priority, run_after, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        $type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'queued', $priority, gmdate('Y-m-d H:i:s', time() + $delaySec), $now, $now,
    ]);
    return (int) db()->lastInsertId();
}

/** Bir iş alır ve kilitler; yoksa null. Payload dizi olarak döner. */
function job_claim(string $workerId): ?array
{
    $now = now_utc();
    // Çöken işçilerin (ör. PHP bellek hatası) işleri: deneme hakkı varsa kuyruğa döner, yoksa başarısız olur.
    foreach (db_all("SELECT * FROM jobs WHERE status = 'running' AND locked_at < ? LIMIT 50", [gmdate('Y-m-d H:i:s', time() - JOB_STALE_SECONDS)]) as $stale) {
        job_fail($stale, 'İşçi yanıt vermeden durdu (zaman aşımı veya bellek hatası)', 0);
    }
    $token = mb_substr($workerId, 0, 40) . ':' . bin2hex(random_bytes(6));
    $n = db_exec("UPDATE jobs SET status = 'running', locked_by = ?, locked_at = ?, attempts = attempts + 1, updated_at = ?
                  WHERE status = 'queued' AND run_after <= ? ORDER BY priority, id LIMIT 1", [$token, $now, $now, $now]);
    if ($n === 0) {
        return null;
    }
    $job = db_row("SELECT * FROM jobs WHERE locked_by = ? AND status = 'running' ORDER BY id DESC LIMIT 1", [$token]);
    if ($job === null) {
        return null;
    }
    db_exec('UPDATE jobs SET locked_by = ? WHERE id = ?', [mb_substr($workerId, 0, 64), $job['id']]);
    $job['locked_by'] = $workerId;
    $job['payload'] = json_decode((string) $job['payload'], true) ?: [];
    return $job;
}

function job_complete(int $id, string $status = 'done', ?string $err = null): void
{
    db_exec('UPDATE jobs SET status = ?, last_error = ?, locked_by = NULL, locked_at = NULL, updated_at = ? WHERE id = ?', [$status, $err, now_utc(), $id]);
}

/** Hata: deneme hakkı varsa ertelenerek kuyruğa döner, yoksa 'failed'. */
function job_fail(array $job, string $err, ?int $delaySec = null): void
{
    $attempts = (int) $job['attempts'];
    $delaySec ??= $attempts * 300;
    if ($attempts < JOB_MAX_ATTEMPTS) {
        db_exec("UPDATE jobs SET status = 'queued', last_error = ?, locked_by = NULL, locked_at = NULL, run_after = ?, updated_at = ? WHERE id = ?", [
            mb_substr($err, 0, 4000), gmdate('Y-m-d H:i:s', time() + $delaySec), now_utc(), $job['id'],
        ]);
    } else {
        job_complete((int) $job['id'], 'failed', mb_substr($err, 0, 4000));
        $payload = is_array($job['payload']) ? $job['payload'] : (json_decode((string) $job['payload'], true) ?: []);
        if ($job['type'] === 'import_step' && !empty($payload['import_id'])) {
            db_exec("UPDATE imports SET status = 'failed', last_error = ? WHERE id = ? AND status NOT IN ('done','paused')", [mb_substr($err, 0, 2000), (int) $payload['import_id']]);
        }
    }
}

/**
 * İş türü → işleyici. İşleyici 'done' | 'skipped' | 'requeued' döner (requeued: kendini yeniden kuyruğa koydu).
 * @return array<string, callable(array):string>
 */
function job_handlers(): array
{
    $h = [
        'noop' => fn(array $p): string => 'done',
        'render' => 'job_render',
    ];
    if (function_exists('import_job_step')) {
        $h['import_step'] = 'import_job_step';
    }
    return array_merge($h, $GLOBALS['__om_job_handlers_extra'] ?? []);
}

/** $budgetSec süresince iş işler; işlenen iş sayısını döner. */
function jobs_run_for(int $budgetSec, string $workerId): int
{
    $deadline = microtime(true) + $budgetSec;
    $handlers = job_handlers();
    $n = 0;
    while (microtime(true) < $deadline) {
        $job = job_claim($workerId);
        if ($job === null) {
            break;
        }
        $n++;
        $fn = $handlers[$job['type']] ?? null;
        if ($fn === null) {
            job_complete((int) $job['id'], 'failed', 'Bilinmeyen iş türü: ' . $job['type']);
            continue;
        }
        try {
            $GLOBALS['__om_job_deadline'] = $deadline;
            $res = $fn($job['payload']);
            job_complete((int) $job['id'], in_array($res, ['done', 'skipped'], true) ? $res : 'done');
        } catch (Throwable $e) {
            app_log('jobs', "#{$job['id']} {$job['type']}: " . $e->getMessage());
            job_fail($job, get_class($e) . ': ' . $e->getMessage());
        }
    }
    return $n;
}

/** İşleyicilerin kalan süreyi bilmesi için (saniye). */
function job_time_left(): float
{
    return max(0.0, (float) ($GLOBALS['__om_job_deadline'] ?? (microtime(true) + 60)) - microtime(true));
}

/** Küçük resim (360) ve önizleme (2048) üretir; video için kare alınır. */
function job_render(array $p): string
{
    $a = db_row('SELECT id, type, blob_sha1 FROM assets WHERE id = ?', [(string) ($p['asset_id'] ?? '')]);
    if ($a === null) {
        return 'skipped';
    }
    $src = blob_path($a['blob_sha1']);
    if (!is_file($src)) {
        db_exec("UPDATE assets SET thumb_state = 'failed' WHERE id = ?", [$a['id']]);
        throw new RuntimeException('Orijinal dosya bulunamadı: ' . $a['blob_sha1']);
    }
    $fmt = media_preferred_format();
    $ext = $fmt === 'webp' ? 'webp' : 'jpg';
    derived_delete($a['id']);
    $state = media_render($src, $a['type'], derived_path($a['id'], 'thumb', $ext), THUMB_EDGE, $fmt);
    if ($state === 'ready') {
        media_render($src, $a['type'], derived_path($a['id'], 'preview', $ext), PREVIEW_EDGE, $fmt);
    }
    db_exec('UPDATE assets SET thumb_state = ? WHERE id = ?', [$state, $a['id']]);
    return $state === 'ready' ? 'done' : 'skipped';
}

/** Bakım: günde bir çöp temizliği ve süresi geçmiş yüklemeler. */
function maintenance_daily(): void
{
    $last = (int) site_setting('maintenance_last', '0');
    if (time() - $last < 86400) {
        return;
    }
    site_setting_set('maintenance_last', (string) time());
    $n = trash_purge_expired();
    if (function_exists('uploads_cleanup')) {
        uploads_cleanup();
    }
    db_exec("DELETE FROM jobs WHERE status IN ('done','skipped') AND updated_at < ?", [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
    db_exec('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);
    app_log('cron', "günlük bakım: $n asset çöpten silindi");
}

/** Cron girişi (CLI veya web): kilitli, süre sınırlı. İşlenen iş sayısı veya kilit doluysa -1. */
function cron_tick(int $budgetSec = 50): int
{
    $locked = (int) db()->query("SELECT GET_LOCK('om_cron', 0)")->fetchColumn() === 1;
    if (!$locked) {
        return -1;
    }
    try {
        site_setting_set('cron_last_run', (string) time());
        maintenance_daily();
        return jobs_run_for($budgetSec, 'cron-' . getmypid());
    } finally {
        db()->query("SELECT RELEASE_LOCK('om_cron')");
    }
}
