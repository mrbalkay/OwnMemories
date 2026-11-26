<?php
/**
 * OwnMemories cron (her dakika):  * * * * * php /yol/bin/cron.php
 * Süre sınırlı olarak kuyruktan iş işler ve günlük bakımı yapar. Aynı anda tek kopya çalışır.
 */
declare(strict_types=1);

define('OM_CLI', true);
require dirname(__DIR__) . '/web/includes/bootstrap.php';

$budget = (int) ($argv[1] ?? 50);
$n = cron_tick(max(5, $budget));
echo $n < 0 ? "cron zaten çalışıyor\n" : "$n iş işlendi\n";
