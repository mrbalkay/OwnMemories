<?php
declare(strict_types=1);

dbtest('test_enqueue_claim_complete', function () {
    $id = job_enqueue('noop', ['x' => 1]);
    $j = job_claim('w1');
    assert_eq($id, (int) $j['id']);
    assert_eq(['x' => 1], $j['payload']);
    assert_eq('running', db_val('SELECT status FROM jobs WHERE id=?', [$id]));
    assert_eq(null, job_claim('w2'), 'başka iş yok');
    job_complete($id);
    assert_eq('done', db_val('SELECT status FROM jobs WHERE id=?', [$id]));
});

dbtest('test_claim_respects_priority_and_delay', function () {
    $low = job_enqueue('noop', [], 9);
    $high = job_enqueue('noop', [], 1);
    job_enqueue('noop', [], 0, 3600);
    assert_eq($high, (int) job_claim('w')['id']);
    assert_eq($low, (int) job_claim('w')['id']);
    assert_eq(null, job_claim('w'), 'ertelenmiş iş alınmaz');
});

dbtest('test_two_workers_never_claim_same_job', function () {
    $a = job_enqueue('noop', []);
    $b = job_enqueue('noop', []);
    $j1 = job_claim('wA');
    $j2 = job_claim('wB');
    assert_true($j1 !== null && $j2 !== null);
    assert_true((int) $j1['id'] !== (int) $j2['id']);
    assert_eq('wA', db_val('SELECT locked_by FROM jobs WHERE id=?', [$j1['id']]));
    assert_eq('wB', db_val('SELECT locked_by FROM jobs WHERE id=?', [$j2['id']]));
});

dbtest('test_retry_then_failed_after_3', function () {
    $GLOBALS['__om_job_handlers_extra'] = ['boom' => function () { throw new RuntimeException('patladı'); }];
    $id = job_enqueue('boom', []);
    for ($i = 1; $i <= 3; $i++) {
        db_exec('UPDATE jobs SET run_after = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 1), $id]);
        jobs_run_for(5, 'w');
        $st = db_row('SELECT status, attempts, last_error FROM jobs WHERE id=?', [$id]);
        assert_eq($i, (int) $st['attempts']);
        assert_eq($i < 3 ? 'queued' : 'failed', $st['status'], "deneme $i");
    }
    assert_true(str_contains((string) $st['last_error'], 'patladı'));
    unset($GLOBALS['__om_job_handlers_extra']);
});

dbtest('test_stale_running_requeued', function () {
    $id = job_enqueue('noop', []);
    job_claim('olu-isci');
    db_exec('UPDATE jobs SET locked_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 3600), $id]);
    $j = job_claim('yeni');
    assert_eq($id, (int) $j['id']);
});

dbtest('test_render_handler_sets_thumb_state', function () {
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $d = temp_dir();
    $i = imagecreatetruecolor(1200, 800);
    imagejpeg($i, "$d/a.jpg");
    $r = asset_ingest($u, "$d/a.jpg", ['filename' => 'a.jpg', 'source' => 'web']);
    assert_eq(1, jobs_run_for(10, 'w'));
    assert_eq('ready', db_val('SELECT thumb_state FROM assets WHERE id=?', [$r['asset_id']]));
    $thumb = derived_find($r['asset_id'], 'thumb');
    $prev = derived_find($r['asset_id'], 'preview');
    assert_true($thumb !== null && $prev !== null);
    assert_eq(THUMB_EDGE, max(getimagesize($thumb)[0], getimagesize($thumb)[1]));
    assert_eq(1200, max(getimagesize($prev)[0], getimagesize($prev)[1]), 'küçük görüntü büyütülmez');
});

dbtest('test_render_placeholder_job_skipped', function () {
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $d = temp_dir();
    file_put_contents("$d/x.heic", str_repeat("\1", 128));
    $r = asset_ingest($u, "$d/x.heic", ['filename' => 'x.heic', 'source' => 'web', 'taken_at_utc' => '2020-01-01 00:00:00']);
    tools_override(['vipsthumbnail' => null, 'imagick' => false, 'imagick_heic' => false]);
    jobs_run_for(10, 'w');
    tools_override(null);
    assert_eq('placeholder', db_val('SELECT thumb_state FROM assets WHERE id=?', [$r['asset_id']]));
    assert_eq('skipped', db_val("SELECT status FROM jobs WHERE type='render'"));
});
