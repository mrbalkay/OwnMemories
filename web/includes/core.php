<?php
/**
 * OwnMemories — tüm çekirdek kütüphaneleri yükler (yan etkisiz).
 * bootstrap.php, CLI betikleri ve testler bunu kullanır.
 */
declare(strict_types=1);

if (!defined('OM_WEB')) {
    define('OM_WEB', dirname(__DIR__));
}
if (!defined('OM_ROOT')) {
    define('OM_ROOT', dirname(OM_WEB));
}
if (!defined('OM_VERSION')) {
    define('OM_VERSION', '0.1.0');
}

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

foreach (['functions', 'i18n', 'migrate', 'tools', 'auth', 'media', 'storage', 'jobs', 'assets', 'albums', 'takeout', 'importer', 'uploads', 'api', 'serve'] as $__lib) {
    $__f = __DIR__ . '/' . $__lib . '.php';
    if (is_file($__f)) {
        require_once $__f;
    }
}
unset($__lib, $__f);
