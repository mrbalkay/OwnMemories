<?php
declare(strict_types=1);

function make_jpeg(string $path, int $w, int $h): string
{
    $i = imagecreatetruecolor($w, $h);
    imagefilledrectangle($i, 0, 0, intdiv($w, 2), $h, imagecolorallocate($i, 220, 120, 160));
    imagejpeg($i, $path, 85);
    return $path;
}

function image_size(string $path): array
{
    $s = getimagesize($path);
    return [$s[0], $s[1]];
}

test('test_kind_by_extension_and_magic', function () {
    $d = temp_dir();
    assert_eq('photo', media_kind(make_jpeg("$d/a.jpg", 10, 10)));
    assert_eq('photo', media_kind(make_jpeg("$d/noext", 10, 10)), 'uzantısız JPEG içerikten tanınır');
    assert_eq('photo', media_kind("$d/x", 'IMG_1.HEIC'));
    assert_eq('video', media_kind("$d/x", 'clip.MOV'));
    assert_eq('video', media_kind("$d/x", 'v.mp4'));
    file_put_contents("$d/n.json", '{}');
    assert_eq(null, media_kind("$d/n.json"));
});

$probeCase = function (bool $withExiftool) {
    return function () use ($withExiftool) {
        if ($withExiftool && !tools_detect(true)['exiftool']) {
            skip('exiftool yok');
        }
        if (!$withExiftool) {
            tools_override(['exiftool' => null]);
        }
        $exp = json_decode((string) file_get_contents(fixture('media/exif_sample.json')), true);
        $p = media_probe(fixture('media/exif_sample.jpg'), 'photo');
        tools_override(null);
        assert_eq($exp['taken_local'], $p['taken_local']);
        assert_eq($exp['tz_offset'], $p['tz_offset']);
        assert_true(abs($exp['lat'] - $p['lat']) < 0.0001, 'enlem');
        assert_true(abs($exp['lng'] - $p['lng']) < 0.0001, 'boylam');
        assert_eq($exp['make'], $p['make']);
        assert_eq($exp['model'], $p['model']);
        assert_eq($exp['orientation'], $p['orientation']);
        assert_eq($exp['width'], $p['width'], 'yön uygulanmış genişlik');
        assert_eq($exp['height'], $p['height']);
        assert_eq('image/jpeg', $p['mime']);
    };
};
test('test_probe_reads_exif_date_and_gps', $probeCase(true));
test('test_probe_reads_exif_without_exiftool', $probeCase(false));

test('test_probe_without_exif_returns_nulls', function () {
    $d = temp_dir();
    $p = media_probe(make_jpeg("$d/plain.jpg", 30, 20), 'photo');
    assert_eq(null, $p['taken_local']);
    assert_eq(null, $p['lat']);
    assert_eq(30, $p['width']);
    assert_eq(20, $p['height']);
});

test('test_render_photo_thumbnail_dimensions', function () {
    $d = temp_dir();
    $src = make_jpeg("$d/big.jpg", 4000, 3000);
    foreach ([['vipsthumbnail' => null, 'imagick' => false], ['vipsthumbnail' => null], []] as $i => $ov) {
        tools_override($ov);
        $out = "$d/t$i.jpg";
        assert_eq('ready', media_render($src, 'photo', $out, 360, 'jpeg'), "araç seti $i");
        assert_eq([360, 270], image_size($out), "araç seti $i boyut");
    }
    tools_override(null);
});

test('test_render_respects_orientation', function () {
    $d = temp_dir();
    foreach ([['vipsthumbnail' => null, 'imagick' => false], []] as $i => $ov) {
        tools_override($ov);
        $out = "$d/o$i.jpg";
        media_render(fixture('media/exif_sample.jpg'), 'photo', $out, 320, 'jpeg');
        assert_eq([240, 320], image_size($out), "yön 6 dikey olmalı (set $i)");
    }
    tools_override(null);
});

test('test_render_webp_or_jpeg', function () {
    $d = temp_dir();
    $out = "$d/w.webp";
    assert_eq('ready', media_render(make_jpeg("$d/s.jpg", 800, 600), 'photo', $out, 200, 'webp'));
    assert_true(is_file($out) && filesize($out) > 0);
});

test('test_thumbnail_unsupported_returns_placeholder', function () {
    $d = temp_dir();
    file_put_contents("$d/fake.heic", str_repeat("\0", 64));
    tools_override(['vipsthumbnail' => null, 'imagick' => false, 'imagick_heic' => false, 'exiftool' => null]);
    assert_eq('placeholder', media_render("$d/fake.heic", 'photo', "$d/out.jpg", 360, 'jpeg'));
    assert_true(!is_file("$d/out.jpg"));
    tools_override(['ffmpeg' => null]);
    assert_eq('placeholder', media_render("$d/v.mp4", 'video', "$d/v.jpg", 360, 'jpeg'));
    tools_override(null);
});

test('test_video_poster_when_ffmpeg', function () {
    $t = tools_detect(true);
    if (!$t['ffmpeg'] || !$t['ffprobe']) {
        skip('ffmpeg yok');
    }
    $d = temp_dir();
    $r = tool_run([$t['ffmpeg'], '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=10', '-t', '2', '-pix_fmt', 'yuv420p', "$d/tiny.mp4"]);
    assert_eq(0, $r['code'], $r['err']);
    $p = media_probe("$d/tiny.mp4", 'video');
    assert_eq(640, $p['width']);
    assert_eq(360, $p['height']);
    assert_true($p['duration_ms'] >= 1900 && $p['duration_ms'] <= 2100, 'süre');
    assert_eq('ready', media_render("$d/tiny.mp4", 'video', "$d/poster.jpg", 320, 'jpeg'));
    assert_eq([320, 180], image_size("$d/poster.jpg"));
});

test('test_capture_time_from_exif_with_offset', function () {
    $p = media_probe_empty();
    $p['taken_local'] = '2019-07-01 15:00:00';
    $p['tz_offset'] = 180;
    assert_eq(['taken_at_utc' => '2019-07-01 12:00:00', 'tz_offset' => 180, 'date_source' => 'exif'], capture_time_resolve(null, $p, 'x.jpg', null));
});

test('test_capture_time_exif_without_offset_uses_istanbul', function () {
    $p = media_probe_empty();
    $p['taken_local'] = '2010-01-15 12:00:00'; // 2010 kışı: Türkiye UTC+2
    $r = capture_time_resolve(null, $p, 'x.jpg', null);
    assert_eq('2010-01-15 10:00:00', $r['taken_at_utc']);
    assert_eq(120, $r['tz_offset']);
});

test('test_capture_time_video_utc_only', function () {
    $p = media_probe_empty();
    $p['taken_utc'] = '2022-08-10 09:00:00';
    $r = capture_time_resolve(null, $p, 'v.mov', null);
    assert_eq('2022-08-10 09:00:00', $r['taken_at_utc']);
    assert_eq(180, $r['tz_offset'], 'İstanbul 2022 = +3');
    assert_eq('exif', $r['date_source']);
});
