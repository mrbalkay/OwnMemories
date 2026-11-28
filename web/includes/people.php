<?php
/**
 * OwnMemories — Kişiler: elle etiketleme ve yüz tanıma (/v1/people, /v1/faces, /v1/assets/{id}/people).
 *
 * Veri modeli (migrations/006_people.sql):
 *   people        kullanıcının kişileri; name NULL = adlandırılmamış yüz kümesi (ML oluşturur)
 *   asset_person  "bu fotoğrafta kim var" — tek kaynak; source manual | takeout | face
 *   faces         ML'in bulduğu yüzler (kutu 0..1, ekranda görünen görüntüye göre), person_id atanmış kişi,
 *                 rejected_person_id kullanıcının "bu o değil" dediği kişi (kümeleme tekrar atamaz),
 *                 assigned_by 'user' olan yüzleri kümeleme taşımaz
 *   face_scans    taranan asset'ler (0 yüz dahil)
 *
 * Kurallar:
 * - Her şey geçerli kullanıcıya ait; başkasının kişi/yüz/asset kimliği 404.
 * - Yüz bir kişiye atanınca asset_person'a 'face' satırı eklenir (manual/takeout varsa dokunulmaz); kişinin o
 *   fotoğrafta başka yüzü kalmazsa 'face' satırı silinir.
 * - Kişiyi fotoğraftan çıkarmak satırı siler ve kişinin o fotoğraftaki yüzlerini ayırır + reddeder.
 * - Adsız ve boş (satırı ve yüzü kalmayan) kümeler silinir; adlı kişiler fotoğrafsız da kalır (listede görünmez).
 * - Yeniden adlandırma, birleştirme, yüz atama ve silme sonrası ML'e POST /faces/recluster (ateşle-unut).
 */
declare(strict_types=1);

const PEOPLE_BULK_MAX = 500;
const FACE_THUMB_SIZE = 256;
const FACE_THUMB_PAD = 0.35; // kutunun uzun kenarına göre her yana dolgu

/* ---------------------------------------------------------------- yardımcılar */

/** Kullanıcının kişisi ya da 404. */
function person_get_owned(int $uid, $id): array
{
    $p = is_numeric($id) ? db_row('SELECT * FROM people WHERE id = ? AND user_id = ?', [(int) $id, $uid]) : null;
    if ($p === null) {
        api_not_found();
    }
    return $p;
}

/** Kullanıcının kendi asset'i ya da 404. */
function people_asset_owned(int $uid, string $assetId): array
{
    $a = is_uuid($assetId) ? db_row('SELECT * FROM assets WHERE id = ? AND user_id = ?', [$assetId, $uid]) : null;
    if ($a === null) {
        api_not_found();
    }
    return $a;
}

/** Kullanıcının yüzü ya da 404. */
function face_get_owned(int $uid, $id): array
{
    $f = is_numeric($id) ? db_row('SELECT f.* FROM faces f JOIN assets a ON a.id = f.asset_id AND a.user_id = f.user_id WHERE f.id = ? AND f.user_id = ?', [(int) $id, $uid]) : null;
    if ($f === null) {
        api_not_found();
    }
    return $f;
}

/** Ad doğrulama: kırpılmış ad; boşsa null (yalnızca $allowNull ise), 190 karakterden uzunsa 422. */
function person_clean_name($v, bool $allowNull = false): ?string
{
    if ($v === null && $allowNull) {
        return null;
    }
    if (!is_string($v)) {
        throw new ValidationError('Geçersiz ad.', 'name');
    }
    $n = trim((string) preg_replace('/\s+/u', ' ', $v));
    if ($n === '') {
        if ($allowNull) {
            return null;
        }
        throw new ValidationError('Ad gerekli.', 'name');
    }
    if (mb_strlen($n) > 190) {
        throw new ValidationError('Ad en fazla 190 karakter olabilir.', 'name');
    }
    return $n;
}

function person_find_by_name(int $uid, string $name, int $exceptId = 0): ?array
{
    return db_row('SELECT * FROM people WHERE user_id = ? AND name = ? AND id <> ? ORDER BY id LIMIT 1', [$uid, $name, $exceptId]);
}

