<?php
/**
 * OwnMemories — bağımlılıksız mini test kütüphanesi.
 * Kullanım: test('ad', function () { assert_eq(1, 1); });
 */
declare(strict_types=1);

define('OM_TESTING', true);
define('OM_CLI', true);
$GLOBALS['__tests'] = [];

function test(string $name, callable $fn, string $group = 'unit'): void
{
    $GLOBALS['__tests'][] = [$name, $fn, $group];
}

/** DB gerektiren test; test veritabanı yoksa atlanır. */
function dbtest(string $name, callable $fn): void
{
    test($name, $fn, 'db');
}

final class AssertionFailed extends Exception {}
final class SkipTest extends Exception {}

function skip(string $why): never
{
    throw new SkipTest($why);
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg !== '' ? $msg . ': ' : '') . 'beklenen ' . var_export($expected, true) . ', gelen ' . var_export($actual, true));
    }
}

function assert_true($cond, string $msg = ''): void
{
    if ($cond !== true) {
        throw new AssertionFailed($msg !== '' ? $msg : 'true bekleniyordu, gelen ' . var_export($cond, true));
    }
}

function assert_throws(string $class, callable $fn, string $msg = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed(($msg ?: 'istisna türü') . ": beklenen $class, gelen " . get_class($e) . ' (' . $e->getMessage() . ')');
    }
    throw new AssertionFailed(($msg ?: 'istisna') . ": $class fırlatılmadı");
}

function fixture(string $rel): string
{
    return __DIR__ . '/fixtures/' . ltrim($rel, '/');
}

/** Geçici, test başına boş bir klasör. */
function temp_dir(string $prefix = 'om'): string
{
    $d = sys_get_temp_dir() . '/' . $prefix . '_' . bin2hex(random_bytes(5));
    mkdir($d, 0777, true);
    $GLOBALS['__tmpdirs'][] = $d;
    return $d;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/**
 * Şeması kurulmuş, her testte boşaltılmış test veritabanı ve geçici depo.
 * OM_TEST_DB_HOST/PORT/USER/PASS/NAME ortam değişkenleri yoksa null.
 */
function test_db(): ?PDO
{
    static $ready = null;
    $host = getenv('OM_TEST_DB_HOST');
    if ($host === false || $host === '') {
        return null;
    }
    if ($ready === null) {
        $name = getenv('OM_TEST_DB_NAME') ?: 'om_test';
        $c = ['host' => $host, 'port' => (int) (getenv('OM_TEST_DB_PORT') ?: 3306), 'user' => getenv('OM_TEST_DB_USER') ?: 'root', 'pass' => getenv('OM_TEST_DB_PASS') ?: '', 'name' => ''];
        $pdo = db_connect($c);
        $pdo->exec("DROP DATABASE IF EXISTS `$name`");
        $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $c['name'] = $name;
        $ready = $c;
        config_all(['app' => ['url' => 'http://localhost', 'name' => 'OwnMemories'], 'db' => $c, 'storage' => ['path' => temp_dir('omstore')], 'cron' => ['key' => 'k']]);
        db(db_connect($c));
        schema_install(db());
    }
    // Her test temiz başlar.
    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if ($t !== 'site_settings') {
            $pdo->exec("TRUNCATE TABLE `$t`");
        }
    }
    $pdo->exec("DELETE FROM site_settings WHERE `key` <> 'schema_version'");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $store = temp_dir('omstore');
    $cfg = config_all();
    $cfg['storage']['path'] = $store;
    config_all($cfg);
    site_setting_cache_clear();
    if (function_exists('tools_override')) {
        tools_override(null);
    }
    return $pdo;
}

/** Kilit testleri için ikinci bağımsız bağlantı. */
function test_db_second_connection(): PDO
{
    return db_connect(config('db'));
}

function require_db(): PDO
{
    $pdo = test_db();
    if ($pdo === null) {
        skip('test veritabanı yok (OM_TEST_DB_HOST)');
    }
    return $pdo;
}
