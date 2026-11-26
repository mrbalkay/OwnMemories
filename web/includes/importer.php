<?php
/**
 * OwnMemories — Google Takeout içe aktarma yürütücüsü.
 *
 * Kaynak: bir klasördeki Takeout zip'leri ve/veya açılmış Takeout klasörleri.
 * Aşamalar: scanning (giriş listeleri → indeks) → importing (öğe öğe, dilimli) → linking
 * (Live Photo ve düzenlenmiş kopya bağlantıları) → done. Her medya dosyası import_items'ta bir satır
 * alır; aynı içe aktarma yeniden çalıştırılırsa kaydı olan öğeler atlanır (kaldığı yerden devam).
 */
declare(strict_types=1);

const IMPORT_DIR_SOURCE = '@klasor';

/** Yeni içe aktarma. $enqueue=false: CLI kendi döngüsüyle çalıştırır. */
function import_create(int $userId, string $dir, bool $enqueue = true): int
{
    $dir = rtrim($dir, '/');
    if (!is_dir($dir)) {
        throw new ValidationError('Klasör bulunamadı: ' . $dir, 'dir');
    }
    db_exec('INSERT INTO imports (user_id, status, source_dir, counts_json, created_at) VALUES (?, ?, ?, ?, ?)', [$userId, 'queued', $dir, json_encode(import_empty_counts()), now_utc()]);
    $id = (int) db()->lastInsertId();
    if ($enqueue) {
        job_enqueue('import_step', ['import_id' => $id], 6);
    }
    return $id;
}

function import_empty_counts(): array
{
    return ['imported' => 0, 'duplicate' => 0, 'no_metadata' => 0, 'skipped_trashed' => 0, 'failed' => 0, 'metadata_only' => 0, 'other' => 0];
}

function import_index_path(int $id): string
{
    return storage_path("imports/$id/index.json");
}

/** İş kuyruğu işleyicisi: bir dilim çalıştırır, bitmediyse kendini yeniden kuyruğa koyar. */
function import_job_step(array $p): string
{
    $id = (int) ($p['import_id'] ?? 0);
    $status = db_val('SELECT status FROM imports WHERE id = ?', [$id]);
    if ($status === null || in_array($status, ['done', 'failed', 'paused'], true)) {
        return 'skipped';
    }
    $budget = (int) max(1, min(45, job_time_left() - 3));
    $res = import_step($id, $budget);
    if ($res === 'more') {
        $paused = db_val('SELECT status FROM imports WHERE id = ?', [$id]) === 'paused';
        if (!$paused) {
            job_enqueue('import_step', ['import_id' => $id], 6, $res === 'busy' ? 30 : 0);
        }
    }
    return 'done';
}

/**
 * İçe aktarmayı en fazla $budgetSec saniye ilerletir (her çağrıda en az bir öğe).
 * @return string 'more' | 'done' | 'busy' (başka bir işlem kilitli tutuyor)
 */
