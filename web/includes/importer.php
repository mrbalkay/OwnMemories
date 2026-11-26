<?php
/**
 * OwnMemories — Google Takeout içe aktarma yürütücüsü.
 *
 * Kaynak: bir klasördeki Takeout zip'leri ve/veya açılmış Takeout klasörleri.
 * Aşamalar: scanning (giriş listeleri → import_entries tablosu, akışla) → importing (öğe öğe, dilimli)
 * → linking (Live Photo ve düzenlenmiş kopya bağlantıları) → done.
 * Her medya dosyası import_items'ta bir satır alır; desteklenmeyen dosyalar da 'unsupported' satırı alır.
 * Aynı içe aktarma yeniden çalıştırılırsa kaydı olan öğeler atlanır (kaldığı yerden devam).
 *
 * Yol kuralı: G/Ç için dosya adı kaynaktaki haliyle (ham) saklanır; eşleştirme ve raporlar için NFC kullanılır
 * (macOS'ta açılıp kopyalanan klasörlerde adlar NFD olabilir).
 */
declare(strict_types=1);

const IMPORT_DIR_SOURCE = '@klasor';
const LIVE_MAX_MS = 6000;      // Live Photo videosu en fazla bu kadar sürer
const LIVE_MAX_GAP_SEC = 5;    // fotoğraf ile video arasındaki en büyük zaman farkı

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
    return ['imported' => 0, 'duplicate' => 0, 'no_metadata' => 0, 'skipped_trashed' => 0, 'failed' => 0, 'metadata_only' => 0, 'unsupported' => 0, 'other' => 0];
}

/** İş kuyruğu işleyicisi: bir dilim çalıştırır, bitmediyse (veya kilit meşgulse) kendini yeniden kuyruğa koyar. */
function import_job_step(array $p): string
{
    $id = (int) ($p['import_id'] ?? 0);
    $status = db_val('SELECT status FROM imports WHERE id = ?', [$id]);
    if ($status === null || in_array($status, ['done', 'failed', 'paused'], true)) {
        return 'skipped';
    }
    $budget = (int) max(1, min(45, job_time_left() - 3));
    $res = import_step($id, $budget);
    if ($res === 'more' || $res === 'busy') {
        if (db_val('SELECT status FROM imports WHERE id = ?', [$id]) !== 'paused') {
            job_enqueue('import_step', ['import_id' => $id], 6, $res === 'busy' ? 30 : 0);
        }
    }
    return 'done';
}

function import_status(int $id): ?string
{
    $s = db_val('SELECT status FROM imports WHERE id = ?', [$id]);
    return $s === null ? null : (string) $s;
}

