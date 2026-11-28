<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib_takeout.php';

function tk_user(): int
{
    return user_create('ali@example.com', 'parola12345', 'Ali');
}

function tk_result(int $importId, string $pathSuffix): array
{
    foreach (db_all('SELECT * FROM import_items WHERE import_id = ?', [$importId]) as $r) {
        if (str_ends_with($r['entry_path'], $pathSuffix)) {
            return $r;
        }
    }
    throw new AssertionFailed("öğe yok: $pathSuffix");
}

dbtest('test_import_counts_and_results', function () {
    $u = tk_user();
    $id = import_create($u, tk_standard_takeout(), false);
    tk_run_import($id);
    $imp = db_row('SELECT * FROM imports WHERE id = ?', [$id]);
    assert_eq('done', $imp['status']);
    assert_eq(10, (int) $imp['files_total']);
    assert_eq(10, (int) $imp['files_done']);
    $c = json_decode($imp['counts_json'], true);
    assert_eq(7, $c['imported'], 'imported');
    assert_eq(1, $c['duplicate'], 'duplicate');
    assert_eq(1, $c['skipped_trashed'], 'trashed');
    assert_eq(1, $c['no_metadata'], 'no_metadata');
    assert_eq(0, $c['failed'], 'failed');
    assert_eq(1, $c['other'], 'html');
    assert_eq(8, (int) db_val('SELECT COUNT(*) FROM assets WHERE user_id = ?', [$u]));
    assert_eq(10, (int) db_val('SELECT COUNT(*) FROM import_items WHERE import_id = ?', [$id]), 'her medya bir satır');

    $a1 = db_row('SELECT * FROM assets WHERE id = ?', [tk_result($id, 'Photos from 2019/IMG_1.jpg')['asset_id']]);
    assert_eq('2019-07-01 12:04:05', $a1['taken_at']);
    assert_eq('takeout', $a1['date_source']);
    assert_eq(1, (int) $a1['is_favorite']);
    assert_eq('38.420000', (string) $a1['latitude']);
    assert_eq('takeout', $a1['source']);
    assert_eq(['Ayşe'], db()->query("SELECT p.name FROM asset_person ap JOIN people p ON p.id = ap.person_id WHERE ap.asset_id = '{$a1['id']}' AND ap.source = 'takeout' AND p.user_id = $u")->fetchAll(PDO::FETCH_COLUMN));
    $a3 = db_row('SELECT * FROM assets WHERE id = ?', [tk_result($id, 'IMG_3.jpg')['asset_id']]);
    assert_eq('Üçüncü', $a3['description']);
    assert_eq('no_metadata', tk_result($id, 'nometa.jpg')['result']);
    assert_true(tk_result($id, 'nometa.jpg')['asset_id'] !== null, 'meta verisiz dosya yine içeride');
    assert_eq('skipped_trashed', tk_result($id, 'IMG_TRASH.jpg')['result']);
    assert_eq(null, tk_result($id, 'IMG_TRASH.jpg')['asset_id']);
    assert_eq(0, count(glob(storage_root() . '/tmp/*') ?: []), 'geçici dosyalar temizlenir');
});

dbtest('test_import_same_file_in_two_albums', function () {
    $u = tk_user();
    $id = import_create($u, tk_standard_takeout(), false);
    tk_run_import($id);
    $assetId = tk_result($id, 'Photos from 2019/IMG_1.jpg')['asset_id'];
    $dup = tk_result($id, 'Tatil 2019/IMG_1.jpg');
    assert_eq('duplicate', $dup['result']);
    assert_eq($assetId, $dup['asset_id']);
    $albums = db_all('SELECT * FROM albums WHERE user_id = ?', [$u]);
    assert_eq(1, count($albums), 'yıl klasörü albüm değil');
    assert_eq('Tatil 2019', $albums[0]['title']);
    assert_eq('takeout', $albums[0]['source']);
    assert_eq([$assetId], db()->query("SELECT asset_id FROM album_assets WHERE album_id = '{$albums[0]['id']}'")->fetchAll(PDO::FETCH_COLUMN));
    assert_eq($assetId, $albums[0]['cover_asset_id']);
});

dbtest('test_import_sidecar_in_other_zip', function () {
    $u = tk_user();
    $id = import_create($u, tk_standard_takeout(), false);
    tk_run_import($id);
    $r = tk_result($id, 'IMG_2.jpg');
    assert_eq('imported', $r['result']);
    $a = db_row('SELECT * FROM assets WHERE id = ?', [$r['asset_id']]);
    assert_eq(gmdate('Y-m-d H:i:s', 1561985000), $a['taken_at']);
    assert_eq(1, (int) $a['is_archived']);
});

