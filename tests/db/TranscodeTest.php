<?php
declare(strict_types=1);

function tc_video(string $path, string $codec): void
{
    $t = tools_detect(true);
    $args = [$t['ffmpeg'], '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=10', '-f', 'lavfi', '-i', 'sine=frequency=440', '-t', '2',
        '-c:v', $codec, '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest'];
    if ($codec === 'libx265') {
        array_push($args, '-tag:v', 'hvc1');
    }
    $args[] = $path;
    $r = tool_run($args, 120);
    assert_eq(0, $r['code'], $r['err']);
}

function tc_codec(string $path): string
{
    $r = tool_run([tools_detect()['ffprobe'], '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=codec_name', '-of', 'csv=p=0', $path]);
    return trim($r['out']);
}

dbtest('test_hevc_video_gets_web_mp4', function () {
    $t = tools_detect(true);
    if (!$t['ffmpeg'] || !$t['ffprobe']) {
        skip('ffmpeg yok');
    }
    $u = user_find(user_create('ali@example.com', 'parola12345', 'Ali'));
    $d = temp_dir();
    tc_video("$d/IMG_1.MOV", 'libx265');
    $r = asset_ingest((int) $u['id'], "$d/IMG_1.MOV", ['filename' => 'IMG_1.MOV', 'source' => 'ios', 'taken_at_utc' => '2026-01-01 10:00:00']);
    jobs_run_for(120, 'test');
    $web = derived_find_video($r['asset_id']);
    assert_true($web !== null, 'web mp4 üretilmeli');
    assert_eq('h264', tc_codec($web));
    $json = api_route('GET', '/v1/assets/' . $r['asset_id'], $u)['json']['asset'];
    assert_eq(true, $json['web_video']);
    $f = api_route('GET', '/v1/assets/' . $r['asset_id'] . '/video', $u);
    assert_eq($web, $f['file']['path']);
    assert_eq('video/mp4', $f['file']['mime']);
    assert_true(str_ends_with(api_route('GET', '/v1/assets/' . $r['asset_id'] . '/original', $u)['file']['path'], '.mov'), 'orijinal korunur');
});

dbtest('test_h264_mp4_needs_no_transcode', function () {
    $t = tools_detect(true);
    if (!$t['ffmpeg'] || !$t['ffprobe']) {
        skip('ffmpeg yok');
    }
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $d = temp_dir();
    tc_video("$d/v.mp4", 'libx264');
    $r = asset_ingest($u, "$d/v.mp4", ['filename' => 'v.mp4', 'source' => 'web', 'taken_at_utc' => '2026-01-01 10:00:00']);
    jobs_run_for(60, 'test');
    assert_eq(null, derived_find_video($r['asset_id']));
    assert_eq(0, (int) db_val("SELECT COUNT(*) FROM jobs WHERE type = 'transcode' AND status <> 'skipped'"));
});

dbtest('test_transcode_without_ffmpeg_skipped', function () {
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $d = temp_dir();
    file_put_contents("$d/x.mov", 'sahte');
    $r = asset_ingest($u, "$d/x.mov", ['filename' => 'x.mov', 'source' => 'web', 'taken_at_utc' => '2026-01-01 10:00:00']);
    tools_override(['ffmpeg' => null, 'ffprobe' => null]);
    assert_eq('skipped', job_transcode(['asset_id' => $r['asset_id']]));
    tools_override(null);
});
