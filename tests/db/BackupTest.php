<?php
declare(strict_types=1);

/** Yedek durum dosyası ve istek klasörünü geçici yerlere yönlendirir. */
function bk_setup(?array $status = null, bool $spool = true): array
{
    $d = temp_dir('ombk');
    $cfg = config_all();
    $cfg['backup'] = ['status_file' => "$d/backup-status.json", 'spool_dir' => "$d/spool"];
    config_all($cfg);
    if ($spool) {
        mkdir("$d/spool", 0770, true);
    }
    if ($status !== null) {
        file_put_contents("$d/backup-status.json", json_encode($status));
    }
    return ['dir' => $d, 'status' => "$d/backup-status.json", 'spool' => "$d/spool"];
}

function bk_iso(int $ts): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

function bk_status(int $now, array $targets, array $extra = []): array
{
    return $extra + [
        'version' => 1, 'configured' => true, 'configured_at' => bk_iso($now - 10 * 86400), 'updated_at' => bk_iso($now - 3600),
        'schedule' => '03:10', 'timezone' => 'Europe/Istanbul', 'include_derived' => false,
        'retention' => ['daily' => 7, 'weekly' => 8, 'monthly' => 24, 'yearly' => 5],
        'running' => null, 'targets' => $targets,
    ];
}

function bk_target(string $id, array $f = []): array
{
    return $f + ['id' => $id, 'type' => 'local', 'label' => 'Yerel disk', 'location' => '/mnt/yedek', 'last_status' => 'never'];
}

function bk_admin(): array
{
    return user_find(user_create('sahip@example.com', 'parola12345', 'Sahip', true));
}

dbtest('test_backup_unconfigured_when_no_status_file', function () {
    bk_setup(null);
    $o = backup_overview();
    assert_eq(false, $o['configured']);
    assert_eq('unconfigured', $o['level']);
    assert_eq([], $o['targets']);
    assert_true(str_contains($o['setup_command'], 'setup-backup.sh'));
});

dbtest('test_backup_corrupt_status_is_unconfigured_not_crash', function () {
    $p = bk_setup(null);
    file_put_contents($p['status'], '{bozuk');
    assert_eq('unconfigured', backup_overview()['level']);
});

dbtest('test_backup_target_health_rules', function () {
    $now = 1_800_000_000;
    $h = fn(array $t) => backup_target_health(bk_target('disk', $t), $now, $now - 10 * 86400)['level'];
    assert_eq('ok', $h(['last_status' => 'ok', 'last_success_at' => bk_iso($now - 3 * 3600)]));
    assert_eq('warn', $h(['last_status' => 'ok', 'last_success_at' => bk_iso($now - 36 * 3600)]), '30 saati geçen başarı gecikmiş');
    assert_eq('error', $h(['last_status' => 'ok', 'last_success_at' => bk_iso($now - 49 * 3600)]), '48 saat başarısız = kırmızı');
    assert_eq('warn', $h(['last_status' => 'failed', 'last_success_at' => bk_iso($now - 20 * 3600), 'last_failure_at' => bk_iso($now - 3600)]));
    assert_eq('error', $h(['last_status' => 'failed', 'last_failure_at' => bk_iso($now - 3600)]), 'hiç başarı yok ve hata');
    assert_eq('error', $h(['last_status' => 'never']), 'kurulum 10 gün önce, hiç yedek yok');
    assert_eq('warn', $h(['last_status' => 'ok', 'last_success_at' => bk_iso($now - 3600), 'last_check_ok' => false]), 'bütünlük denetimi başarısız');
    // Yeni kurulum: 48 saat dolmadan hiç yedek olmaması sarıdır.
    assert_eq('warn', backup_target_health(bk_target('disk'), $now, $now - 3600)['level']);
});

dbtest('test_backup_overview_levels_and_next_run', function () {
    $now = 1_800_000_000; // 2027-01-15T08:00:00Z = 11:00 İstanbul
    bk_setup(bk_status($now, [
        bk_target('disk', ['last_status' => 'ok', 'last_success_at' => bk_iso($now - 5 * 3600), 'repo_size' => 123456, 'snapshot_id' => 'abcd1234']),
        bk_target('offsite', ['type' => 's3', 'label' => 'Backblaze B2', 'location' => 's3:…/bucket', 'last_status' => 'failed', 'last_failure_at' => bk_iso($now - 5 * 3600), 'error' => 'bağlantı kurulamadı']),
    ]));
    $o = backup_overview($now);
    assert_eq(true, $o['configured']);
    assert_eq('error', $o['level'], 'en kötü hedef belirler');
    assert_eq('ok', $o['targets'][0]['health']['level']);
    assert_eq('error', $o['targets'][1]['health']['level']);
    // 03:10 İstanbul (UTC+3) = 00:10 UTC, ertesi gün.
    assert_eq('2027-01-16T00:10:00Z', $o['next_run_at']);
    assert_eq(false, $o['running']);
    assert_eq(false, $o['requested']);
});

dbtest('test_backup_next_run_same_day_and_bad_input', function () {
    $now = (new DateTimeImmutable('2027-01-15 01:00:00', new DateTimeZone('Europe/Istanbul')))->getTimestamp();
    assert_eq('2027-01-15T00:10:00Z', backup_next_run('03:10', 'Europe/Istanbul', $now));
    assert_eq(null, backup_next_run('25:99', 'Europe/Istanbul', $now));
    assert_eq('2027-01-15T00:10:00Z', backup_next_run('03:10', 'Gecersiz/Bolge', $now), 'bilinmeyen bölge → İstanbul');
});