/** Durumu yalnız duraklatılmamışsa değiştirir. */
function import_set_status(int $id, string $status, array $extra = []): bool
{
    $set = 'status = ?';
    $args = [$status];
    foreach ($extra as $col => $val) {
        $set .= ", $col = ?";
        $args[] = $val;
    }
    $args[] = $id;
    return db_exec("UPDATE imports SET $set WHERE id = ? AND status NOT IN ('paused','failed')", $args) > 0;
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
        if ($imp === null || in_array($imp['status'], ['done', 'failed'], true)) {
            return 'done';
        }
        if ($imp['status'] === 'paused') {
            return 'more';
        }
        if (in_array($imp['status'], ['queued', 'scanning'], true)) {
            db_exec("UPDATE imports SET status = 'scanning', started_at = COALESCE(started_at, ?) WHERE id = ?", [now_utc(), $importId]);
            import_scan($imp);
            if (!import_set_status($importId, 'importing')) {
                return 'more';
            }
            if (microtime(true) >= $deadline) {
                return 'more';
            }
            $imp = db_row('SELECT * FROM imports WHERE id = ?', [$importId]);
        }
        if ($imp['status'] === 'importing') {
            if (import_process($imp, $deadline, $state)) {
                return 'more';
            }
            if (!import_set_status($importId, 'linking')) {
                return 'more';
            }
            $imp['status'] = 'linking';
        }
        if ($imp['status'] === 'linking') {
            import_link($imp);
            $counts = import_counts($importId);
            if (import_set_status($importId, 'done', ['counts_json' => json_encode($counts), 'finished_at' => now_utc(), 'current_zip' => null])) {
                db_exec('DELETE FROM import_entries WHERE import_id = ?', [$importId]);
                audit((int) $imp['user_id'], 'import_done', "#$importId " . json_encode($counts));
                return 'done';
            }
            return 'more';
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

/** Açık zip tutamakları ve klasör başına sidecar indeksleri (tek dilim boyunca). */
final class ImportState
{
    /** @var array<string, ZipArchive> */
    public array $zips = [];
    public ?array $sources = null;
    /** @var array<string, array{index: array, files: array}> */
    public array $sidecars = [];

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

/** Taranan girdileri toplu ekler. */
final class EntryWriter
{
    private array $rows = [];

    public function __construct(private int $importId)
    {
    }

    public function add(string $kind, int $si, string $rawPath, string $dirKey, string $nameKey, int $mtime): void
    {
        $this->rows[] = [$this->importId, $kind, $si, $rawPath, $dirKey, $nameKey, $mtime];
        if (count($this->rows) >= 500) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (!$this->rows) {
            return;
        }
        $sql = 'INSERT INTO import_entries (import_id, kind, source_idx, path, dir_key, name_key, mtime) VALUES ' . implode(',', array_fill(0, count($this->rows), '(?,?,?,?,?,?,?)'));
        db_exec($sql, array_merge(...$this->rows));
        $this->rows = [];
    }
}

/** Kaynakları akışla listeler; girdiler import_entries'e, desteklenmeyenler import_items'a yazılır. */
function import_scan(array $imp): void
{
    $id = (int) $imp['id'];
    $dir = (string) $imp['source_dir'];
    db_exec('DELETE FROM import_entries WHERE import_id = ?', [$id]);
    $w = new EntryWriter($id);
    $sources = [];
    $ignored = 0;
    $handle = function (int $si, string $raw, int $mtime) use ($w, $id, &$sources, &$ignored): void {
        $nfc = tk_nfc($raw);
        $c = takeout_classify_one($nfc);
        $dirKey = $c['dir'];
        switch ($c['type']) {
            case 'media':
            case 'sidecar':
            case 'album_meta':
                $w->add($c['type'], $si, $raw, mb_substr($dirKey, 0, 768), mb_substr($c['name'], 0, 255), max(0, $mtime));
                break;
            case 'unsupported':
                db_exec('INSERT IGNORE INTO import_items (import_id, zip_name, entry_path, entry_hash, result, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                    $id, $sources[$si]['name'], mb_substr($nfc, 0, 1024), sha1($sources[$si]['name'] . "\0" . $nfc), 'unsupported', 'Desteklenmeyen dosya türü; içe aktarılmadı', now_utc(),
                ]);
                break;
            default:
                $ignored++;
        }
    };

    $zips = glob($dir . '/*.zip') ?: [];
    sort($zips, SORT_NATURAL);
    foreach ($zips as $zf) {
        $si = count($sources);
        $sources[] = ['type' => 'zip', 'name' => basename($zf)];
        $z = new ZipArchive();
        if ($z->open($zf, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Zip açılamadı (bozuk veya eksik indirilmiş olabilir): ' . basename($zf));
        }
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i, ZipArchive::FL_ENC_GUESS);
            if ($st === false || str_ends_with($st['name'], '/')) {
                continue;
            }
            $handle($si, $st['name'], (int) $st['mtime']);
        }
        $z->close();
    }
    $si = count($sources);
    $sources[] = ['type' => 'dir', 'name' => IMPORT_DIR_SOURCE];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || strtolower($f->getExtension()) === 'zip') {
            continue;
        }
        $handle($si, ltrim(substr($f->getPathname(), strlen($dir)), '/'), (int) $f->getMTime());
    }
    $w->flush();

    $total = (int) db_val("SELECT COUNT(*) FROM import_entries WHERE import_id = ? AND kind = 'media'", [$id]);
    $counts = import_empty_counts();
    $counts['other'] = $ignored;
    db_exec('UPDATE imports SET files_total = ?, files_done = 0, current_entry = 0, counts_json = ?, sources_json = ? WHERE id = ?', [
        $total, json_encode($counts), json_encode($sources, JSON_UNESCAPED_UNICODE), $id,
    ]);
}

