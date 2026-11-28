<?php
declare(strict_types=1);

dbtest('test_default_theme_is_sinema', function () {
    $u = user_find(user_create('ali@example.com', 'parola12345', 'Ali'));
    assert_eq('sinema', user_public($u)['theme']);
    assert_eq('sinema', api_route('GET', '/v1/me', $u)['json']['user']['theme']);
});

dbtest('test_patch_me_theme', function () {
    $u = user_find(user_create('ali@example.com', 'parola12345', 'Ali'));
    $r = api_route('PATCH', '/v1/me', $u, ['theme' => 'galeri'])['json']['user'];
    assert_eq('galeri', $r['theme']);
    assert_eq('galeri', db_val('SELECT ui_theme FROM users WHERE id = ?', [$u['id']]));
    $r = api_route('PATCH', '/v1/me', $u, ['theme' => 'yerel', 'name' => 'Ali Veli'])['json']['user'];
    assert_eq('yerel', $r['theme']);
    assert_eq('Ali Veli', $r['name']);
    $e = assert_throws(ApiError::class, fn() => api_route('PATCH', '/v1/me', $u, ['theme' => 'neon']));
    assert_eq(422, $e->status);
});
