<?php
/**
 * Google Takeout içe aktarma (komut satırı):
 *   php bin/import-takeout.php --user=eposta@ornek.com [--dir=/klasor] [--resume=ID]
 *
 * --dir verilmezse <depo>/import kullanılır. Kesilirse --resume=ID ile kaldığı yerden devam eder.
 */
declare(strict_types=1);

define('OM_CLI', true);
require dirname(__DIR__) . '/web/includes/bootstrap.php';

$opts = getopt('', ['user:', 'dir:', 'resume:']);
if (!empty($opts['resume'])) {
    $id = (int) $opts['resume'];
    db_exec("UPDATE imports SET status = IF(status IN ('paused','failed'), 'importing', status) WHERE id = ?", [$id]);
} else {
    $email = (string) ($opts['user'] ?? '');
    $user = $email !== '' ? user_find_by_email($email) : null;
    if ($user === null) {
        fwrite(STDERR, "Kullanım: php bin/import-takeout.php --user=eposta [--dir=klasör] | --resume=ID\n");
        exit(2);
    }
    $dir = (string) ($opts['dir'] ?? storage_path('import'));
    $id = import_create((int) $user['id'], $dir, false);
    echo "İçe aktarma #$id başladı: $dir\n";
}

$last = '';
while (true) {
    $res = import_step($id, 20);
    $imp = db_row('SELECT status, files_done, files_total, current_zip FROM imports WHERE id = ?', [$id]);
    $line = sprintf('[%s] %d / %d  %s', $imp['status'], $imp['files_done'], $imp['files_total'], (string) $imp['current_zip']);
    if ($line !== $last) {
        echo $line . "\n";
        $last = $line;
    }
    if ($res === 'done') {
        break;
    }
    if ($res === 'busy') {
        echo "Bu içe aktarma başka bir işlem tarafından yürütülüyor; bekleniyor...\n";
        sleep(10);
    }
}
$r = import_report($id, 50);
echo "\nSonuç:\n";
foreach ($r['counts'] as $k => $v) {
    printf("  %-16s %d\n", $k, $v);
}
foreach ($r['items']['failed'] as $f) {
    echo "  HATA: {$f['entry_path']} — {$f['detail']}\n";
}
echo "Ayrıntılı rapor: Yönetim paneli → İçe aktarmalar → #$id\n";
