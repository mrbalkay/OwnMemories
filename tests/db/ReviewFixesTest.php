<?php
/** Son incelemede bulunan sorunların regresyon testleri. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib_takeout.php';

function rf_user(): int
{
    return user_create('ali@example.com', 'parola12345', 'Ali');
}

// I1: zayıf tarihli (dosya zamanı) farklı fotoğraflar ikincil kontrolle yutulmamalı
dbtest('test_secondary_dupe_ignores_weak_dates', function () {
    $u = rf_user();
    $d = temp_dir();
    file_put_contents("$d/a.jpg", tk_jpeg_bytes(31));
    file_put_contents("$d/b.jpg", tk_jpeg_bytes(32));
    touch("$d/a.jpg", 1600000000);
    touch("$d/b.jpg", 1600000000);
    tools_override(['exiftool' => null]);
    $r1 = asset_ingest($u, "$d/a.jpg", ['filename' => 'image.jpg', 'source' => 'takeout']);
    $r2 = asset_ingest($u, "$d/b.jpg", ['filename' => 'image.jpg', 'source' => 'takeout']);
    tools_override(null);
    assert_eq('created', $r1['status']);
    assert_eq('created', $r2['status'], 'dosya zamanı aynı diye farklı fotoğraf yinelenen sayılmamalı');
});

// I2: çıkarılan dosya zip girdisinin boyut/CRC değeriyle doğrulanmalı
dbtest('test_extracted_file_verified_against_zip', function () {
    $d = temp_dir();
    file_put_contents("$d/x.bin", 'abcdef');
    import_verify_extracted("$d/x.bin", 6, crc32('abcdef'));
    assert_throws(RuntimeException::class, fn() => import_verify_extracted("$d/x.bin", 7, crc32('abcdef')), 'boyut');
    assert_throws(RuntimeException::class, fn() => import_verify_extracted("$d/x.bin", 6, crc32('xxxxxx')), 'crc');
});

// I3: adı aynı ama uzun ve alakasız video Live Photo sayılmamalı
dbtest('test_long_unrelated_video_not_live_pair', function () {
    $t = tools_detect(true);
    if (!$t['ffmpeg'] || !$t['ffprobe']) {
        skip('ffmpeg yok');
    }
    $tmp = temp_dir();
    $r = tool_run([$t['ffmpeg'], '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=64x48:rate=5', '-t', '9', '-pix_fmt', 'yuv420p', "$tmp/v.mp4"]);
    assert_eq(0, $r['code'], $r['err']);
    $dir = temp_dir('takeout');
    $y = 'Takeout/Google Photos/Photos from 2019/';
    tk_make_zip("$dir/t.zip", [
        $y . 'IMG_0001.JPG' => tk_jpeg_bytes(41),
        $y . 'IMG_0001.JPG.json' => tk_sidecar(1561982645, ['trashed' => false]),
        $y . 'IMG_0001.MOV' => (string) file_get_contents("$tmp/v.mp4"),
    ]);
    $u = rf_user();
    $id = import_create($u, $dir, false);
    tk_run_import($id);
    $vid = db_row("SELECT * FROM assets WHERE original_filename = 'IMG_0001.MOV'");
    assert_true($vid !== null);
    assert_eq(null, $vid['live_pair_id'], '9 sn\'lik video Live Photo değildir');
    assert_true($vid['taken_at'] !== gmdate('Y-m-d H:i:s', 1561982645), 'fotoğrafın sidecar tarihi ödünç alınmamalı');
    $ids = array_column(api_route('GET', '/v1/assets', user_find($u))['json']['assets'], 'id');
    assert_true(in_array($vid['id'], $ids, true), 'video zaman çizelgesinde görünür');
});

// I4: açılmış klasörde NFD adlar
dbtest('test_extracted_folder_nfd_names', function () {
    $dir = temp_dir('takeout');
    $sub = "$dir/Takeout/Google Photos/" . Normalizer::normalize('Gözde Düğün', Normalizer::FORM_D);
    mkdir($sub, 0777, true);
    $name = Normalizer::normalize('Gözde.jpg', Normalizer::FORM_D);
    file_put_contents("$sub/$name", tk_jpeg_bytes(51));
    file_put_contents("$sub/$name.json", tk_sidecar(1600000000));
    $u = rf_user();
    $id = import_create($u, $dir, false);
    tk_run_import($id);
    $items = db_all('SELECT result, detail FROM import_items WHERE import_id = ?', [$id]);
    assert_eq(1, count($items));
    assert_eq('imported', $items[0]['result'], (string) $items[0]['detail']);
    assert_eq(gmdate('Y-m-d H:i:s', 1600000000), db_val('SELECT taken_at FROM assets'));
    assert_eq('Gözde Düğün', db_val('SELECT title FROM albums'));
});

// I5a: GD bellek koruması
test('test_gd_memory_guard', function () {
    assert_true(media_gd_fits(4000, 3000, 5_000_000, 512 * 1024 * 1024, 10 * 1024 * 1024));
    assert_true(!media_gd_fits(8000, 7500, 30_000_000, 128 * 1024 * 1024, 20 * 1024 * 1024), '60 MP + 128M sığmaz');
    assert_true(media_gd_fits(8000, 7500, 30_000_000, -1, 0), 'sınırsız bellek');
});

// I5b: sürekli çöken iş sonsuza kadar yeniden kuyruğa alınmamalı
dbtest('test_stale_job_counts_attempts', function () {
    $id = job_enqueue('noop', []);
    db_exec("UPDATE jobs SET status='running', attempts=3, locked_at=? WHERE id=?", [gmdate('Y-m-d H:i:s', time() - 7200), $id]);
    assert_eq(null, job_claim('w'));
    assert_eq('failed', db_val('SELECT status FROM jobs WHERE id=?', [$id]));
});

// I5c: tarama belleği giriş sayısıyla birlikte şişmemeli
dbtest('test_import_scan_memory_bounded', function () {
    if (!function_exists('memory_reset_peak_usage')) {
        skip('PHP 8.2+ gerekli');
    }
    $dir = temp_dir('takeout');
    $z = new ZipArchive();
    $z->open("$dir/big.zip", ZipArchive::CREATE);
    for ($i = 0; $i < 30000; $i++) {
        $p = sprintf('Takeout/Google Photos/Photos from 2019/IMG_%05d_uzun_bir_dosya_adi_ornegi.jpg', $i);
        $z->addFromString($p, '');
        $z->addFromString($p . '.supplemental-metadata.json', '');
    }
    $z->close();
    $u = rf_user();
    $id = import_create($u, $dir, false);
    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    import_step($id, 0); // tarama
    $peak = memory_get_peak_usage() - $before;
    assert_true($peak < 24 * 1024 * 1024, 'tarama tepe belleği ' . round($peak / 1048576) . ' MB');
    assert_eq(30000, (int) db_val('SELECT files_total FROM imports WHERE id=?', [$id]));
});

// I6: desteklenmeyen dosyalar raporda satır almalı
dbtest('test_unsupported_files_reported', function () {
    $dir = temp_dir('takeout');
    $y = 'Takeout/Google Photos/Photos from 2019/';
    tk_make_zip("$dir/t.zip", [
        $y . 'a.jpg' => tk_jpeg_bytes(61),
        $y . 'raw.xyz' => 'bilinmeyen',
        $y . 'b.jfif' => tk_jpeg_bytes(62),
        'Takeout/archive_browser.html' => '<html>',
    ]);
    $u = rf_user();
    $id = import_create($u, $dir, false);
    tk_run_import($id);
    $r = import_report($id);
    assert_eq(1, $r['counts']['unsupported']);
    assert_true(str_ends_with($r['items']['unsupported'][0]['entry_path'], 'raw.xyz'));
    assert_eq(2, (int) db_val('SELECT COUNT(*) FROM assets'), 'jfif desteklenir');
});

// m4→: giriş sonrası açık yönlendirme
test('test_safe_next_rejects_external', function () {
    assert_eq('/app/', safe_next('/\\evil.com'));
    assert_eq('/app/', safe_next('//evil.com'));
    assert_eq('/app/', safe_next('https://evil.com'));
    assert_eq('/app/#/albums', safe_next('/app/#/albums'));
    assert_eq('/admin/users.php', safe_next('/admin/users.php'));
});

// m8→: eşzamanlı aynı dosya → yinelenen (500 değil)
dbtest('test_concurrent_same_sha_returns_duplicate', function () {
    $u = rf_user();
    $d = temp_dir();
    file_put_contents("$d/a.jpg", tk_jpeg_bytes(71));
    $other = null;
    $GLOBALS['__om_before_asset_insert'] = function () use ($u, $d, &$other) {
        unset($GLOBALS['__om_before_asset_insert']);
        copy("$d/a.jpg", "$d/b.jpg");
        $other = asset_ingest($u, "$d/b.jpg", ['filename' => 'a.jpg', 'source' => 'ios']);
    };
    $r = asset_ingest($u, "$d/a.jpg", ['filename' => 'a.jpg', 'source' => 'web']);
    assert_eq('created', $other['status']);
    assert_eq('duplicate', $r['status']);
    assert_eq($other['asset_id'], $r['asset_id']);
    assert_eq(1, (int) db_val('SELECT ref_count FROM blobs'));
});

// m2→: çalışan bir dilim duraklatmayı ezmemeli; meşgulse yeniden kuyruğa girmeli; kalıcı hata 'failed' yapmalı
dbtest('test_pause_during_step_is_kept', function () {
    $u = rf_user();
    $id = import_create($u, tk_standard_takeout(), false);
    $GLOBALS['__om_import_after_item'] = function () use ($id) {
        db_exec("UPDATE imports SET status = 'paused' WHERE id = ?", [$id]);
    };
    import_step($id, 30);
    unset($GLOBALS['__om_import_after_item']);
    assert_eq('paused', db_val('SELECT status FROM imports WHERE id=?', [$id]));
    assert_true((int) db_val('SELECT files_done FROM imports WHERE id=?', [$id]) < 10);
});

dbtest('test_import_job_requeues_when_busy', function () {
    $u = rf_user();
    $id = import_create($u, tk_standard_takeout(), false);
    $c = test_db_second_connection();
    $c->query("SELECT GET_LOCK('om_import_$id', 0)");
    import_job_step(['import_id' => $id]);
    $c->query("SELECT RELEASE_LOCK('om_import_$id')");
    assert_eq(1, (int) db_val("SELECT COUNT(*) FROM jobs WHERE type='import_step' AND run_after > ?", [now_utc()]));
});

dbtest('test_import_marked_failed_after_job_gives_up', function () {
    $u = rf_user();
    $id = import_create($u, temp_dir('bos'), true);
    $job = job_claim('w');
    db_exec('UPDATE jobs SET attempts = 3 WHERE id = ?', [$job['id']]);
    $job['attempts'] = 3;
    job_fail($job, 'kalıcı hata');
    assert_eq('failed', db_val('SELECT status FROM imports WHERE id=?', [$id]));
});

// m5→: zaman çizelgesi alt sorguları için indeksler
dbtest('test_live_and_edited_indexes_exist', function () {
    $keys = array_column(db_all('SHOW INDEX FROM assets'), 'Column_name');
    assert_true(in_array('live_pair_id', $keys, true));
    assert_true(in_array('edited_of_id', $keys, true));
    assert_eq(2, SCHEMA_VERSION);
});