function import_step(int $importId, int $budgetSec): string
{
    $lock = 'om_import_' . $importId;
    if ((int) db()->query("SELECT GET_LOCK('$lock', 0)")->fetchColumn() !== 1) {
        return 'busy';
    }
    $deadline = microtime(true) + $budgetSec;
    $state = new ImportState();
    try {
        $imp = db_row('SELECT * FROM imports WHERE id = ?', [$importId]);
        if ($imp === null) {
            return 'done';
        }
        if (in_array($imp['status'], ['done', 'failed'], true)) {
            return 'done';
        }
        if ($imp['status'] === 'paused') {
            return 'more';
        }
        if (in_array($imp['status'], ['queued', 'scanning'], true)) {
            db_exec("UPDATE imports SET status = 'scanning', started_at = COALESCE(started_at, ?) WHERE id = ?", [now_utc(), $importId]);
            import_scan($imp);
            db_exec("UPDATE imports SET status = 'importing' WHERE id = ?", [$importId]);
            if (microtime(true) >= $deadline) {
                return 'more';
            }
            $imp = db_row('SELECT * FROM imports WHERE id = ?', [$importId]);
        }
        if ($imp['status'] === 'importing') {
            $more = import_process($imp, $deadline, $state);
            if ($more) {
                return 'more';
            }
            db_exec("UPDATE imports SET status = 'linking' WHERE id = ?", [$importId]);
            $imp['status'] = 'linking';
        }
        if ($imp['status'] === 'linking') {
            import_link($imp);
            $counts = import_counts($importId);
            db_exec("UPDATE imports SET status = 'done', counts_json = ?, finished_at = ?, current_zip = NULL WHERE id = ?", [json_encode($counts), now_utc(), $importId]);
            audit((int) $imp['user_id'], 'import_done', "#$importId " . json_encode($counts));
        }
        return 'done';
    } catch (Throwable $e) {
        app_log('import', "#$importId: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        db_exec('UPDATE imports SET last_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 2000), $importId]);
        throw $e;
    } finally {
        $state->close();
        db()->query("SELECT RELEASE_LOCK('$lock')");
    }
}

/** Açık zip tutamakları ve geçici dosyalar (tek dilim boyunca). */
final class ImportState
{
    /** @var array<string, ZipArchive> */
    public array $zips = [];
    public ?array $index = null;
    public array $sidecarIdx = [];

    public function zip(string $file): ZipArchive
    {
        if (!isset($this->zips[$file])) {
            $z = new ZipArchive();
            $r = $z->open($file, ZipArchive::RDONLY);
            if ($r !== true) {
                throw new RuntimeException('Zip açılamadı (' . $r . '): ' . basename($file));
            }
            $this->zips[$file] = $z;
        }
        return $this->zips[$file];
    }

    public function close(): void
    {
        foreach ($this->zips as $z) {
            @$z->close();
        }
        $this->zips = [];
    }
}

/** Kaynakları listeler ve indeksi yazar. */
function import_scan(array $imp): void
{
    $dir = (string) $imp['source_dir'];
    $sources = [];
    $entries = []; // [sourceIdx, path, mtime]
    $zips = glob($dir . '/*.zip') ?: [];
    sort($zips, SORT_NATURAL);
    foreach ($zips as $zf) {
        $si = count($sources);
        $sources[] = ['type' => 'zip', 'name' => basename($zf)];
        $z = new ZipArchive();
        if ($z->open($zf, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Zip açılamadı: ' . basename($zf));
        }
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i, ZipArchive::FL_ENC_GUESS);
            if ($st === false || str_ends_with($st['name'], '/')) {
                continue;
            }
            $entries[] = [$si, tk_nfc($st['name']), (int) $st['mtime']];
        }
        $z->close();
    }
    // Açılmış klasörler (zip dışındaki dosyalar)
    $si = count($sources);
    $sources[] = ['type' => 'dir', 'name' => IMPORT_DIR_SOURCE];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || strtolower($f->getExtension()) === 'zip') {
            continue;
        }
        $rel = ltrim(substr($f->getPathname(), strlen($dir)), '/');
        $entries[] = [$si, tk_nfc($rel), (int) $f->getMTime()];
    }

    $byPath = [];
    foreach ($entries as $e) {
        $byPath[$e[1]][] = $e;
    }
    $c = takeout_classify(array_keys($byPath));
    $media = [];
    foreach ($c['media'] as $path => $info) {
        foreach ($byPath[$path] as $e) {
            $media[] = [$e[0], $path, $e[2]];
        }
    }
    $sidecars = [];
    foreach ($c['sidecars'] as $path => $info) {
        $e = $byPath[$path][0];
        $sidecars[$info['dir']][$info['name']] = [$e[0], $path];
    }
    $albumMeta = [];
    foreach ($c['album_meta'] as $d => $path) {
        $albumMeta[$d] = [$byPath[$path][0][0], $path];
    }
    $index = ['sources' => $sources, 'media' => $media, 'sidecars' => $sidecars, 'album_meta' => $albumMeta, 'other' => count($c['other'])];
    file_put_contents(import_index_path((int) $imp['id']), json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    $counts = import_empty_counts();
    $counts['other'] = count($c['other']);
    db_exec('UPDATE imports SET files_total = ?, current_entry = 0, counts_json = ? WHERE id = ?', [count($media), json_encode($counts), $imp['id']]);
}

