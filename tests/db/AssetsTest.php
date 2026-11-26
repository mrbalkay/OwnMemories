<?php
declare(strict_types=1);

function mk_user(string $email = 'ali@example.com'): int
{
    return user_create($email, 'parola12345', 'Kullanıcı');
}

/** EXIF tarihli küçük bir JPEG üretir (renk farklı → farklı bayt). */
function mk_photo(string $dir, string $name, string $exifDate = '2020:05:01 10:00:00', int $seed = 1, int $w = 64, int $h = 48): string
{
    $p = "$dir/$name";
    $i = imagecreatetruecolor($w, $h);
    imagefilledrectangle($i, 0, 0, $w, $h, imagecolorallocate($i, ($seed * 37) % 255, ($seed * 91) % 255, ($seed * 53) % 255));
    imagejpeg($i, $p, 90);
    $bin = tools_detect(true)['exiftool'];
    if ($bin) {
        tool_run([$bin, '-q', '-overwrite_original', '-DateTimeOriginal=' . $exifDate, '-OffsetTimeOriginal=+03:00', $p]);
    }
    return $p;
}

dbtest('test_ingest_creates_asset_and_blob', function () {
    $u = mk_user();
    $d = temp_dir();
    $src = mk_photo($d, 'IMG_1.jpg');
    $sha = sha1_file($src);
    $r = asset_ingest($u, $src, ['filename' => 'IMG_1.jpg', 'source' => 'web']);
    assert_eq('created', $r['status']);
    $a = db_row('SELECT * FROM assets WHERE id = ?', [$r['asset_id']]);
    assert_eq($sha, $a['blob_sha1']);
    assert_eq('photo', $a['type']);
    assert_eq('IMG_1.jpg', $a['original_filename']);
    assert_eq(64, (int) $a['width']);
    if (tools_detect()['exiftool']) {
        assert_eq('2020-05-01 07:00:00', $a['taken_at'], 'EXIF +03:00 → UTC');
        assert_eq(180, (int) $a['taken_tz_offset']);
        assert_eq('exif', $a['date_source']);
    }
    assert_true(is_file(blob_path($sha)), 'blob dosyası');
    assert_true(is_file($src), 'move=false kaynağı silmez');
    assert_eq(1, (int) db_val('SELECT ref_count FROM blobs WHERE sha1 = ?', [$sha]));
    assert_eq(1, (int) db_val("SELECT COUNT(*) FROM jobs WHERE type = 'render'"));
    assert_true((int) $a['version'] > 0, 'version atanır');
});

dbtest('test_ingest_same_file_twice_is_duplicate', function () {
    $u = mk_user();
    $d = temp_dir();
    $src = mk_photo($d, 'a.jpg');
    $r1 = asset_ingest($u, $src, ['filename' => 'a.jpg', 'source' => 'web']);
    $r2 = asset_ingest($u, $src, ['filename' => 'kopya.jpg', 'source' => 'ios']);
    assert_eq('duplicate', $r2['status']);
    assert_eq($r1['asset_id'], $r2['asset_id']);
    assert_eq(1, (int) db_val('SELECT COUNT(*) FROM assets'));
});

dbtest('test_ingest_secondary_match_duplicate', function () {
    $u = mk_user();
    $d = temp_dir();
    $a = mk_photo($d, 'a.jpg', '2020:05:01 10:00:00', 1);
    $b = mk_photo(temp_dir(), 'a.jpg', '2020:05:01 10:00:00', 2);
    assert_true(sha1_file($a) !== sha1_file($b));
    $ts = '2020-05-01 07:00:00';
    $r1 = asset_ingest($u, $a, ['filename' => 'IMG_9.JPG', 'source' => 'takeout', 'taken_at_utc' => $ts]);
    $r2 = asset_ingest($u, $b, ['filename' => 'img_9.jpg', 'source' => 'ios', 'taken_at_utc' => $ts, 'source_ref' => 'LOCAL-ID-1']);
    assert_eq('duplicate', $r2['status']);
    assert_eq($r1['asset_id'], $r2['asset_id']);
    // Farklı boyut → yinelenen değil
    $c = mk_photo(temp_dir(), 'x.jpg', '2020:05:01 10:00:00', 3, 80, 48);
    assert_eq('created', asset_ingest($u, $c, ['filename' => 'IMG_9.JPG', 'source' => 'ios', 'taken_at_utc' => $ts])['status']);
});

dbtest('test_same_file_two_users_one_blob', function () {
    $u1 = mk_user('a@example.com');
    $u2 = mk_user('b@example.com');
    $src = mk_photo(temp_dir(), 'a.jpg');
    $r1 = asset_ingest($u1, $src, ['filename' => 'a.jpg', 'source' => 'web']);
    $r2 = asset_ingest($u2, $src, ['filename' => 'a.jpg', 'source' => 'web']);
    assert_eq('created', $r2['status']);
    assert_true($r1['asset_id'] !== $r2['asset_id']);
    assert_eq(2, (int) db_val('SELECT ref_count FROM blobs'));
    asset_purge($u1, [$r1['asset_id']]);
    assert_eq(1, (int) db_val('SELECT ref_count FROM blobs'));
    assert_true(is_file(blob_path(sha1_file($src))), 'diğer kullanıcı için blob kalır');
});