function person_create(int $uid, ?string $name): int
{
    db_exec('INSERT INTO people (user_id, name, cover_face_id, hidden, created_at, updated_at) VALUES (?, ?, NULL, 0, ?, ?)', [$uid, $name, now_utc(), now_utc()]);
    return (int) db()->lastInsertId();
}

/** Ada göre kişiyi bulur ya da oluşturur (Takeout içe aktarma ve "Kişi ekle"). */
function person_find_or_create(int $uid, string $name): int
{
    $p = person_find_by_name($uid, $name);
    return $p !== null ? (int) $p['id'] : person_create($uid, $name);
}

/** Gövdedeki person_id (kullanıcınınki olmalı) veya name (yoksa oluşturulur) → kişi kimliği. */
function people_resolve(int $uid, array $b): int
{
    if (isset($b['person_id']) && $b['person_id'] !== '') {
        return (int) person_get_owned($uid, $b['person_id'])['id'];
    }
    if (!array_key_exists('name', $b)) {
        throw new ValidationError('person_id veya name gerekli.', 'name');
    }
    return person_find_or_create($uid, (string) person_clean_name($b['name']));
}

/** asset_person satırı ekler; $source 'manual' ise mevcut 'face'/'takeout' satırını 'manual' yapar, diğerleri dokunmaz. */
function asset_person_add(string $assetId, int $personId, string $source): void
{
    if ($source === 'manual') {
        db_exec("INSERT INTO asset_person (asset_id, person_id, source, created_at) VALUES (?, ?, 'manual', ?) ON DUPLICATE KEY UPDATE source = 'manual'", [$assetId, $personId, now_utc()]);
    } else {
        db_exec('INSERT IGNORE INTO asset_person (asset_id, person_id, source, created_at) VALUES (?, ?, ?, ?)', [$assetId, $personId, $source, now_utc()]);
    }
}

/** Takeout içe aktarıcı: ada göre kişi + 'takeout' etiketi. */
function people_tag_takeout(int $uid, string $assetId, string $name): void
{
    $n = trim($name);
    if ($n === '') {
        return;
    }
    asset_person_add($assetId, person_find_or_create($uid, mb_substr($n, 0, 190)), 'takeout');
}