dbtest('test_import_skips_trashed_with_record', function () {
    $u = tk_user();
    $id = import_create($u, tk_standard_takeout(), false);
    tk_run_import($id);
    $r = tk_result($id, 'IMG_TRASH.jpg');
    assert_eq('skipped_trashed', $r['result']);
    assert_true(str_contains((string) $r['detail'], 'trash') || str_contains((string) $r['detail'], 'çöp'));
});

dbtest('test_import_live_and_edited_links', function () {
    $u = tk_user();
    $id = import_create($u, tk_standard_takeout(), false);
    tk_run_import($id);
    $photo = tk_result($id, 'IMG_L.JPG')['asset_id'];
    $video = tk_result($id, 'IMG_L.MP4')['asset_id'];
    assert_eq($photo, db_val('SELECT live_pair_id FROM assets WHERE id = ?', [$video]));
    assert_eq($video, db_val('SELECT live_pair_id FROM assets WHERE id = ?', [$photo]));
    assert_eq(gmdate('Y-m-d H:i:s', 1561987000), db_val('SELECT taken_at FROM assets WHERE id = ?', [$video]), 'video fotoğrafın sidecarını kullanır');
    $orig = tk_result($id, 'IMG_E.jpg')['asset_id'];
    $edited = tk_result($id, 'IMG_E-edited.jpg')['asset_id'];
    assert_eq($orig, db_val('SELECT edited_of_id FROM assets WHERE id = ?', [$edited]));
    assert_eq('imported', tk_result($id, 'IMG_E-edited.jpg')['result']);
});

dbtest('test_import_resume_after_interrupt', function () {
    $u = tk_user();
    $id = import_create($u, tk_standard_takeout(), false);
    // Adım adım (her çağrıda en az bir öğe), ortada ilerleme imleci kaybolmuş gibi geri sarılır.
    for ($i = 0; $i < 5; $i++) {
        import_step($id, 0);
    }
    db_exec('UPDATE imports SET current_entry = 0, files_done = 0 WHERE id = ?', [$id]);
    tk_run_import($id, 0);
    $c = json_decode((string) db_val('SELECT counts_json FROM imports WHERE id = ?', [$id]), true);
    assert_eq(7, $c['imported']);
    assert_eq(1, $c['duplicate']);
    assert_eq(8, (int) db_val('SELECT COUNT(*) FROM assets'));
    assert_eq(10, (int) db_val('SELECT COUNT(*) FROM import_items WHERE import_id = ?', [$id]));
});

dbtest('test_import_rerun_same_dir_all_duplicates', function () {
    $u = tk_user();
    $dir = tk_standard_takeout();
    tk_run_import(import_create($u, $dir, false));
    $id2 = import_create($u, $dir, false);
    tk_run_import($id2);
    $c = json_decode((string) db_val('SELECT counts_json FROM imports WHERE id = ?', [$id2]), true);
    assert_eq(0, $c['imported']);
    assert_eq(0, $c['no_metadata']);
    assert_eq(9, $c['duplicate']);
    assert_eq(1, $c['skipped_trashed']);
    assert_eq(8, (int) db_val('SELECT COUNT(*) FROM assets'));
});

dbtest('test_import_extracted_folder_source', function () {
    $u = tk_user();
    $dir = temp_dir('takeout');
    @mkdir("$dir/Takeout/Google Photos/Photos from 2020", 0777, true);
    file_put_contents("$dir/Takeout/Google Photos/Photos from 2020/A.jpg", tk_jpeg_bytes(20));
    file_put_contents("$dir/Takeout/Google Photos/Photos from 2020/A.jpg.json", tk_sidecar(1600000000));
    $id = import_create($u, $dir, false);
    tk_run_import($id);
    assert_eq('imported', tk_result($id, 'A.jpg')['result']);
    assert_true(is_file("$dir/Takeout/Google Photos/Photos from 2020/A.jpg"), 'açılmış klasördeki dosya silinmez');
});

dbtest('test_import_job_handler_requeues_until_done', function () {
    $u = tk_user();
    $id = import_create($u, tk_standard_takeout());
    for ($i = 0; $i < 50 && db_val('SELECT status FROM imports WHERE id = ?', [$id]) !== 'done'; $i++) {
        jobs_run_for(20, 'test');
    }
    assert_eq('done', db_val('SELECT status FROM imports WHERE id = ?', [$id]));
});

dbtest('test_import_report', function () {
    $u = tk_user();
    $id = import_create($u, tk_standard_takeout(), false);
    tk_run_import($id);
    $r = import_report($id);
    assert_eq(7, $r['counts']['imported']);
    assert_eq(1, count($r['items']['no_metadata']));
    assert_true(str_ends_with($r['items']['no_metadata'][0]['entry_path'], 'nometa.jpg'));
});