function import_load_index(int $id, ImportState $state): array
{
    if ($state->index === null) {
        $j = json_decode((string) @file_get_contents(import_index_path($id)), true);
        if (!is_array($j)) {
            throw new RuntimeException('İçe aktarma indeksi okunamadı');
        }
        $state->index = $j;
    }
    return $state->index;
}

/** Kaynaktaki bir girdiyi okur (küçük dosyalar: sidecar, metadata.json). */
function import_read_entry(array $imp, array $index, int $si, string $path, ImportState $state): ?string
{
    $src = $index['sources'][$si];
    if ($src['type'] === 'zip') {
        $data = $state->zip($imp['source_dir'] . '/' . $src['name'])->getFromName($path);
        if ($data === false) {
            // Ad kodlaması farklı olabilir: NFC/NFD dene
            $alt = class_exists('Normalizer') ? Normalizer::normalize($path, Normalizer::FORM_D) : $path;
            $data = $state->zip($imp['source_dir'] . '/' . $src['name'])->getFromName((string) $alt);
        }
        return $data === false ? null : $data;
    }
    $f = $imp['source_dir'] . '/' . $path;
    return is_file($f) ? (string) file_get_contents($f) : null;
}

/** Medya girdisini geçici dosyaya akışla çıkarır; [yol, geçici mi]. */
function import_extract_media(array $imp, array $index, int $si, string $path, ImportState $state): array
{
    $src = $index['sources'][$si];
    if ($src['type'] === 'dir') {
        return [$imp['source_dir'] . '/' . $path, false];
    }
    $zip = $state->zip($imp['source_dir'] . '/' . $src['name']);
    $stream = $zip->getStream($path);
    if ($stream === false && class_exists('Normalizer')) {
        $stream = $zip->getStream((string) Normalizer::normalize($path, Normalizer::FORM_D));
    }
    if ($stream === false) {
        throw new RuntimeException('Zip girdisi okunamadı: ' . $path);
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $tmp = storage_path('tmp/imp' . $imp['id'] . '_' . bin2hex(random_bytes(6)) . ($ext !== '' ? '.' . $ext : ''));
    $out = fopen($tmp, 'wb');
    stream_copy_to_stream($stream, $out);
    fclose($out);
    fclose($stream);
    return [$tmp, true];
}

/** Öğeleri işler. Süre dolduysa true (devam edecek). */
function import_process(array $imp, float $deadline, ImportState $state): bool
{
    $id = (int) $imp['id'];
    $userId = (int) $imp['user_id'];
    $index = import_load_index($id, $state);
    $media = $index['media'];
    $total = count($media);
    $i = (int) $imp['current_entry'];
    $first = true;
    $albums = [];
    while ($i < $total) {
        if (!$first && microtime(true) >= $deadline) {
            break;
        }
        $first = false;
        [$si, $path, $mtime] = $media[$i];
        $srcName = $index['sources'][$si]['name'];
        $hash = sha1($srcName . "\0" . $path);
        if (db_val('SELECT 1 FROM import_items WHERE import_id = ? AND entry_hash = ?', [$id, $hash]) === null) {
            import_one($imp, $index, $state, (int) $si, (string) $path, (int) $mtime, $hash, $albums);
        }
        $i++;
        db_exec('UPDATE imports SET current_entry = ?, files_done = ?, current_zip = ? WHERE id = ?', [$i, $i, $srcName, $id]);
    }
    return $i < $total;
}

/** Tek bir medya dosyasını içe aktarır ve sonucunu yazar. */
function import_one(array $imp, array $index, ImportState $state, int $si, string $path, int $mtime, string $hash, array &$albums): void
{
    $id = (int) $imp['id'];
    $userId = (int) $imp['user_id'];
    $name = basename($path);
    $dir = dirname($path) === '.' ? '' : dirname($path);
    $result = 'failed';
    $assetId = null;
    $detail = null;
    $tmp = null;
    $isTemp = false;
    try {
        // Sidecar
        $dirSidecars = $index['sidecars'][$dir] ?? [];
        $sidecar = [];
        $sidecarName = null;
        if ($dirSidecars) {
            $state->sidecarIdx[$dir] ??= takeout_sidecar_index(array_keys($dirSidecars));
            $sidecarName = takeout_match_sidecar($name, $state->sidecarIdx[$dir]);
            if ($sidecarName !== null) {
                [$ssi, $spath] = $dirSidecars[$sidecarName];
                $json = import_read_entry($imp, $index, (int) $ssi, (string) $spath, $state);
                if ($json !== null) {
                    $sidecar = takeout_parse_sidecar($json);
                }
            }
        }
        if (!empty($sidecar['trashed'])) {
            $result = 'skipped_trashed';
            $detail = 'Google Fotoğraflar çöp kutusundaydı (trashed)';
        } else {
            [$tmp, $isTemp] = import_extract_media($imp, $index, $si, $path, $state);
            $kind = media_kind($tmp, $name);
            $probe = media_probe($tmp, (string) $kind, $name);
            $res = takeout_resolve($sidecar, $probe, $name, $mtime > 0 ? $mtime : null);
            $r = asset_ingest($userId, $tmp, $res + [
                'filename' => $name, 'source' => 'takeout', 'source_ref' => $path, 'probe' => $probe, 'move' => $isTemp,
            ]);
            if ($isTemp && $r['status'] === 'created') {
                $tmp = null; // taşındı
            }
            $assetId = $r['asset_id'];
            if ($r['status'] === 'created') {
                $result = $sidecarName !== null ? 'imported' : 'no_metadata';
                $srcLabel = ['exif' => 'fotoğraf bilgisinden (EXIF)', 'filename' => 'dosya adından', 'file' => 'dosya tarihinden (kesin değil)'][$res['date_source']] ?? $res['date_source'];
                $detail = $sidecarName !== null ? 'sidecar: ' . $sidecarName : 'Meta veri (.json) dosyası bulunamadı; tarih ' . $srcLabel . ' alındı';
            } else {
                $result = 'duplicate';
                $detail = $r['status'] === 'deleted_before' ? 'Daha önce kalıcı silinmiş' : 'Zaten kütüphanede';
            }
            if ($assetId !== null) {
                foreach ($sidecar['people'] ?? [] as $person) {
                    db_exec('INSERT IGNORE INTO asset_people (asset_id, name) VALUES (?, ?)', [$assetId, mb_substr($person, 0, 190)]);
                }
                import_add_to_album($imp, $index, $state, $dir, $assetId, $albums);
            }
        }
    } catch (Throwable $e) {
        $result = 'failed';
        $detail = get_class($e) . ': ' . $e->getMessage();
        app_log('import', "#$id $path: $detail");
    } finally {
        if ($isTemp && $tmp !== null) {
            @unlink($tmp);
        }
    }
    db_exec('INSERT IGNORE INTO import_items (import_id, zip_name, entry_path, entry_hash, result, asset_id, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $id, $index['sources'][$si]['name'], mb_substr($path, 0, 1024), $hash, $result, $assetId, $detail, now_utc(),
    ]);
}