function import_sources(array $imp, ImportState $state): array
{
    if ($state->sources === null) {
        $state->sources = json_decode((string) ($imp['sources_json'] ?? db_val('SELECT sources_json FROM imports WHERE id = ?', [$imp['id']])), true) ?: [];
    }
    return $state->sources;
}

/** Kaynaktaki küçük bir girdiyi okur (sidecar, metadata.json). */
function import_read_entry(array $imp, int $si, string $rawPath, ImportState $state): ?string
{
    $src = import_sources($imp, $state)[$si] ?? null;
    if ($src === null) {
        return null;
    }
    if ($src['type'] === 'zip') {
        $data = $state->zip($imp['source_dir'] . '/' . $src['name'])->getFromName($rawPath);
        return $data === false ? null : $data;
    }
    $f = $imp['source_dir'] . '/' . $rawPath;
    return is_file($f) ? (string) file_get_contents($f) : null;
}

/** Çıkarılan dosyayı zip girdisinin boyutu ve CRC32 değeriyle doğrular; tutmazsa istisna. */
function import_verify_extracted(string $file, int $expectedSize, int $expectedCrc): void
{
    clearstatcache(true, $file);
    $size = (int) @filesize($file);
    if ($size !== $expectedSize) {
        throw new RuntimeException("Çıkarılan dosya eksik: $size / $expectedSize bayt (disk dolu veya zip bozuk olabilir)");
    }
    $crc = hexdec((string) hash_file('crc32b', $file));
    if ($crc !== ($expectedCrc & 0xFFFFFFFF)) {
        throw new RuntimeException('Çıkarılan dosyanın CRC değeri tutmuyor (zip bozuk olabilir)');
    }
}

/** Medya girdisini geçici dosyaya akışla çıkarır ve doğrular; [yol, geçici mi]. */
function import_extract_media(array $imp, int $si, string $rawPath, ImportState $state): array
{
    $src = import_sources($imp, $state)[$si];
    if ($src['type'] === 'dir') {
        $p = $imp['source_dir'] . '/' . $rawPath;
        if (!is_file($p)) {
            throw new RuntimeException('Dosya bulunamadı: ' . $rawPath);
        }
        return [$p, false];
    }
    $zip = $state->zip($imp['source_dir'] . '/' . $src['name']);
    $stat = $zip->statName($rawPath);
    $stream = $zip->getStream($rawPath);
    if ($stat === false || $stream === false) {
        throw new RuntimeException('Zip girdisi okunamadı: ' . $rawPath);
    }
    $ext = strtolower(pathinfo($rawPath, PATHINFO_EXTENSION));
    $tmp = storage_path('tmp/imp' . $imp['id'] . '_' . bin2hex(random_bytes(6)) . ($ext !== '' ? '.' . $ext : ''));
    $out = fopen($tmp, 'wb');
    if ($out === false) {
        fclose($stream);
        throw new RuntimeException('Geçici dosya oluşturulamadı (depo yazılabilir mi?)');
    }
    $copied = stream_copy_to_stream($stream, $out);
    $closed = fclose($out);
    fclose($stream);
    try {
        if ($copied === false || !$closed) {
            throw new RuntimeException('Zip girdisi kopyalanamadı (disk dolu olabilir)');
        }
        import_verify_extracted($tmp, (int) $stat['size'], (int) $stat['crc']);
    } catch (Throwable $e) {
        @unlink($tmp);
        throw $e;
    }
    return [$tmp, true];
}

