<?php
/**
 * OwnMemories — şema kurulumu ve otomatik migrasyonlar.
 *
 * Kurulu şema sürümü site_settings.schema_version'da tutulur. install/migrations/NNN_ad.sql
 * dosyalarından NNN > sürüm olanlar sırayla uygulanır. Migrasyonlar tekrar çalıştırılabilir olmalı.
 */
declare(strict_types=1);

/** Bu kodla gelen en yüksek migrasyon numarası. Yeni migrations/NNN_*.sql ile birlikte artırılır. */
const SCHEMA_VERSION = 6;

/** SQL dosyasını ifadelere böler (satır sonundaki ";" ile biten; "--" yorumları atılır). */
function sql_statements(string $sql): array
{
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    $parts = preg_split('/;\s*$/m', $sql) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
}

/** Temel şemayı kurar (tekrar çalıştırılabilir) ve sürümü SCHEMA_VERSION olarak işaretler. */
function schema_install(PDO $pdo): void
{
    foreach (sql_statements((string) file_get_contents(OM_WEB . '/install/schema.sql')) as $stmt) {
        $pdo->exec($stmt);
    }
    $st = $pdo->prepare('INSERT INTO site_settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = GREATEST(CAST(value AS UNSIGNED), CAST(VALUES(value) AS UNSIGNED))');
    $st->execute(['schema_version', (string) SCHEMA_VERSION]);
    site_setting_cache_clear();
}

function migration_files(): array
{
    $out = [];
    foreach (glob(OM_WEB . '/install/migrations/*.sql') ?: [] as $f) {
        if (preg_match('/^(\d{3,})_[a-z0-9_-]+\.sql$/i', basename($f), $m)) {
            $out[(int) $m[1]] = $f;
        }
    }
    ksort($out);
    return $out;
}

/** Bekleyen migrasyonları uygular. Asla istisna fırlatmaz; hata günlüğe yazılır. */
function migrations_run(): int
{
    $current = (int) site_setting('schema_version', '1');
    if ($current >= SCHEMA_VERSION) {
        return $current;
    }
    $locked = false;
    try {
        $pdo = db();
        $locked = (int) $pdo->query("SELECT GET_LOCK('om_migrate', 0)")->fetchColumn() === 1;
        if (!$locked) {
            return $current;
        }
        foreach (migration_files() as $n => $file) {
            if ($n <= $current || $n > SCHEMA_VERSION) {
                continue;
            }
            foreach (sql_statements((string) file_get_contents($file)) as $stmt) {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    // Yinelenen sütun/anahtar (tekrar çalıştırma) yok sayılır.
                    if (!in_array((int) ($e->errorInfo[1] ?? 0), [1060, 1061, 1050, 1091], true)) {
                        throw $e;
                    }
                }
            }
            site_setting_set('schema_version', (string) $n);
            $current = $n;
        }
    } catch (Throwable $e) {
        app_log('migrate', $e->getMessage());
    } finally {
        if ($locked) {
            db()->query("SELECT RELEASE_LOCK('om_migrate')");
        }
    }
    return $current;
}