dbtest('test_backup_running_detected_only_for_live_pid', function () {
    $now = time();
    // Gerçek yedek süreci gibi adlandırılmış canlı bir süreç.
    $p = proc_open(['bash', '-c', 'exec -a /var/www/ownmemories/server/backup.sh sleep 20'], [], $pipes);
    $pid = proc_get_status($p)['pid'];
    usleep(200000);
    bk_setup(bk_status($now, [bk_target('disk')], ['running' => ['started_at' => bk_iso($now - 60), 'pid' => $pid, 'trigger' => 'manual']]));
    assert_eq(true, backup_overview($now)['running']);
    proc_terminate($p);
    proc_close($p);
    bk_setup(bk_status($now, [bk_target('disk')], ['running' => ['started_at' => bk_iso($now - 60), 'pid' => 999999999, 'trigger' => 'manual']]));
    assert_eq(false, backup_overview($now)['running'], 'ölü süreç çalışıyor sayılmaz');
    // Elektrik kesintisinden sonra aynı PID başka bir sürece verilmiş olabilir: yedek sayılmaz.
    bk_setup(bk_status($now, [bk_target('disk')], ['running' => ['started_at' => bk_iso($now - 60), 'pid' => getmypid(), 'trigger' => 'manual']]));
    assert_eq(false, backup_overview($now)['running'], 'PID başka bir sürece ait');
});

dbtest('test_backup_request_run_creates_flag_and_is_idempotent', function () {
    $admin = bk_admin();
    $p = bk_setup(bk_status(time(), [bk_target('disk')]));
    $r = backup_request_run($admin);
    assert_eq(true, $r['requested']);
    assert_eq(false, $r['already']);
    assert_true(is_file($p['spool'] . '/run-request'));
    assert_eq(true, backup_overview()['requested']);
    $r2 = backup_request_run($admin);
    assert_eq(true, $r2['already']);
    assert_eq(1, (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'backup_request'"));
});

dbtest('test_backup_request_run_rejects_non_admin_and_unconfigured', function () {
    $normal = user_find(user_create('es@example.com', 'parola12345', 'Eş'));
    bk_setup(bk_status(time(), [bk_target('disk')]));
    assert_eq(403, assert_throws(ApiError::class, fn() => backup_request_run($normal))->status);
    bk_setup(null);
    assert_eq(409, assert_throws(ApiError::class, fn() => backup_request_run(bk_admin()))->status);
    bk_setup(bk_status(time(), [bk_target('disk')]), false);
    $e = assert_throws(ApiError::class, fn() => backup_request_run(user_find((int) db_val("SELECT id FROM users WHERE email='sahip@example.com'"))));
    assert_eq(503, $e->status, 'istek klasörü yoksa');
});

dbtest('test_backup_api_routes', function () {
    $admin = bk_admin();
    $normal = user_find(user_create('es@example.com', 'parola12345', 'Eş'));
    $p = bk_setup(bk_status(time(), [bk_target('disk', ['last_status' => 'ok', 'last_success_at' => bk_iso(time() - 60), 'error' => null])]));
    $r = api_route('GET', '/v1/admin/backup', $admin);
    assert_eq(200, $r['status']);
    assert_eq('ok', $r['json']['backup']['level']);
    assert_eq('disk', $r['json']['backup']['targets'][0]['id']);
    assert_eq(403, assert_throws(ApiError::class, fn() => api_route('GET', '/v1/admin/backup', $normal))->status);
    assert_eq(401, assert_throws(ApiError::class, fn() => api_route('GET', '/v1/admin/backup', null))->status);
    $run = api_route('POST', '/v1/admin/backup/run', $admin);
    assert_eq(202, $run['status']);
    assert_eq(true, $run['json']['backup']['requested']);
    assert_true(is_file($p['spool'] . '/run-request'));
    assert_eq(403, assert_throws(ApiError::class, fn() => api_route('POST', '/v1/admin/backup/run', $normal))->status);
});

dbtest('test_backup_alert_only_for_admins_when_needed', function () {
    $admin = bk_admin();
    $normal = user_find(user_create('es@example.com', 'parola12345', 'Eş'));
    bk_setup(null);
    assert_eq('unconfigured', backup_alert($admin)['level']);
    assert_eq(null, backup_alert($normal));
    $now = time();
    bk_setup(bk_status($now, [bk_target('disk', ['last_status' => 'ok', 'last_success_at' => bk_iso($now - 3600)])]));
    assert_eq(null, backup_alert($admin), 'sağlıklıyken uyarı yok');
    bk_setup(bk_status($now, [bk_target('disk', ['last_status' => 'failed', 'last_success_at' => bk_iso($now - 3600), 'last_failure_at' => bk_iso($now - 60)])]));
    assert_eq('warn', backup_alert($admin)['level'], 'son deneme başarısızsa uyar');
    bk_setup(bk_status($now, [bk_target('disk', ['last_status' => 'ok', 'last_success_at' => bk_iso($now - 72 * 3600)])]));
    assert_eq('error', backup_alert($admin)['level']);
});

dbtest('test_backup_status_never_exposes_secret_like_fields', function () {
    $admin = bk_admin();
    bk_setup(bk_status(time(), [bk_target('disk', ['password' => 'x', 'aws_secret_access_key' => 'y', 'repo' => 's3:https://k:s@h/b'])]));
    $t = api_route('GET', '/v1/admin/backup', $admin)['json']['backup']['targets'][0];
    assert_true(!isset($t['password']) && !isset($t['aws_secret_access_key']) && !isset($t['repo']), 'yalnızca izinli alanlar');
});
