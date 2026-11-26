<?php
/**
 * OwnMemories — MySQL tabanlı iş kuyruğu.
 */
declare(strict_types=1);

function job_enqueue(string $type, array $payload, int $priority = 5, int $delaySec = 0): int
{
    $now = now_utc();
    db_exec('INSERT INTO jobs (type, payload, status, priority, run_after, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        $type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'queued', $priority, gmdate('Y-m-d H:i:s', time() + $delaySec), $now, $now,
    ]);
    return (int) db()->lastInsertId();
}