/** Bir klasörün sidecar'ları: ['index' => takeout_sidecar_index, 'files' => [ad => [si, ham yol]]]. */
function import_dir_sidecars(int $importId, string $dirKey, ImportState $state): array
{
    if (!isset($state->sidecars[$dirKey])) {
        if (count($state->sidecars) > 200) {
            $state->sidecars = [];
        }
        $files = [];
        foreach (db_all("SELECT name_key, source_idx, path FROM import_entries WHERE import_id = ? AND kind = 'sidecar' AND dir_key = ?", [$importId, $dirKey]) as $r) {
            $files[$r['name_key']] ??= [(int) $r['source_idx'], $r['path']];
        }
        $state->sidecars[$dirKey] = ['index' => takeout_sidecar_index(array_keys($files)), 'files' => $files];
    }
    return $state->sidecars[$dirKey];
}

/** Öğeleri işler. Süre dolduysa veya duraklatıldıysa true (devam edecek). */
function import_process(array $imp, float $deadline, ImportState $state): bool
{
    $id = (int) $imp['id'];
    $last = (int) $imp['current_entry'];
    $done = (int) $imp['files_done'];
    $first = true;
    $albums = [];
    while (true) {
        $batch = db_all("SELECT id, source_idx, path, mtime FROM import_entries WHERE import_id = ? AND kind = 'media' AND id > ? ORDER BY id LIMIT 200", [$id, $last]);
        if (!$batch) {
            return false;
        }
        foreach ($batch as $e) {
            if (!$first && microtime(true) >= $deadline) {
                return true;
            }
            $first = false;
            $sources = import_sources($imp, $state);
            $srcName = $sources[(int) $e['source_idx']]['name'] ?? '?';
            $nfc = tk_nfc((string) $e['path']);
            $hash = sha1($srcName . "\0" . $nfc);
            if (db_val('SELECT 1 FROM import_items WHERE import_id = ? AND entry_hash = ?', [$id, $hash]) === null) {
                import_one($imp, $state, (int) $e['source_idx'], (string) $e['path'], (int) $e['mtime'], $hash, $albums);
            }
            $last = (int) $e['id'];
            $done = min($done + 1, (int) $imp['files_total']);
            db_exec('UPDATE imports SET current_entry = ?, files_done = ?, current_zip = ? WHERE id = ?', [$last, $done, $srcName, $id]);
            if (isset($GLOBALS['__om_import_after_item'])) {
                ($GLOBALS['__om_import_after_item'])(); // yalnız testler
            }
            if (import_status($id) === 'paused') {
                return true;
            }
        }
    }
}

