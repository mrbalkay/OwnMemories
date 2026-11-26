<?php
/**
 * OwnMemories test çalıştırıcı:  php tests/run.php [filtre]
 * tests/unit/*.php ve tests/db/*.php dosyalarını yükler ve test() ile kaydedilenleri çalıştırır.
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/web/includes/core.php';

$filter = $argv[1] ?? '';
foreach (array_merge(glob(__DIR__ . '/unit/*.php') ?: [], glob(__DIR__ . '/db/*.php') ?: []) as $f) {
    require $f;
}

$pass = $fail = $skipped = 0;
$failures = [];
foreach ($GLOBALS['__tests'] as [$name, $fn, $group]) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        if ($group === 'db') {
            require_db();
        }
        $fn();
        $pass++;
        echo "  ok   $name\n";
    } catch (SkipTest $e) {
        $skipped++;
        echo "  skip $name — {$e->getMessage()}\n";
    } catch (Throwable $e) {
        $fail++;
        $failures[] = $name;
        echo "  FAIL $name — " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}
foreach ($GLOBALS['__tmpdirs'] ?? [] as $d) {
    rrmdir($d);
}
echo "\n$pass passed, $fail failed, $skipped skipped\n";
if ($failures) {
    echo 'Başarısız: ' . implode(', ', $failures) . "\n";
}
exit($fail > 0 ? 1 : 0);