/** Kova albümse albümü bulur/oluşturur ve asset'i ekler. */
function import_add_to_album(array $imp, array $index, ImportState $state, string $dir, string $assetId, array &$albums): void
{
    $userId = (int) $imp['user_id'];
    if (!array_key_exists($dir, $albums)) {
        $meta = null;
        if (isset($index['album_meta'][$dir])) {
            [$msi, $mpath] = $index['album_meta'][$dir];
            $meta = import_read_entry($imp, $index, (int) $msi, (string) $mpath, $state);
        }
        $bucket = takeout_bucket($dir);
        $title = $bucket === '' ? null : takeout_album_title($bucket, $meta);
        $albumId = null;
        if ($title !== null) {
            $albumId = db_val("SELECT id FROM albums WHERE user_id = ? AND source = 'takeout' AND source_ref = ?", [$userId, mb_substr($bucket, 0, 255)]);
            if ($albumId === null) {
                $albumId = uuid4();
                $desc = null;
                $j = $meta !== null ? json_decode($meta, true) : null;
                if (is_array($j) && is_string($j['description'] ?? null) && trim($j['description']) !== '') {
                    $desc = trim($j['description']);
                }
                $v = asset_bump_version($userId);
                db_exec('INSERT INTO albums (id, user_id, title, description, source, source_ref, created_at, updated_at, version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                    $albumId, $userId, mb_substr($title, 0, 255), $desc, 'takeout', mb_substr($bucket, 0, 255), now_utc(), now_utc(), $v,
                ]);
            }
        }
        $albums[$dir] = $albumId;
    }
    $albumId = $albums[$dir];
    if ($albumId === null) {
        return;
    }
    db_exec('INSERT IGNORE INTO album_assets (album_id, asset_id, added_by, sort_order, added_at) VALUES (?, ?, ?, 0, ?)', [$albumId, $assetId, $userId, now_utc()]);
    db_exec('UPDATE albums SET cover_asset_id = COALESCE(cover_asset_id, ?) WHERE id = ?', [$assetId, $albumId]);
}

