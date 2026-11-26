<?php
/**
 * OwnMemories iş kuyruğu işçisi (sürekli çalışır). systemd: server/ownmemories-worker.service
 *   php bin/worker.php [--once]
 */
declare(strict_types=1);

define('OM_CLI', true);
require dirname(__DIR__) . '/web/includes/bootstrap.php';

$once = in_array('--once', $argv, true);
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });
    pcntl_signal(SIGINT, function () use (&$stop) { $stop = true; });
}
$id = gethostname() . '-' . getmypid();
fwrite(STDOUT, "[worker $id] başladı\n");
$idle = 0;
while (!$stop) {
    try {
        $n = jobs_run_for(30, $id);
        site_setting_set('worker_last_seen', (string) time());
        if ($n === 0) {
            if ($once) {
                break;
            }
            sleep(min(10, 2 + $idle++));
        } else {
            $idle = 0;
        }
        maintenance_daily();
    } catch (Throwable $e) {
        // Veritabanı kopmuş olabilir: biraz bekle, bağlantıyı yenile.
        fwrite(STDERR, '[worker] ' . $e->getMessage() . "\n");
        app_log('worker', $e->getMessage());
        sleep(10);
        db(db_connect(['host' => config('db.host'), 'port' => (int) config('db.port', 3306), 'name' => config('db.name'), 'user' => config('db.user'), 'pass' => config('db.pass')]));
    }
}
fwrite(STDOUT, "[worker $id] durdu\n");
