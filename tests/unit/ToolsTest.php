<?php
declare(strict_types=1);

test('test_tool_run_captures_output', function () {
    $r = tool_run([PHP_BINARY, '-r', 'echo 42; fwrite(STDERR, "e");']);
    assert_eq(0, $r['code']);
    assert_eq('42', $r['out']);
    assert_eq('e', $r['err']);
});

test('test_tool_run_no_shell', function () {
    $r = tool_run([PHP_BINARY, '-r', 'echo $argv[1];', '; echo hacked']);
    assert_eq('; echo hacked', $r['out']);
});

test('test_tools_detect_shape', function () {
    $t = tools_detect(true);
    foreach (['exiftool', 'ffmpeg', 'ffprobe', 'vipsthumbnail', 'imagick', 'imagick_heic', 'gd', 'gd_webp', 'zip', 'exec'] as $k) {
        assert_true(array_key_exists($k, $t), "anahtar eksik: $k");
    }
    tools_override(['exiftool' => null, 'gd' => false]);
    assert_eq(null, tools_detect()['exiftool']);
    tools_override(null);
});
