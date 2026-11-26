<?php
/**
 * OwnMemories web cron'u — CLI cron'u olmayan hostingler için:
 *   https://adres/cron.php?key=<config cron.key>   (her dakika çağrılır)
 */
declare(strict_types=1);

define('OM_API', true);
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$key = (string) ($_GET['key'] ?? '');
if ($key === '' || !hash_equals((string) config('cron.key', ''), $key)) {
    http_response_code(403);
    exit("forbidden\n");
}
ignore_user_abort(true);
@set_time_limit(90);
$n = cron_tick(40);
echo $n < 0 ? "busy\n" : "ok $n\n";
