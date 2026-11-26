<?php
declare(strict_types=1);

dbtest('test_album_crud_and_share_rules', function () {
    $u = user_create('ali@example.com', 'parola12345', 'Ali');
    $o = user_create('es@example.com', 'parola12345', 'Eş');
    $id = album_create($u, 'Yaz', 'açıklama');
    assert_true(album_can($u, $id, 'own'));
    assert_true(!album_can($o, $id, 'view'));
    album_share($id, $u, 'es@example.com', 'viewer');
    assert_true(album_can($o, $id, 'view'));
    assert_true(!album_can($o, $id, 'contribute'));
    album_share($id, $u, 'es@example.com', 'contributor');
    assert_true(album_can($o, $id, 'contribute'));
    assert_eq(1, (int) db_val('SELECT COUNT(*) FROM album_members'));
    assert_throws(ApiError::class, fn() => album_share($id, $u, 'ali@example.com', 'viewer'), 'kendisiyle paylaşamaz');
    assert_throws(ApiError::class, fn() => album_share($id, $u, 'yok@example.com', 'viewer'));
    album_unshare($id, $o, $o); // üye kendisi ayrılabilir
    assert_true(!album_can($o, $id, 'view'));
    album_update($id, $u, ['title' => 'Yaz 2024']);
    assert_eq('Yaz 2024', db_val('SELECT title FROM albums WHERE id=?', [$id]));
    album_delete($id, $u);
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM albums'));
});