/** Kişinin bu fotoğrafta yüzü kalmadıysa 'face' kaynaklı satırını siler. */
function asset_person_drop_face_row(string $assetId, int $personId): void
{
    db_exec("DELETE FROM asset_person WHERE asset_id = ? AND person_id = ? AND source = 'face'
             AND NOT EXISTS (SELECT 1 FROM faces WHERE asset_id = ? AND person_id = ?)", [$assetId, $personId, $assetId, $personId]);
}

/** Satırı ve yüzü kalmamış adsız kümeleri siler (verilen kimliklerle sınırlı; boşsa kullanıcının hepsi). */
function people_cleanup_empty(array $personIds): void
{
    $personIds = array_values(array_unique(array_filter(array_map('intval', $personIds))));
    if (!$personIds) {
        return;
    }
    db_exec('DELETE FROM people WHERE name IS NULL AND id IN (' . db_in($personIds) . ')
             AND NOT EXISTS (SELECT 1 FROM asset_person ap WHERE ap.person_id = people.id)
             AND NOT EXISTS (SELECT 1 FROM faces f WHERE f.person_id = people.id)', $personIds);
}

/** ML'e yeniden kümeleme isteği (ateşle-unut, kısa zaman aşımı; hata yok sayılır). */
function people_recluster(int $uid): void
{
    try {
        ml_request('POST', '/faces/recluster', ['user_id' => $uid], (float) config('ml.recluster_timeout', 0.5));
    } catch (Throwable $e) {
        // yok say
    }
}

/* ------------------------------------------------------------------- JSON */

/**
 * people satırlarını API nesnesine çevirir. $counts verilmezse sayılar sorgulanır.
 * Kapak: geçerli cover_face_id (hâlâ bu kişinin yüzü) yoksa en yüksek puanlı yüz; yüz yoksa en yeni fotoğraf.
 */
function people_to_json(int $uid, array $rows, ?array $counts = null): array
{
    if (!$rows) {
        return [];
    }
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $in = db_in($ids);
    if ($counts === null) {
        $counts = [];
        foreach (db_all("SELECT ap.person_id, COUNT(*) AS n FROM asset_person ap JOIN assets a ON a.id = ap.asset_id AND a.trashed_at IS NULL
                         WHERE ap.person_id IN ($in) GROUP BY ap.person_id", $ids) as $r) {
            $counts[(int) $r['person_id']] = (int) $r['n'];
        }
    }
    // Açıkça seçilmiş kapaklar.
    $chosen = [];
    $coverIds = array_values(array_filter(array_map(fn($r) => (int) ($r['cover_face_id'] ?? 0), $rows)));
    if ($coverIds) {
        foreach (db_all('SELECT id, asset_id, person_id FROM faces WHERE user_id = ? AND id IN (' . db_in($coverIds) . ')', array_merge([$uid], $coverIds)) as $f) {
            $chosen[(int) $f['id']] = $f;
        }
    }
    $best = [];
    foreach (db_all("SELECT person_id, id, asset_id FROM (
                SELECT f.person_id, f.id, f.asset_id, ROW_NUMBER() OVER (PARTITION BY f.person_id ORDER BY a.trashed_at IS NOT NULL, f.score DESC, (f.x2 - f.x1) * (f.y2 - f.y1) DESC, f.id) AS rn
                FROM faces f JOIN assets a ON a.id = f.asset_id WHERE f.user_id = ? AND f.person_id IN ($in)
            ) x WHERE rn = 1", array_merge([$uid], $ids)) as $f) {
        $best[(int) $f['person_id']] = $f;
    }
    $latest = [];
    $noFace = array_values(array_filter($ids, fn($id) => !isset($best[$id])));
    if ($noFace) {
        foreach (db_all('SELECT person_id, asset_id FROM (
                    SELECT ap.person_id, ap.asset_id, ROW_NUMBER() OVER (PARTITION BY ap.person_id ORDER BY a.taken_at DESC, a.id DESC) AS rn
                    FROM asset_person ap JOIN assets a ON a.id = ap.asset_id AND a.trashed_at IS NULL WHERE ap.person_id IN (' . db_in($noFace) . ')
                ) x WHERE rn = 1', $noFace) as $r) {
            $latest[(int) $r['person_id']] = $r['asset_id'];
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $face = null;
        $c = (int) ($r['cover_face_id'] ?? 0);
        if ($c && isset($chosen[$c]) && (int) $chosen[$c]['person_id'] === $id) {
            $face = $chosen[$c];
        } elseif (isset($best[$id])) {
            $face = $best[$id];
        }
        $out[] = [
            'id' => $id,
            'name' => $r['name'] !== null ? (string) $r['name'] : null,
            'count' => $counts[$id] ?? 0,
            'cover_face_id' => $face !== null ? (int) $face['id'] : null,
            'cover_asset_id' => $face !== null ? $face['asset_id'] : ($latest[$id] ?? null),
            'hidden' => (bool) $r['hidden'],
        ];
    }
    return $out;
}

function person_json(int $uid, int $id): array
{
    $r = db_row('SELECT * FROM people WHERE id = ? AND user_id = ?', [$id, $uid]);
    if ($r === null) {
        api_not_found();
    }
    return people_to_json($uid, [$r])[0];
}

/** GET /v1/assets/{id}/people yükü: kişiler (adlılar önce) ve yüzler (soldan sağa). */
function asset_people_payload(int $uid, string $assetId): array
{
    $people = db_all('SELECT p.id, p.name, ap.source FROM asset_person ap JOIN people p ON p.id = ap.person_id
                      WHERE ap.asset_id = ? AND p.user_id = ? ORDER BY p.name IS NULL, p.name, p.id', [$assetId, $uid]);
    $faces = db_all('SELECT f.id, f.x1, f.y1, f.x2, f.y2, f.person_id, p.name AS person_name FROM faces f
                     LEFT JOIN people p ON p.id = f.person_id WHERE f.asset_id = ? AND f.user_id = ? ORDER BY f.x1, f.y1, f.id', [$assetId, $uid]);
    return [
        'people' => array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'] !== null ? (string) $p['name'] : null, 'source' => $p['source']], $people),
        'faces' => array_map(fn($f) => [
            'id' => (int) $f['id'],
            'x1' => round((float) $f['x1'], 4), 'y1' => round((float) $f['y1'], 4),
            'x2' => round((float) $f['x2'], 4), 'y2' => round((float) $f['y2'], 4),
            'person_id' => $f['person_id'] !== null ? (int) $f['person_id'] : null,
            'person_name' => $f['person_name'] !== null ? (string) $f['person_name'] : null,
        ], $faces),
    ];
}

/** Asset ayrıntısındaki geriye uyumlu 'people' alanı: adlı kişilerin adları. */
function asset_person_names(string $assetId): array
{
    return array_map('strval', array_column(db_all('SELECT p.name FROM asset_person ap JOIN people p ON p.id = ap.person_id
            WHERE ap.asset_id = ? AND p.name IS NOT NULL ORDER BY p.name', [$assetId]), 'name'));
}

/* ------------------------------------------------------------------ uçlar */

function api_people_list(array $user, array $q): array
{
    $uid = (int) $user['id'];
    $hidden = api_bool($q['include_hidden'] ?? null) ? '' : ' AND p.hidden = 0';
    $rows = db_all("SELECT p.*, COUNT(a.id) AS n FROM people p
                    LEFT JOIN asset_person ap ON ap.person_id = p.id
                    LEFT JOIN assets a ON a.id = ap.asset_id AND a.trashed_at IS NULL
                    WHERE p.user_id = ?$hidden GROUP BY p.id
                    HAVING (p.name IS NOT NULL AND n >= 1) OR (p.name IS NULL AND n >= 2)
                    ORDER BY p.name IS NULL, n DESC, p.name, p.id", [$uid]);
    $counts = [];
    foreach ($rows as $r) {
        $counts[(int) $r['id']] = (int) $r['n'];
    }
    return api_ok(['people' => people_to_json($uid, $rows, $counts)]);
}

function api_person_get(array $user, string $id): array
{
    $uid = (int) $user['id'];
    return api_ok(['person' => person_json($uid, (int) person_get_owned($uid, $id)['id'])]);
}

function api_person_patch(array $user, string $id, array $b): array
{
    $uid = (int) $user['id'];
    $p = person_get_owned($uid, $id);
    $pid = (int) $p['id'];
    $set = [];
    $args = [];
    $renamed = false;
    if (array_key_exists('name', $b)) {
        $name = person_clean_name($b['name'], true);
        if ($name !== null && ($other = person_find_by_name($uid, $name, $pid)) !== null) {
            throw new ApiError(409, 'name_exists', 'Bu adda bir kişi zaten var.', [
                'person_id' => (int) $other['id'], 'person' => people_to_json($uid, [$other])[0],
            ]);
        }
        $set[] = 'name = ?';
        $args[] = $name;
        $renamed = $name !== $p['name'];
    }
    if (array_key_exists('hidden', $b)) {
        $set[] = 'hidden = ?';
        $args[] = api_bool($b['hidden']) ? 1 : 0;
    }
    if (array_key_exists('cover_face_id', $b)) {
        $fid = $b['cover_face_id'];
        if ($fid !== null) {
            if (!is_numeric($fid) || db_val('SELECT 1 FROM faces WHERE id = ? AND user_id = ? AND person_id = ?', [(int) $fid, $uid, $pid]) === null) {
                throw new ValidationError('Kapak bu kişinin yüzlerinden biri olmalı.', 'cover_face_id');
            }
            $fid = (int) $fid;
        }
        $set[] = 'cover_face_id = ?';
        $args[] = $fid;
    }
    if ($set) {
        $set[] = 'updated_at = ?';
        array_push($args, now_utc(), $pid, $uid);
        db_exec('UPDATE people SET ' . implode(', ', $set) . ' WHERE id = ? AND user_id = ?', $args);
    }
    if ($renamed) {
        people_recluster($uid);
    }
    return api_ok(['person' => person_json($uid, $pid)]);
}

/** Kaynak kişiyi hedefe katar: yüzler, etiketler (elle olan korunur), retler; kaynak silinir. */
function api_person_merge(array $user, string $id, array $b): array
{
    $uid = (int) $user['id'];
    $src = person_get_owned($uid, $id);
    if (!isset($b['into'])) {
        throw new ValidationError('Hedef kişi gerekli.', 'into');
    }
    $dst = person_get_owned($uid, $b['into']);
    $s = (int) $src['id'];
    $d = (int) $dst['id'];
    if ($s === $d) {
        throw new ValidationError('Kişi kendisiyle birleştirilemez.', 'into');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_exec('UPDATE faces SET person_id = ? WHERE person_id = ? AND user_id = ?', [$d, $s, $uid]);
        db_exec('UPDATE faces SET rejected_person_id = ? WHERE rejected_person_id = ? AND user_id = ?', [$d, $s, $uid]);
        db_exec("INSERT INTO asset_person (asset_id, person_id, source, created_at)
                 SELECT x.asset_id, ?, x.source, x.created_at FROM asset_person x WHERE x.person_id = ?
                 ON DUPLICATE KEY UPDATE source = IF(asset_person.source = 'manual' OR VALUES(source) = 'manual', 'manual',
                                                  IF(asset_person.source = 'takeout' OR VALUES(source) = 'takeout', 'takeout', 'face'))", [$d, $s]);
        db_exec('DELETE FROM asset_person WHERE person_id = ?', [$s]);
        db_exec('UPDATE people SET name = COALESCE(name, ?), cover_face_id = COALESCE(cover_face_id, ?), updated_at = ? WHERE id = ?', [$src['name'], $src['cover_face_id'], now_utc(), $d]);
        db_exec('DELETE FROM people WHERE id = ?', [$s]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    people_recluster($uid);
    return api_ok(['person' => person_json($uid, $d)]);
}

function api_person_delete(array $user, string $id): array
{
    $uid = (int) $user['id'];
    $pid = (int) person_get_owned($uid, $id)['id'];
    db_exec("UPDATE faces SET person_id = NULL, rejected_person_id = ?, assigned_by = NULL WHERE person_id = ? AND user_id = ?", [$pid, $pid, $uid]);
    db_exec('DELETE FROM asset_person WHERE person_id = ?', [$pid]);
    db_exec('DELETE FROM people WHERE id = ?', [$pid]);
    people_recluster($uid);
    return api_ok(['ok' => true]);
}

function api_asset_people_get(array $user, string $assetId): array
{
    $uid = (int) $user['id'];
    people_asset_owned($uid, $assetId);
    return api_ok(asset_people_payload($uid, $assetId));
}

function api_asset_people_add(array $user, string $assetId, array $b): array
{
    $uid = (int) $user['id'];
    people_asset_owned($uid, $assetId);
    asset_person_add($assetId, people_resolve($uid, $b), 'manual');
    return api_ok(asset_people_payload($uid, $assetId));
}

function api_asset_people_remove(array $user, string $assetId, string $personId): array
{
    $uid = (int) $user['id'];
    people_asset_owned($uid, $assetId);
    $pid = (int) person_get_owned($uid, $personId)['id'];
    db_exec('DELETE FROM asset_person WHERE asset_id = ? AND person_id = ?', [$assetId, $pid]);
    $moved = db_exec('UPDATE faces SET person_id = NULL, rejected_person_id = ?, assigned_by = NULL WHERE asset_id = ? AND person_id = ? AND user_id = ?', [$pid, $assetId, $pid, $uid]);
    people_cleanup_empty([$pid]);
    if ($moved > 0) {
        people_recluster($uid);
    }
    return api_ok(asset_people_payload($uid, $assetId));
}

function api_assets_people_bulk(array $user, array $b): array
{
    $uid = (int) $user['id'];
    $ids = (array) ($b['ids'] ?? []);
    if (count($ids) > PEOPLE_BULK_MAX) {
        throw new ValidationError('En fazla ' . PEOPLE_BULK_MAX . ' öğe.', 'ids');
    }
    $owned = asset_owned_ids($uid, $ids);
    if (!$owned) {
        api_not_found(); // başkasının ya da olmayan öğeler: boş kişi de oluşturma
    }
    $pid = people_resolve($uid, $b);
    foreach ($owned as $aid) {
        asset_person_add($aid, $pid, 'manual');
    }
    return api_ok(['count' => count($owned), 'person' => person_json($uid, $pid)]);
}

/** Yüzü kişiye ata (kullanıcı kararı) ya da ayır (person_id null → reddedilir). */
function api_face_patch(array $user, string $faceId, array $b): array
{
    $uid = (int) $user['id'];
    $f = face_get_owned($uid, $faceId);
    $fid = (int) $f['id'];
    $old = $f['person_id'] !== null ? (int) $f['person_id'] : null;
    $assetId = $f['asset_id'];
    if (array_key_exists('person_id', $b) && $b['person_id'] === null && !array_key_exists('name', $b)) {
        if ($old !== null) {
            db_exec('UPDATE faces SET person_id = NULL, rejected_person_id = ?, assigned_by = NULL WHERE id = ?', [$old, $fid]);
            db_exec('UPDATE people SET cover_face_id = NULL WHERE id = ? AND cover_face_id = ?', [$old, $fid]);
        }
    } else {
        if (!array_key_exists('person_id', $b) && !array_key_exists('name', $b)) {
            throw new ValidationError('person_id veya name gerekli.', 'person_id');
        }
        $pid = people_resolve($uid, $b);
        db_exec("UPDATE faces SET person_id = ?, assigned_by = 'user', rejected_person_id = IF(rejected_person_id = ?, NULL, rejected_person_id) WHERE id = ?", [$pid, $pid, $fid]);
        asset_person_add($assetId, $pid, 'face');
        if ($old !== null && $old !== $pid) {
            db_exec('UPDATE people SET cover_face_id = NULL WHERE id = ? AND cover_face_id = ?', [$old, $fid]);
        }
    }
    if ($old !== null) {
        asset_person_drop_face_row($assetId, $old);
        people_cleanup_empty([$old]);
    }
    people_recluster($uid);
    return api_ok(asset_people_payload($uid, $assetId));
}

function face_thumb_path(int $faceId): string
{
    return storage_root() . '/derived/face/' . substr((string) $faceId, 0, 2) . '/' . $faceId . '.jpg';
}

function api_face_thumb(array $user, string $faceId): array
{
    $uid = (int) $user['id'];
    $f = face_get_owned($uid, $faceId);
    $dest = face_thumb_path((int) $f['id']);
    if (!is_file($dest) || filesize($dest) === 0) {
        $src = derived_find($f['asset_id'], 'preview') ?? derived_find($f['asset_id'], 'thumb') ?? derived_find($f['asset_id'], 'poster');
        if ($src === null || !face_thumb_render($src, [(float) $f['x1'], (float) $f['y1'], (float) $f['x2'], (float) $f['y2']], $dest)) {
            api_not_found();
        }
    }
    return ['status' => 200, 'file' => ['path' => $dest, 'mime' => 'image/jpeg', 'cache' => 'private, max-age=31536000, immutable', 'download' => null]];
}

/**
 * Kare kırpma alanı (piksel): kutunun uzun kenarı + her yana FACE_THUMB_PAD dolgu, merkez kutunun merkezi,
 * görüntü sınırları içinde kalacak şekilde kaydırılır/küçültülür. [x, y, kenar]
 */
function face_crop_rect(int $w, int $h, array $box): array
{
    [$x1, $y1, $x2, $y2] = array_map(fn($v) => max(0.0, min(1.0, $v)), $box);
    $bw = max(1.0, ($x2 - $x1) * $w);
    $bh = max(1.0, ($y2 - $y1) * $h);
    $side = max($bw, $bh) * (1 + 2 * FACE_THUMB_PAD);
    $side = (int) round(min($side, $w, $h));
    $side = max(1, $side);
    $cx = ($x1 + $x2) / 2 * $w;
    $cy = ($y1 + $y2) / 2 * $h;
    $x = (int) round(max(0, min($w - $side, $cx - $side / 2)));
    $y = (int) round(max(0, min($h - $side, $cy - $side / 2)));
    return [$x, $y, $side];
}

/** Türev önizlemeden (yönü zaten uygulanmış) yüz kırpması yazar: GD, olmazsa Imagick. */
function face_thumb_render(string $src, array $box, string $dest): bool
{
    @mkdir(dirname($dest), 0750, true);
    $tmp = $dest . '.tmp-' . bin2hex(random_bytes(4));
    $ok = false;
    $data = @file_get_contents($src);
    $img = function_exists('imagecreatefromstring') && is_string($data) ? @imagecreatefromstring($data) : false;
    if ($img !== false) {
        [$x, $y, $side] = face_crop_rect(imagesx($img), imagesy($img), $box);
        $dst = imagecreatetruecolor(FACE_THUMB_SIZE, FACE_THUMB_SIZE);
        imagecopyresampled($dst, $img, 0, 0, $x, $y, FACE_THUMB_SIZE, FACE_THUMB_SIZE, $side, $side);
        $ok = imagejpeg($dst, $tmp, 85);
        imagedestroy($dst);
        imagedestroy($img);
    } elseif (class_exists('Imagick')) {
        try {
            $im = new Imagick($src . '[0]');
            [$x, $y, $side] = face_crop_rect($im->getImageWidth(), $im->getImageHeight(), $box);
            $im->cropImage($side, $side, $x, $y);
            $im->setImagePage(0, 0, 0, 0);
            $im->resizeImage(FACE_THUMB_SIZE, FACE_THUMB_SIZE, Imagick::FILTER_LANCZOS, 1);
            $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $im->stripImage();
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(85);
            $ok = $im->writeImage($tmp);
            $im->clear();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        return false;
    }
    return rename($tmp, $dest);
}

function api_people_status(array $user): array
{
    $r = ml_request('GET', '/faces/status', ['user_id' => (int) $user['id']]);
    if ($r === null || !array_key_exists('scanned', $r)) {
        return api_ok(['available' => false]);
    }
    return api_ok([
        'available' => (bool) ($r['available'] ?? true),
        'scanned' => (int) $r['scanned'],
        'total' => (int) ($r['total'] ?? 0),
        'faces' => (int) ($r['faces'] ?? 0),
        'people' => (int) ($r['people'] ?? 0),
        'ready' => (bool) ($r['ready'] ?? false),
    ]);
}

/**
 * Kalıcı silmede kişi verisini temizler: yüzler, yüz kırpmaları, taramalar, etiketler; boş adsız kümeler silinir,
 * silinen yüzü kapak olan kişilerin kapağı sıfırlanır. 006 kurulmadıysa sessizce geçer.
 */
function people_purge_asset(string $assetId): void
{
    try {
        $faces = db_all('SELECT id, person_id FROM faces WHERE asset_id = ?', [$assetId]);
        $persons = array_merge(array_column($faces, 'person_id'), array_column(db_all('SELECT person_id FROM asset_person WHERE asset_id = ?', [$assetId]), 'person_id'));
        $faceIds = array_map('intval', array_column($faces, 'id'));
        if ($faceIds) {
            db_exec('UPDATE people SET cover_face_id = NULL WHERE cover_face_id IN (' . db_in($faceIds) . ')', $faceIds);
        }
        db_exec('DELETE FROM faces WHERE asset_id = ?', [$assetId]);
        db_exec('DELETE FROM face_scans WHERE asset_id = ?', [$assetId]);
        db_exec('DELETE FROM asset_person WHERE asset_id = ?', [$assetId]);
        people_cleanup_empty(array_filter($persons, fn($v) => $v !== null));
        foreach ($faceIds as $fid) {
            @unlink(face_thumb_path($fid));
        }
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) !== 1146) { // ER_NO_SUCH_TABLE
            throw $e;
        }
    }
}
