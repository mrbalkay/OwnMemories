<?php
/**
 * Yerler: GeoNames dosyalarını veritabanına yükler (server/install-geo.sh indirir ve bunu çağırır).
 *   php bin/geo-import.php [--dir=/var/lib/ownmemories/geo] [--force] [--backfill]
 *
 * Dosyalar değişmediyse içe aktarma atlanır (--force ile zorlanır). Veri değiştiyse fotoğraflar için
 * toplu yer bulma işi (geocode_backfill) kuyruğa girer; işçi (ownmemories-worker) arka planda işler.
 * --backfill: içe aktarmadan yalnızca eksik yerler için toplu işi kuyruğa koyar.
 * Veri: GeoNames (https://www.geonames.org), CC BY 4.0.
 */
declare(strict_types=1);

define('OM_CLI', true);
require dirname(__DIR__) . '/web/includes/bootstrap.php';

$opts = getopt('', ['dir:', 'force', 'backfill']);
$dir = rtrim((string) ($opts['dir'] ?? (getenv('OM_GEO_DIR') ?: '/var/lib/ownmemories/geo')), '/');
migrations_run();
try {
    if (isset($opts['backfill'])) {
        echo places_enqueue_backfill() ? "Toplu yer bulma işi kuyruğa alındı.\n" : "Toplu yer bulma işi zaten kuyrukta.\n";
        exit(0);
    }
    $t = microtime(true);
    $r = geo_import($dir, fn(string $m) => print("  $m\n"), isset($opts['force']));
    printf("Yer verisi: %d yer, %d ülke (sürüm %s) — %.1f sn\n", $r['places'], $r['countries'], $r['version'], microtime(true) - $t);
    printf("Yer adı bekleyen fotoğraf: %d\n", places_pending_count());
} catch (Throwable $e) {
    fwrite(STDERR, 'HATA: ' . $e->getMessage() . "\n");
    exit(1);
}