/** Tek bir medya dosyasını içe aktarır ve sonucunu yazar. */
function import_one(array $imp, ImportState $state, int $si, string $rawPath, int $mtime, string $hash, array &$albums): void
{
    $id = (int) $imp['id'];
    $userId = (int) $imp['user_id'];
    $nfc = tk_nfc($rawPath);
    $name = basename($nfc);
    $dirKey = dirname($nfc) === '.' ? '' : dirname($nfc);
    $result = 'failed';
    $assetId = null;
    $detail = null;
    $tmp = null;
    $isTemp = false;
    $srcName = import_sources($imp, $state)[$si]['name'] ?? '?';
    try {
        // Sidecar
        $dirSc = import_dir_sidecars($id, $dirKey, $state);
        $sidecar = [];
        $match = ['name' => null, 'via' => null];
        if ($dirSc['files']) {
            $match = takeout_match_sidecar_info($name, $dirSc['index']);
            if ($match['name'] !== null) {
                [$ssi, $spath] = $dirSc['files'][$match['name']];
                $json = import_read_entry($imp, (int) $ssi, (string) $spath, $state);
                if ($json !== null) {
                    $sidecar = takeout_parse_sidecar($json);
                } else {
                    $match = ['name' => null, 'via' => null];
                }
            }
        }
        if (!empty($sidecar['trashed']) && $match['via'] !== 'live') {
            $result = 'skipped_trashed';
            $detail = 'Google Fotoğraflar çöp kutusundaydı (trashed)';
        } else {
            [$tmp, $isTemp] = import_extract_media($imp, $si, $rawPath, $state);
            $kind = media_kind($tmp, $name);
            $probe = media_probe($tmp, (string) $kind, $name);
            // Fotoğrafın sidecar'ını ödünç alan video gerçekten kısa bir Live Photo videosu değilse ödünç alma.
            if ($match['via'] === 'live' && $probe['duration_ms'] !== null && $probe['duration_ms'] > LIVE_MAX_MS) {
                $sidecar = [];
                $match = ['name' => null, 'via' => null];
            }
            if (!empty($sidecar['trashed'])) {
                $result = 'skipped_trashed';
                $detail = 'Google Fotoğraflar çöp kutusundaydı (trashed)';
            } else {
                $res = takeout_resolve($sidecar, $probe, $name, $mtime > 0 ? $mtime : null);
                $r = asset_ingest($userId, $tmp, $res + [
                    'filename' => $name, 'source' => 'takeout', 'source_ref' => $nfc, 'probe' => $probe, 'move' => $isTemp,
                ]);
                if ($isTemp && $r['status'] === 'created') {
                    $tmp = null; // depoya taşındı
                }
                $assetId = $r['asset_id'];
                if ($r['status'] === 'created') {
                    $result = $match['name'] !== null ? 'imported' : 'no_metadata';
                    $srcLabel = ['exif' => 'fotoğraf bilgisinden (EXIF)', 'filename' => 'dosya adından', 'file' => 'dosya tarihinden (kesin değil)'][$res['date_source']] ?? $res['date_source'];
                    $detail = $match['name'] !== null ? 'sidecar: ' . $match['name'] : 'Meta veri (.json) dosyası bulunamadı; tarih ' . $srcLabel . ' alındı';
                } else {
                    // Önceki bir çalıştırma bu öğeyi tam kaydedemeden kesildiyse yine 'imported' say.
                    $sameItem = $assetId !== null && db_val("SELECT 1 FROM assets WHERE id = ? AND source = 'takeout' AND source_ref = ?", [$assetId, mb_substr($nfc, 0, 512)]) !== null
                        && db_val('SELECT 1 FROM import_items WHERE asset_id = ? LIMIT 1', [$assetId]) === null;
                    if ($sameItem) {
                        $result = $match['name'] !== null ? 'imported' : 'no_metadata';
                        $detail = 'Kesintiden sonra tamamlandı';
                    } else {
                        $result = 'duplicate';
                        $detail = $r['status'] === 'deleted_before' ? 'Daha önce kalıcı silinmiş' : 'Zaten kütüphanede';
                    }
                }
                if ($assetId !== null) {
                    foreach ($sidecar['people'] ?? [] as $person) {
                        db_exec('INSERT IGNORE INTO asset_people (asset_id, name) VALUES (?, ?)', [$assetId, mb_substr($person, 0, 190)]);
                    }
                    import_add_to_album($imp, $state, $dirKey, $assetId, $albums);
                }
            }
        }
    } catch (Throwable $e) {
        $result = 'failed';
        $detail = get_class($e) . ': ' . $e->getMessage();
        app_log('import', "#$id $nfc: $detail");
    } finally {
        if ($isTemp && $tmp !== null) {
            @unlink($tmp);
        }
    }
    db_exec('INSERT IGNORE INTO import_items (import_id, zip_name, entry_path, entry_hash, result, asset_id, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $id, $srcName, mb_substr($nfc, 0, 1024), $hash, $result, $assetId, $detail, now_utc(),
    ]);
}

