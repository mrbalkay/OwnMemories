<?php
declare(strict_types=1);

dbtest('test_schema_installs_and_is_idempotent', function () {
    schema_install(db());
    schema_install(db());
    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['users', 'api_tokens', 'blobs', 'assets', 'deleted_checksums', 'albums', 'album_assets', 'album_members', 'asset_people', 'people', 'asset_person', 'faces', 'face_scans', 'jobs', 'imports', 'import_items', 'uploads', 'site_settings', 'rate_limits', 'audit_log'] as $t) {
        assert_true(in_array($t, $tables, true), "tablo eksik: $t");
    }
    assert_eq((string) SCHEMA_VERSION, (string) site_setting('schema_version'));
});
