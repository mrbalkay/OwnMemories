<?php
declare(strict_types=1);

function dv_user(): array
{
    return user_find(user_create('ali@example.com', 'parola12345', 'Ali', true));
}

function dv_png(int $seed): string
{
    $i = imagecreatetruecolor(30 + $seed, 20);
    imagefilledrectangle($i, 0, 0, 5, 5, imagecolorallocate($i, $seed % 256, 10, 10));
    ob_start();
    imagejpeg($i, null, 90);
    return (string) ob_get_clean();
}

/** Tek parçada yükler ve tamamlar. */
function dv_upload(array $u, string $name, string $data, array $meta): array
{
    $s = fopen('php://memory', 'w+b');
    fwrite($s, $data);
    rewind($s);
    $id = api_route('POST', '/v1/uploads', $u, ['filename' => $name, 'size' => strlen($data), 'meta' => $meta])['json']['id'];
    api_route('PUT', "/v1/uploads/$id", $u, [], ['offset' => '0'], $s);
    return api_route('POST', "/v1/uploads/$id/complete", $u)['json'];
}

dbtest('test_device_report_upserts', function () {
    $u = dv_user();
    $b = ['device_id' => 'ABC-123', 'name' => 'Mehmet iPhone', 'model' => 'iPhone15,2', 'os_version' => '18.1', 'app_version' => '1.0 (101)',
        'pending' => 12, 'uploaded_total' => 340, 'last_backup_at' => '2026-11-27T10:00:00Z', 'last_error' => null];
    $r = api_route('POST', '/v1/devices/report', $u, $b)['json'];
    assert_eq(true, $r['ok']);
    api_route('POST', '/v1/devices/report', $u, ['pending' => 0, 'last_error' => 'Sunucuya ulaşılamıyor'] + $b);
    $rows = db_all('SELECT * FROM devices');
    assert_eq(1, count($rows));
    assert_eq(0, (int) $rows[0]['pending']);
    assert_eq('2026-11-27 10:00:00', $rows[0]['last_backup_at']);
    assert_eq('Sunucuya ulaşılamıyor', $rows[0]['last_error']);
    assert_eq((int) $u['id'], (int) $rows[0]['user_id']);
});

dbtest('test_device_report_validates', function () {
    $u = dv_user();
    $e = assert_throws(ApiError::class, fn() => api_route('POST', '/v1/devices/report', $u, ['name' => 'x']));
    assert_eq(422, $e->status);
});

dbtest('test_upload_links_live_pair', function () {
    $u = dv_user();
    $p = dv_upload($u, 'IMG_1.HEIC.jpg', dv_png(1), ['source_ref' => 'L1/P', 'taken_at' => '2026-01-01T10:00:00Z']);
    $v = dv_upload($u, 'IMG_1.MOV', 'video-baytlari-1', ['source_ref' => 'L1/P#live', 'live_pair_source_ref' => 'L1/P', 'taken_at' => '2026-01-01T10:00:00Z']);
    assert_eq($v['asset']['id'], db_val('SELECT live_pair_id FROM assets WHERE id=?', [$p['asset']['id']]));
    assert_eq($p['asset']['id'], db_val('SELECT live_pair_id FROM assets WHERE id=?', [$v['asset']['id']]));
});

dbtest('test_upload_links_edited_and_trashes_old_edit', function () {
    $u = dv_user();
    $o = dv_upload($u, 'IMG_2.jpg', dv_png(2), ['source_ref' => 'L2/P', 'taken_at' => '2026-01-02T10:00:00Z']);
    $e1 = dv_upload($u, 'IMG_2.jpg', dv_png(3), ['source_ref' => 'L2/P#edited', 'edited_of_source_ref' => 'L2/P', 'taken_at' => '2026-01-02T10:00:01Z']);
    assert_eq($o['asset']['id'], db_val('SELECT edited_of_id FROM assets WHERE id=?', [$e1['asset']['id']]));
    $e2 = dv_upload($u, 'IMG_2.jpg', dv_png(4), ['source_ref' => 'L2/P#edited', 'edited_of_source_ref' => 'L2/P', 'taken_at' => '2026-01-02T10:00:02Z']);
    assert_eq($o['asset']['id'], db_val('SELECT edited_of_id FROM assets WHERE id=?', [$e2['asset']['id']]));
    assert_true(db_val('SELECT trashed_at FROM assets WHERE id=?', [$e1['asset']['id']]) !== null, 'eski düzenleme çöpe');
    assert_eq(null, db_val('SELECT trashed_at FROM assets WHERE id=?', [$o['asset']['id']]));
});

dbtest('test_admin_overview_lists_devices', function () {
    $u = dv_user();
    api_route('POST', '/v1/devices/report', $u, ['device_id' => 'D1', 'name' => 'iPhone', 'pending' => 3]);
    $ov = admin_overview();
    assert_eq(1, count($ov['devices']));
    assert_eq('iPhone', $ov['devices'][0]['name']);
    assert_eq('Ali', $ov['devices'][0]['user_name']);
});