dbtest('test_purge_records_deleted_checksum_and_blocks_reingest', function () {
    $u = mk_user();
    $src = mk_photo(temp_dir(), 'a.jpg');
    $sha = sha1_file($src);
    $r = asset_ingest($u, $src, ['filename' => 'a.jpg', 'source' => 'web']);
    asset_trash($u, [$r['asset_id']]);
    assert_eq(1, asset_purge($u, [$r['asset_id']]));
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM assets'));
    assert_true(!is_file(blob_path($sha)), 'son referans: blob silinir');
    assert_eq(1, (int) db_val('SELECT COUNT(*) FROM deleted_checksums WHERE user_id=? AND sha1=?', [$u, $sha]));
    assert_eq('deleted_before', asset_ingest($u, $src, ['filename' => 'a.jpg', 'source' => 'ios'])['status']);
});

dbtest('test_trash_restore_roundtrip', function () {
    $u = mk_user();
    $r = asset_ingest($u, mk_photo(temp_dir(), 'a.jpg'), ['filename' => 'a.jpg', 'source' => 'web']);
    $v0 = (int) db_val('SELECT version FROM assets');
    assert_eq(1, asset_trash($u, [$r['asset_id']]));
    assert_true(db_val('SELECT trashed_at FROM assets') !== null);
    assert_true((int) db_val('SELECT version FROM assets') > $v0, 'çöp version artırır');
    assert_eq(1, asset_restore($u, [$r['asset_id']]));
    assert_eq(null, db_val('SELECT trashed_at FROM assets'));
    $other = mk_user('b@example.com');
    assert_eq(0, asset_trash($other, [$r['asset_id']]), 'başkasının asset\'i');
});

dbtest('test_trash_purge_expired_respects_30_days', function () {
    $u = mk_user();
    $a = asset_ingest($u, mk_photo(temp_dir(), 'a.jpg', '2020:01:01 00:00:00', 1), ['filename' => 'a.jpg', 'source' => 'web']);
    $b = asset_ingest($u, mk_photo(temp_dir(), 'b.jpg', '2020:01:02 00:00:00', 2), ['filename' => 'b.jpg', 'source' => 'web']);
    db_exec('UPDATE assets SET trashed_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 31 * 86400), $a['asset_id']]);
    db_exec('UPDATE assets SET trashed_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 29 * 86400), $b['asset_id']]);
    assert_eq(1, trash_purge_expired());
    assert_eq([$b['asset_id']], db()->query('SELECT id FROM assets')->fetchAll(PDO::FETCH_COLUMN));
});

dbtest('test_used_bytes_tracking', function () {
    $u = mk_user();
    $src = mk_photo(temp_dir(), 'a.jpg');
    $r = asset_ingest($u, $src, ['filename' => 'a.jpg', 'source' => 'web']);
    assert_eq(filesize($src), (int) db_val('SELECT used_bytes FROM users WHERE id=?', [$u]));
    asset_purge($u, [$r['asset_id']]);
    assert_eq(0, (int) db_val('SELECT used_bytes FROM users WHERE id=?', [$u]));
});

dbtest('test_quota_exceeded_rejected', function () {
    $u = mk_user();
    db_exec('UPDATE users SET quota_bytes = 10 WHERE id = ?', [$u]);
    assert_throws(ValidationError::class, fn() => asset_ingest($u, mk_photo(temp_dir(), 'a.jpg'), ['filename' => 'a.jpg', 'source' => 'web']));
    assert_eq(0, (int) db_val('SELECT COUNT(*) FROM blobs'));
});

dbtest('test_ingest_move_removes_source', function () {
    $u = mk_user();
    $src = mk_photo(temp_dir(), 'a.jpg');
    asset_ingest($u, $src, ['filename' => 'a.jpg', 'source' => 'takeout', 'move' => true]);
    assert_true(!is_file($src));
});

dbtest('test_asset_to_json_shape', function () {
    $u = mk_user();
    $r = asset_ingest($u, mk_photo(temp_dir(), 'a.jpg'), ['filename' => 'a.jpg', 'source' => 'web', 'taken_at_utc' => '2021-02-03 04:05:06', 'tz_offset' => 180, 'lat' => 38.5, 'lng' => 27.25, 'is_favorite' => true]);
    $j = asset_to_json(db_row('SELECT * FROM assets WHERE id=?', [$r['asset_id']]));
    assert_eq('2021-02-03T04:05:06Z', $j['taken_at']);
    assert_eq('2021-02-03T07:05:06+03:00', $j['taken_local']);
    assert_eq(38.5, $j['latitude']);
    assert_eq(true, $j['is_favorite']);
    assert_eq('photo', $j['type']);
});
