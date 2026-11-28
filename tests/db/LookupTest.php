<?php
declare(strict_types=1);

dbtest('test_assets_lookup_keeps_order_and_scopes_to_user', function () {
    $u = api_user();
    $other = api_user('veli@example.com');
    $a = api_asset($u, '2024-05-01 10:00:00', 1);
    $b = api_asset($u, '2024-05-02 10:00:00', 2);
    $c = api_asset($u, '2024-05-03 10:00:00', 3);
    $x = api_asset($other, '2024-05-04 10:00:00', 4);
    call('POST', '/v1/assets/trash', $u, ['ids' => [$c]]);
    $r = call('POST', '/v1/assets/lookup', $u, ['ids' => [$b, $x, $a, $c, $b, 'nope']])['json'];
    assert_eq([$b, $a], array_column($r['assets'], 'id'));
    $e = call_err('POST', '/v1/assets/lookup', $u, ['ids' => array_fill(0, 501, $a)]);
    assert_eq(422, $e->status);
    assert_eq(401, call_err('POST', '/v1/assets/lookup', null, ['ids' => [$a]])->status);
});