/** Kova albümse albümü bulur/oluşturur ve asset'i ekler. */
function import_add_to_album(array $imp, ImportState $state, string $dirKey, string $assetId, array &$albums): void
{
    $userId = (int) $imp['user_id'];
    if (!array_key_exists($dirKey, $albums)) {
        $meta = null;
        $m = db_row("SELECT source_idx, path FROM import_entries WHERE import_id = ? AND kind = 'album_meta' AND dir_key = ? LIMIT 1", [$imp['id'], $dirKey]);
        if ($m !== null) {
            $meta = import_read_entry($imp, (int) $m['source_idx'], (string) $m['path'], $state);
        }
        $bucket = takeout_bucket($dirKey);
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
        $albums[$dirKey] = $albumId;
    }
    $albumId = $albums[$dirKey];
    if ($albumId === null) {
        return;
    }
    db_exec('INSERT IGNORE INTO album_assets (album_id, asset_id, added_by, sort_order, added_at) VALUES (?, ?, ?, 0, ?)', [$albumId, $assetId, $userId, now_utc()]);
    db_exec('UPDATE albums SET cover_asset_id = COALESCE(cover_asset_id, ?) WHERE id = ?', [$assetId, $albumId]);
}

/** Live Photo çiftleri (kısa video + yakın zaman) ve düzenlenmiş kopyalar arasında bağlantı kurar. */
function import_link(array $imp): void
{
    $id = (int) $imp['id'];
    $userId = (int) $imp['user_id'];
    $map = [];
    foreach (db_all('SELECT entry_path, asset_id FROM import_items WHERE import_id = ? AND asset_id IS NOT NULL', [$id]) as $r) {
        $map[$r['entry_path']] ??= $r['asset_id'];
    }
    $paths = array_keys($map);
    $changed = false;
    foreach (takeout_live_pairs($paths) as $photo => $video) {
        $p = $map[$photo];
        $v = $map[$video];
        if ($p === $v) {
            continue;
        }
        $pa = db_row('SELECT type, taken_at FROM assets WHERE id = ?', [$p]);
        $va = db_row('SELECT type, taken_at, duration_ms FROM assets WHERE id = ?', [$v]);
        if ($pa === null || $va === null || $pa['type'] !== 'photo' || $va['type'] !== 'video') {
            continue;
        }
        $gap = abs((int) strtotime($pa['taken_at'] . ' UTC') - (int) strtotime($va['taken_at'] . ' UTC'));
        $short = $va['duration_ms'] === null || (int) $va['duration_ms'] <= LIVE_MAX_MS;
        if (!$short || $gap > LIVE_MAX_GAP_SEC) {
            continue;
        }
        db_exec('UPDATE assets SET live_pair_id = ? WHERE id = ? AND user_id = ?', [$v, $p, $userId]);
        db_exec('UPDATE assets SET live_pair_id = ? WHERE id = ? AND user_id = ?', [$p, $v, $userId]);
        $changed = true;
    }
    $byDir = [];
    foreach ($paths as $p) {
        $byDir[dirname($p)][] = $p;
    }
    foreach ($paths as $p) {
        $orig = takeout_edited_original($p, $byDir[dirname($p)] ?? []);
        if ($orig !== null && $map[$orig] !== $map[$p]) {
            db_exec('UPDATE assets SET edited_of_id = ? WHERE id = ? AND user_id = ?', [$map[$orig], $map[$p], $userId]);
            $changed = true;
        }
    }
    if ($changed) {
        $v = asset_bump_version($userId);
        db_exec("UPDATE assets SET version = ? WHERE user_id = ? AND source = 'takeout' AND (live_pair_id IS NOT NULL OR edited_of_id IS NOT NULL)", [$v, $userId]);
    }
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

/** Rapor: sayımlar ve sorunlu öğe listeleri. */
function import_report(int $importId, int $limit = 500): array
{
    $imp = db_row('SELECT * FROM imports WHERE id = ?', [$importId]);
    if ($imp === null) {
        throw new ValidationError('İçe aktarma yok', 'id');
    }
    $counts = import_counts($importId);
    $items = [];
    foreach (['failed', 'unsupported', 'no_metadata', 'skipped_trashed'] as $res) {
        $items[$res] = db_all('SELECT zip_name, entry_path, asset_id, detail FROM import_items WHERE import_id = ? AND result = ? ORDER BY id LIMIT ' . (int) $limit, [$importId, $res]);
    }
    return ['import' => $imp, 'counts' => $counts, 'items' => $items];
}
