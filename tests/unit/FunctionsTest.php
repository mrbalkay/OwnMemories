<?php
declare(strict_types=1);

test('test_uuid4_format', function () {
    for ($i = 0; $i < 20; $i++) {
        assert_true((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', uuid4()));
    }
});

test('test_e_escapes', function () {
    assert_eq('&lt;a &quot;x&quot;&gt;', e('<a "x">'));
    assert_eq('', e(null));
});

test('test_t_fallback', function () {
    assert_eq('yok.boyle_bir_anahtar', t('yok.boyle_bir_anahtar'));
    assert_eq('Merhaba Ali', i18n_format('Merhaba {name}', ['name' => 'Ali']));
});

test('test_config_dot', function () {
    $old = config_all();
    config_all(['a' => ['b' => 1]]);
    assert_eq(1, config('a.b'));
    assert_eq('x', config('a.c', 'x'));
    config_all($old);
});
