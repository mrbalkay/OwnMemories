<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib_takeout.php';

dbtest('test_non_admin_forbidden', function () {
    $admin = user_find(user_create('sahip@example.com', 'parola12345', 'Sahip', true));
    $normal = user_find(user_create('es@example.com', 'parola12345', 'Eş'));
    admin_assert($admin);
    assert_throws(ApiError::class, fn() => admin_assert($normal));
    assert_throws(ApiError::class, fn() => admin_assert(null));
});

dbtest('test_create_spouse_user', function () {
    $admin = user_find(user_create('sahip@example.com', 'parola12345', 'Sahip', true));
    $id = admin_create_user($admin, ['email' => 'es@example.com', 'name' => 'Eş', 'password' => 'parola12345', 'quota_gb' => '500', 'is_admin' => '']);
    $u = user_find($id);
    assert_eq('Eş', $u['name']);
    assert_eq(500 * 1024 ** 3, (int) $u['quota_bytes']);
    assert_eq(0, (int) $u['is_admin']);
    assert_true(user_verify_password('es@example.com', 'parola12345') !== null);
    $id2 = admin_create_user($admin, ['email' => 'c@example.com', 'name' => 'C', 'password' => 'parola12345', 'quota_gb' => '']);
    assert_eq(null, user_find($id2)['quota_bytes'], 'boş kota = sınırsız');
    assert_eq(1, (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'user_create' AND details LIKE '%es@example.com%'"));
    $ov = admin_overview();
    assert_eq(3, count($ov['users']));
});

dbtest('test_import_report_csv_rows', function () {
    $u = user_create('sahip@example.com', 'parola12345', 'Sahip', true);
    $id = import_create($u, tk_standard_takeout(), false);
    tk_run_import($id);
    $csv = import_report_csv($id);
    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
    assert_eq(['zip', 'path', 'result', 'asset_id', 'detail'], $rows[0]);
    assert_eq(11, count($rows), 'başlık + 10 medya satırı');
    $results = array_count_values(array_column(array_slice($rows, 1), 2));
    assert_eq(7, $results['imported']);
    assert_eq(1, $results['no_metadata']);
});

dbtest('test_rerender_all_enqueues_jobs', function () {
    $u = user_create('sahip@example.com', 'parola12345', 'Sahip', true);
    $id = import_create($u, tk_standard_takeout(), false);
    tk_run_import($id);
    db_exec('DELETE FROM jobs');
    assert_eq(8, admin_rerender_all());
    assert_eq(8, (int) db_val("SELECT COUNT(*) FROM jobs WHERE type='render' AND status='queued'"));
});
