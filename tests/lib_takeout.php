<?php
/** Test için sahte Google Takeout zip'leri üretir. */
declare(strict_types=1);

/** Benzersiz baytlı küçük JPEG içeriği. */
function tk_jpeg_bytes(int $seed, int $w = 48, int $h = 32): string
{
    $i = imagecreatetruecolor($w, $h);
    imagefilledrectangle($i, 0, 0, $w, $h, imagecolorallocate($i, ($seed * 41) % 256, ($seed * 89) % 256, ($seed * 17) % 256));
    imagesetpixel($i, 0, 0, imagecolorallocate($i, $seed % 256, 0, 0));
    ob_start();
    imagejpeg($i, null, 90);
    return (string) ob_get_clean();
}

function tk_sidecar(int $ts, array $extra = []): string
{
    return json_encode(array_merge(['title' => 'x', 'photoTakenTime' => ['timestamp' => (string) $ts], 'geoData' => ['latitude' => 0.0, 'longitude' => 0.0, 'altitude' => 0.0]], $extra), JSON_UNESCAPED_UNICODE);
}

/** $files: [zip içi yol => içerik]. */
function tk_make_zip(string $path, array $files): string
{
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $z->addFromString($name, $content);
    }
    $z->close();
    return $path;
}

/** Plan Task 8'deki iki zip'lik senaryo. Dönen: klasör yolu. */
function tk_standard_takeout(): string
{
    $dir = temp_dir('takeout');
    $p = 'Takeout/Google Fotoğraflar/';
    $y = $p . 'Photos from 2019/';
    $img1 = tk_jpeg_bytes(1);
    tk_make_zip("$dir/takeout-20261126T000000Z-001.zip", [
        $y . 'IMG_1.jpg' => $img1,
        $y . 'IMG_1.jpg.json' => tk_sidecar(1561982645, ['favorited' => true, 'people' => [['name' => 'Ayşe']], 'geoData' => ['latitude' => 38.42, 'longitude' => 27.13, 'altitude' => 5.0]]),
        $y . 'IMG_2.jpg' => tk_jpeg_bytes(2),
        $y . 'IMG_3.jpg' => tk_jpeg_bytes(3),
        $y . 'IMG_3.jpg.json' => tk_sidecar(1561990000, ['description' => 'Üçüncü']),
        $p . 'Tatil 2019/metadata.json' => json_encode(['title' => 'Tatil 2019', 'description' => '']),
        $p . 'Tatil 2019/IMG_1.jpg' => $img1,
        $p . 'Tatil 2019/IMG_1.jpg.json' => tk_sidecar(1561982645),
    ]);
    tk_make_zip("$dir/takeout-20261126T000000Z-002.zip", [
        $y . 'IMG_2.jpg.supplemental-metadata.json' => tk_sidecar(1561985000, ['archived' => true]),
        $y . 'IMG_TRASH.jpg' => tk_jpeg_bytes(4),
        $y . 'IMG_TRASH.jpg.json' => tk_sidecar(1561986000, ['trashed' => true]),
        $y . 'nometa.jpg' => tk_jpeg_bytes(5),
        $y . 'IMG_L.JPG' => tk_jpeg_bytes(6),
        $y . 'IMG_L.MP4' => 'sahte-video-baytlari',
        $y . 'IMG_L.JPG.json' => tk_sidecar(1561987000),
        $y . 'IMG_E.jpg' => tk_jpeg_bytes(7),
        $y . 'IMG_E-edited.jpg' => tk_jpeg_bytes(8),
        $y . 'IMG_E.jpg.json' => tk_sidecar(1561988000),
        'Takeout/archive_browser.html' => '<html></html>',
    ]);
    return $dir;
}

/** İçe aktarmayı sonuna kadar çalıştırır. */
function tk_run_import(int $importId, int $budget = 30, int $maxSteps = 1000): void
{
    for ($i = 0; $i < $maxSteps; $i++) {
        if (import_step($importId, $budget) === 'done') {
            return;
        }
    }
    throw new RuntimeException('import bitmedi');
}