/** Live Photo çiftleri ve düzenlenmiş kopyalar arasında bağlantı kurar. */
function import_link(array $imp): void
{
    $id = (int) $imp['id'];
    $userId = (int) $imp['user_id'];
    $map = [];
    foreach (db_all("SELECT entry_path, asset_id FROM import_items WHERE import_id = ? AND asset_id IS NOT NULL", [$id]) as $r) {
        $map[$r['entry_path']] ??= $r['asset_id'];
    }
    $paths = array_keys($map);
    foreach (takeout_live_pairs($paths) as $photo => $video) {
        $p = $map[$photo];
        $v = $map[$video];
        if ($p !== $v) {
            db_exec('UPDATE assets SET live_pair_id = ? WHERE id = ? AND user_id = ?', [$v, $p, $userId]);
            db_exec('UPDATE assets SET live_pair_id = ? WHERE id = ? AND user_id = ?', [$p, $v, $userId]);
        }
    }
    $byDir = [];
    foreach ($paths as $p) {
        $byDir[dirname($p)][] = $p;
    }
    foreach ($paths as $p) {
        $orig = takeout_edited_original($p, $byDir[dirname($p)] ?? []);
        if ($orig !== null && $map[$orig] !== $map[$p]) {
            db_exec('UPDATE assets SET edited_of_id = ? WHERE id = ? AND user_id = ?', [$map[$orig], $map[$p], $userId]);
        }
    }
    if ($paths) {
        asset_bump_version($userId);
        db_exec('UPDATE assets SET version = (SELECT sync_version FROM users WHERE id = ?) WHERE user_id = ? AND (live_pair_id IS NOT NULL OR edited_of_id IS NOT NULL) AND source = ?', [$userId, $userId, 'takeout']);
    }
    @unlink(import_index_path($id));
    @rmdir(dirname(import_index_path($id)));
}

function import_counts(int $importId): array
{
    $counts = import_empty_counts();
    $prev = json_decode((string) db_val('SELECT counts_json FROM imports WHERE id = ?', [$importId]), true);
    $counts['other'] = (int) ($prev['other'] ?? 0);
    foreach (db_all('SELECT result, COUNT(*) n FROM import_items WHERE import_id = ? GROUP BY result', [$importId]) as $r) {
        $counts[$r['result']] = (int) $r['n'];
    }
    return $counts;
}

/** Rapor: sayımlar ve sorunlu öğe listeleri (failed, no_metadata). */
function import_report(int $importId, int $limit = 500): array
{
    $imp = db_row('SELECT * FROM imports WHERE id = ?', [$importId]);
    if ($imp === null) {
        throw new ValidationError('İçe aktarma yok', 'id');
    }
    $counts = $imp['status'] === 'done' ? (json_decode((string) $imp['counts_json'], true) ?: import_counts($importId)) : import_counts($importId);
    $items = [];
    foreach (['failed', 'no_metadata', 'skipped_trashed'] as $res) {
        $items[$res] = db_all('SELECT zip_name, entry_path, asset_id, detail FROM import_items WHERE import_id = ? AND result = ? ORDER BY id LIMIT ' . (int) $limit, [$importId, $res]);
    }
    return ['import' => $imp, 'counts' => $counts, 'items' => $items];
}
