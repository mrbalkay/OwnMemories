<?php
declare(strict_types=1);

dbtest('test_create_and_verify_user', function () {
    $id = user_create('ali@example.com', 'parola12345', 'Ali');
    $u = user_verify_password('ali@example.com', 'parola12345');
    assert_eq($id, (int) $u['id']);
    assert_eq(0, (int) $u['is_admin']);
});

dbtest('test_duplicate_email_throws', function () {
    user_create('ali@example.com', 'parola12345', 'Ali');
    assert_throws(ValidationError::class, fn() => user_create('ALI@example.com', 'parola12345', 'Ali 2'));
});

dbtest('test_wrong_password_returns_null', function () {
    user_create('ali@example.com', 'parola12345', 'Ali');
    assert_eq(null, user_verify_password('ali@example.com', 'yanlis-parola'));
    assert_eq(null, user_verify_password('yok@example.com', 'parola12345'));
});

dbtest('test_short_password_rejected', function () {
    assert_throws(ValidationError::class, fn() => user_create('a@example.com', 'kisa', 'A'));
});

dbtest('test_token_roundtrip', function () {
    $id = user_create('ali@example.com', 'parola12345', 'Ali');
    $tok = api_token_issue($id, 'iPhone', 'ios');
    assert_eq(43, strlen($tok));
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tok;
    assert_eq($id, (int) api_user_from_bearer()['id']);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer yanlis';
    assert_eq(null, api_user_from_bearer());
    unset($_SERVER['HTTP_AUTHORIZATION']);
});

dbtest('test_email_case_insensitive', function () {
    user_create('Ayse@Example.com', 'parola12345', 'Ayşe');
    assert_true(user_verify_password('ayse@example.COM', 'parola12345') !== null);
});

dbtest('test_suspended_user_cannot_login', function () {
    $id = user_create('ali@example.com', 'parola12345', 'Ali');
    db_exec("UPDATE users SET status='suspended' WHERE id=?", [$id]);
    assert_eq(null, user_verify_password('ali@example.com', 'parola12345'));
});
