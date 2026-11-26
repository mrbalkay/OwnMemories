<?php
declare(strict_types=1);

test('test_serve_range_206', function () {
    assert_eq(['status' => 200, 'start' => 0, 'end' => 999, 'length' => 1000, 'total' => 1000], serve_plan(1000, null));
    assert_eq(['status' => 206, 'start' => 100, 'end' => 199, 'length' => 100, 'total' => 1000], serve_plan(1000, 'bytes=100-199'));
    assert_eq(['status' => 206, 'start' => 900, 'end' => 999, 'length' => 100, 'total' => 1000], serve_plan(1000, 'bytes=-100'));
    assert_eq(['status' => 206, 'start' => 500, 'end' => 999, 'length' => 500, 'total' => 1000], serve_plan(1000, 'bytes=500-'));
    assert_eq(416, serve_plan(1000, 'bytes=2000-3000')['status']);
    assert_eq(200, serve_plan(1000, 'bytes=0-1,5-6')['status'], 'çoklu aralık desteklenmez → tamamı');
    $d = temp_dir();
    file_put_contents("$d/f.bin", str_repeat('0123456789', 100));
    ob_start();
    serve_stream("$d/f.bin", 100, 109);
    assert_eq('0123456789', ob_get_clean());
});
