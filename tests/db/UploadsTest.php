<?php
declare(strict_types=1);

function up_bytes(): string
{
    $i = imagecreatetruecolor(120, 90);
    imagefilledrectangle($i, 0, 0, 60, 90, imagecolorallocate($i, 10, 200, 100));
    ob_start();
    imagejpeg($i, null, 95);
    return (string) ob_get_clean();
}

function up_stream(string $s)
{
    $h = fopen('php://memory', 'w+b');
    fwrite($h, $s);
    rewind($h);
    return $h;
}

dbtest('test_upload_chunks_and_complete', function () {
    $u = user_find(user_create('ali@example.com', 'parola12345', 'Ali'));
    $data = up_bytes();
    $start = api_route('POST', '/v1/uploads', $u, ['filename' => 'IMG_5.jpg', 'size' => strlen($data), 'sha1' => sha1($data), 'meta' => ['taken_at' => '2022-03-04T05:06:07Z', 'tz_offset' => 180, 'source_ref' => 'PH-1', 'is_favorite' => true]])['json'];
    assert_eq(52428800, $start['chunk_size']);
    assert_eq(0, $start['received']);
    $half = intdiv(strlen($data), 2);
    $r1 = api_route('PUT', '/v1/uploads/' . $start['id'], $u, [], ['offset' => '0'], up_stream(substr($data, 0, $half)))['json'];
    assert_eq($half, $r1['received']);
    $r2 = api_route('PUT', '/v1/uploads/' . $start['id'], $u, [], ['offset' => (string) $half], up_stream(substr($data, $half)))['json'];
    assert_eq(strlen($data), $r2['received']);
    $done = api_route('POST', '/v1/uploads/' . $start['id'] . '/complete', $u)['json'];
    assert_eq('created', $done['status']);
    assert_eq('2022-03-04T05:06:07Z', $done['asset']['taken_at']);
    assert_eq(true, $done['asset']['is_favorite']);
    assert_eq('ios', db_val('SELECT source FROM assets'), 'Bearer (ios) yükleme kaynağı');
    assert_eq('PH-1', db_val('SELECT source_ref FROM assets'));
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM uploads'));
    assert_eq([], glob(storage_root() . '/uploads/*') ?: []);
});

dbtest('test_upload_offset_mismatch_409', function () {
    $u = user_find(user_create('ali@example.com', 'parola12345', 'Ali'));
    $data = up_bytes();
    $id = api_route('POST', '/v1/uploads', $u, ['filename' => 'a.jpg', 'size' => strlen($data)])['json']['id'];
    $e = assert_throws(ApiError::class, fn() => api_route('PUT', "/v1/uploads/$id", $u, [], ['offset' => '10'], up_stream('xx')));
    assert_eq(409, $e->status);
    assert_eq('offset_mismatch', $e->errorCode);
    assert_eq(0, $e->extra['received']);
});

dbtest('test_upload_sha1_mismatch_422', function () {
    $u = user_find(user_create('ali@example.com', 'parola12345', 'Ali'));
    $data = up_bytes();
    $id = api_route('POST', '/v1/uploads', $u, ['filename' => 'a.jpg', 'size' => strlen($data), 'sha1' => str_repeat('0', 40)])['json']['id'];
    api_route('PUT', "/v1/uploads/$id", $u, [], ['offset' => '0'], up_stream($data));
    $e = assert_throws(ApiError::class, fn() => api_route('POST', "/v1/uploads/$id/complete", $u));
    assert_eq(422, $e->status);
    assert_eq('checksum_mismatch', $e->errorCode);
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM assets'));
});

dbtest('test_upload_resume_get_received', function () {
    $u = user_find(user_create('ali@example.com', 'parola12345', 'Ali'));
    $data = up_bytes();
    $id = api_route('POST', '/v1/uploads', $u, ['filename' => 'a.jpg', 'size' => strlen($data)])['json']['id'];
    api_route('PUT', "/v1/uploads/$id", $u, [], ['offset' => '0'], up_stream(substr($data, 0, 100)));
    assert_eq(100, api_route('GET', "/v1/uploads/$id", $u)['json']['received']);
    $e = assert_throws(ApiError::class, fn() => api_route('POST', "/v1/uploads/$id/complete", $u));
    assert_eq(409, $e->status);
    $o = user_find(user_create('b@example.com', 'parola12345', 'B'));
    assert_eq(404, assert_throws(ApiError::class, fn() => api_route('GET', "/v1/uploads/$id", $o))->status);
});

dbtest('test_upload_rejects_unsupported_and_oversize', function () {
    $u = user_find(user_create('ali@example.com', 'parola12345', 'Ali'));
    assert_eq(422, assert_throws(ApiError::class, fn() => api_route('POST', '/v1/uploads', $u, ['filename' => 'virus.exe', 'size' => 10]))->status);
    $id = api_route('POST', '/v1/uploads', $u, ['filename' => 'a.jpg', 'size' => 3])['json']['id'];
    assert_eq(422, assert_throws(ApiError::class, fn() => api_route('PUT', "/v1/uploads/$id", $u, [], ['offset' => '0'], up_stream('fazla-bayt')))->status);
});

dbtest('test_upload_duplicate_returns_existing', function () {
    $u = user_find(user_create('ali@example.com', 'parola12345', 'Ali'));
    $data = up_bytes();
    foreach ([1, 2] as $n) {
        $id = api_route('POST', '/v1/uploads', $u, ['filename' => 'a.jpg', 'size' => strlen($data)])['json']['id'];
        api_route('PUT', "/v1/uploads/$id", $u, [], ['offset' => '0'], up_stream($data));
        $r = api_route('POST', "/v1/uploads/$id/complete", $u)['json'];
    }
    assert_eq('duplicate', $r['status']);
    assert_true($r['asset'] !== null);
    assert_eq(1, (int) db_val('SELECT COUNT(*) FROM assets'));
});
