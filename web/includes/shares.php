<?php
/**
 * OwnMemories — bağlantıyla paylaşım (sahip tarafı + ortak yardımcılar).
 *
 * Bir albüm (güncel içeriğiyle) veya bir seçim, tailnet dışındaki kişilere (Tailscale Funnel ile yalnızca
 * paylaşım ucu açılır; bkz. server/setup-share.sh) /s/<belirteç> adresinden gösterilir.
 *
 * Güvenlik:
 *  - Belirteç 256 bit rastgele (base64url, 43 karakter); veritabanında yalnızca SHA-256 özeti tutulur.
 *  - Bağlantıdaki her öğe, belirteçten türetilen anahtarla AES ile şifrelenmiş asset kimliğiyle (22 karakter)
 *    adreslenir: anahtar başka bir bağlantıda çalışmaz, iç kimlik hiç görünmez, tahmin edilen anahtar
 *    bağlantıya ait olmayan bir öğeye çözülür ve reddedilir.
 *  - show_location=0: türev görüntüler zaten metadatasızdır (vips/Imagick strip); orijinal indirmede GPS anında
 *    silinir (exiftool → stdout; yoksa JPEG için PHP), videolar ffmpeg ile metadatasız kopyalanır
 *    (derived/share-*). Orijinal dosyalara asla yazılmaz.
 * Genel uç (HTML sayfaları, medya, zip): web/includes/share_public.php.
 */
declare(strict_types=1);

const SHARE_TOKEN_RE = '[A-Za-z0-9_-]{43}';
const SHARE_KEY_RE = '[A-Za-z0-9_-]{22}';
const SHARE_EXPIRY = ['1d' => 86400, '1w' => 7 * 86400, '1m' => 30 * 86400, 'never' => null];
const SHARE_MAX_SELECTION = 2000;
const SHARE_MIN_PASSWORD = 4;

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $s): ?string
{
    $r = base64_decode(strtr($s, '-_', '+/'), true);
    return $r === false ? null : $r;
}

/** Sunucuya özgü gizli anahtar (öğe anahtarları ve parola çerezi imzası). İlk kullanımda üretilir. */
function share_secret(): string
{
    $s = site_setting('share_secret');
    if (!is_string($s) || strlen($s) < 64) {
        db_exec('INSERT IGNORE INTO site_settings (`key`, value) VALUES (?, ?)', ['share_secret', bin2hex(random_bytes(32))]);
        site_setting_cache_clear();
        $s = (string) site_setting('share_secret');
    }
    return $s;
}

function share_token_new(): string
{
    return b64url(random_bytes(32));
}

function share_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Paylaşım adresinin kökü: shares.public_url (Funnel adresi) yoksa isteğin kendi adresi. */
function share_base_url(): string
{
    $cfg = (string) config('shares.public_url', '');
    if (preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $cfg)) {
        return rtrim($cfg, '/');
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host)) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https://' : 'http://') . $host;
    }
    return rtrim((string) config('app.url', 'http://localhost'), '/');
}

function share_public_url(string $token): string
{
    return share_base_url() . '/s/' . $token;
}

/* ------------------------------------------------------------ öğe anahtarı */

function share_item_cipher_key(string $token): string
{
    return hash_hmac('sha256', 'item|' . $token, share_secret(), true);
}

/** Asset kimliğinin bu bağlantıya özgü, tersine çevrilebilir anahtarı (22 karakter). */
function share_item_key(string $token, string $assetId): string
{
    $bin = (string) hex2bin(str_replace('-', '', $assetId));
    $c = openssl_encrypt($bin, 'aes-256-ecb', share_item_cipher_key($token), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    return b64url((string) $c);
}

/** Anahtarı asset kimliğine çözer (üyelik ayrıca denetlenmeli). */
function share_item_asset(string $token, string $key): ?string
{
    if (!preg_match('/^' . SHARE_KEY_RE . '$/', $key)) {
        return null;
    }
    $bin = b64url_decode($key);
    if ($bin === null || strlen($bin) !== 16) {
        return null;
    }
    $p = openssl_decrypt($bin, 'aes-256-ecb', share_item_cipher_key($token), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    if ($p === false || strlen($p) !== 16) {
        return null;
    }
    $h = bin2hex($p);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

/* --------------------------------------------------------------- içerik */

/** Bağlantıdaki görünür öğeler (çöpte olmayanlar), çekim sırasına göre. */
/** Liste/galeri/zip için gereken sütunlar (a.* yerine: büyük albümlerde bellek yarıya iner). */
const SHARE_ITEM_COLS = 'a.id, a.user_id, a.blob_sha1, a.type, a.original_filename, a.mime, a.size, a.width, a.height, a.duration_ms, a.taken_at, a.taken_tz_offset';

function share_items(array $link): array
{
    if ($link['kind'] === 'album') {
        return db_all('SELECT ' . SHARE_ITEM_COLS . ' FROM album_assets aa JOIN assets a ON a.id = aa.asset_id
            WHERE aa.album_id = ? AND a.trashed_at IS NULL ORDER BY a.taken_at ASC, a.id ASC', [$link['album_id']]);
    }
    return db_all('SELECT ' . SHARE_ITEM_COLS . ' FROM share_link_assets s JOIN assets a ON a.id = s.asset_id
        WHERE s.link_id = ? AND a.user_id = ? AND a.trashed_at IS NULL ORDER BY a.taken_at ASC, a.id ASC', [$link['id'], $link['user_id']]);
}

/** Öğe bu bağlantıda görünür mü? Görünürse asset satırı. */
function share_item_row(array $link, string $assetId): ?array
{
    if (!is_uuid($assetId)) {
        return null;
    }
    if ($link['kind'] === 'album') {
        return db_row('SELECT a.* FROM album_assets aa JOIN assets a ON a.id = aa.asset_id
            WHERE aa.album_id = ? AND aa.asset_id = ? AND a.trashed_at IS NULL', [$link['album_id'], $assetId]);
    }
    return db_row('SELECT a.* FROM share_link_assets s JOIN assets a ON a.id = s.asset_id
        WHERE s.link_id = ? AND s.asset_id = ? AND a.user_id = ? AND a.trashed_at IS NULL', [$link['id'], $assetId, $link['user_id']]);
}

function share_count(array $link): int
{
    if ($link['kind'] === 'album') {
        return (int) db_val('SELECT COUNT(*) FROM album_assets aa JOIN assets a ON a.id = aa.asset_id WHERE aa.album_id = ? AND a.trashed_at IS NULL', [$link['album_id']]);
    }
    return (int) db_val('SELECT COUNT(*) FROM share_link_assets s JOIN assets a ON a.id = s.asset_id WHERE s.link_id = ? AND a.user_id = ? AND a.trashed_at IS NULL', [$link['id'], $link['user_id']]);
}

/** Kapak: albüm kapağı (bağlantıdaysa) yoksa ilk fotoğraf. */
function share_cover_id(array $link, array $items): ?string
{
    if ($link['kind'] === 'album') {
        $c = db_val('SELECT cover_asset_id FROM albums WHERE id = ?', [$link['album_id']]);
        foreach ($items as $a) {
            if ($a['id'] === $c) {
                return $c;
            }
        }
    }
    foreach ($items as $a) {
        if ($a['type'] === 'photo') {
            return $a['id'];
        }
    }
    return $items[0]['id'] ?? null;
}

/** share_cover_id() ile aynı sonuç, ama tek sorguyla (öğeleri yüklemeden). */
function share_cover_fast(array $link): ?string
{
    if ($link['kind'] === 'album') {
        $c = db_val('SELECT al.cover_asset_id FROM albums al JOIN album_assets aa ON aa.album_id = al.id AND aa.asset_id = al.cover_asset_id
            JOIN assets a ON a.id = aa.asset_id AND a.trashed_at IS NULL WHERE al.id = ?', [$link['album_id']]);
        if ($c !== null) {
            return (string) $c;
        }
        $from = 'album_assets aa JOIN assets a ON a.id = aa.asset_id WHERE aa.album_id = ? AND a.trashed_at IS NULL';
        $args = [$link['album_id']];
    } else {
        $from = 'share_link_assets s JOIN assets a ON a.id = s.asset_id WHERE s.link_id = ? AND a.user_id = ? AND a.trashed_at IS NULL';
        $args = [$link['id'], $link['user_id']];
    }
    $id = db_val("SELECT a.id FROM $from ORDER BY a.type = 'photo' DESC, a.taken_at ASC, a.id ASC LIMIT 1", $args);
    return $id === null ? null : (string) $id;
}

/**
 * Belirteci çözer. state: ok | not_found | expired | revoked.
 * revoked: iptal edilmiş, sahibi devre dışı veya albüm silinmiş/el değiştirmiş.
 */
function share_resolve(string $token): array
{
    if (!preg_match('/^' . SHARE_TOKEN_RE . '$/', $token)) {
        return ['state' => 'not_found', 'link' => null];
    }
    $link = db_row('SELECT * FROM share_links WHERE token_hash = ?', [share_token_hash($token)]);
    if ($link === null) {
        return ['state' => 'not_found', 'link' => null];
    }
    if ($link['revoked_at'] !== null) {
        return ['state' => 'revoked', 'link' => $link];
    }
    if (db_val("SELECT 1 FROM users WHERE id = ? AND status = 'active'", [$link['user_id']]) === null) {
        return ['state' => 'revoked', 'link' => $link];
    }
    if ($link['kind'] === 'album' && db_val('SELECT 1 FROM albums WHERE id = ? AND user_id = ?', [$link['album_id'], $link['user_id']]) === null) {
        return ['state' => 'revoked', 'link' => $link];
    }
    if ($link['expires_at'] !== null && strtotime($link['expires_at'] . ' UTC') <= time()) {
        return ['state' => 'expired', 'link' => $link];
    }
    return ['state' => 'ok', 'link' => $link];
}

/* ------------------------------------------------------------ sahip işleri */

function share_status(array $l): string
{
    if ($l['revoked_at'] !== null) {
        return 'revoked';
    }
    if ($l['kind'] === 'album' && db_val('SELECT 1 FROM albums WHERE id = ? AND user_id = ?', [$l['album_id'], $l['user_id']]) === null) {
        return 'revoked';
    }
    if ($l['expires_at'] !== null && strtotime($l['expires_at'] . ' UTC') <= time()) {
        return 'expired';
    }
    return 'active';
}

function share_to_json(array $l, ?array &$memo = null): array
{
    // Liste için yalnız sayı ve kapak (tüm öğeleri yüklemeden: büyük albümlerde liste hızlı kalsın).
    $status = share_status($l);
    $count = 0;
    $cover = null;
    if ($status !== 'revoked' || $l['kind'] === 'selection') {
        $mk = $l['kind'] === 'album' ? 'a:' . $l['album_id'] : null; // aynı albümün bağlantıları bir kez sayılır
        if ($mk !== null && $memo !== null && isset($memo[$mk])) {
            [$count, $cover] = $memo[$mk];
        } else {
            $count = share_count($l);
            $cover = $count > 0 ? share_cover_fast($l) : null;
            if ($mk !== null && $memo !== null) {
                $memo[$mk] = [$count, $cover];
            }
        }
    }
    return [
        'id' => (int) $l['id'],
        'kind' => $l['kind'],
        'album_id' => $l['album_id'],
        'title' => $l['title'],
        'count' => $count,
        'cover_asset_id' => $cover,
        'created_at' => iso_utc($l['created_at']),
        'expires_at' => iso_utc($l['expires_at']),
        'has_password' => $l['password_hash'] !== null,
        'allow_download' => (bool) $l['allow_download'],
        'show_location' => (bool) $l['show_location'],
        'views' => (int) $l['views'],
        'last_viewed_at' => iso_utc($l['last_viewed_at']),
        'revoked_at' => iso_utc($l['revoked_at']),
        'status' => $status,
        'url' => null, // belirteç saklanmaz; yalnız oluşturma/yenileme yanıtında
    ];
}

/** Ortak alan doğrulaması: [sütun => değer]. $creating: varsayılanlar uygulanır. */
function share_fields(array $in, bool $creating): array
{
    $f = [];
    if (array_key_exists('title', $in)) {
        $f['title'] = mb_substr(trim((string) $in['title']), 0, 255);
    }
    if (array_key_exists('expires', $in) || $creating) {
        $e = $in['expires'] ?? 'never';
        $e = $e === null || $e === '' ? 'never' : (string) $e;
        if (!array_key_exists($e, SHARE_EXPIRY)) {
            throw new ValidationError('Geçersiz süre (1d, 1w, 1m, never).', 'expires');
        }
        $f['expires_at'] = SHARE_EXPIRY[$e] === null ? null : gmdate('Y-m-d H:i:s', time() + SHARE_EXPIRY[$e]);
    }
    if (array_key_exists('password', $in)) {
        $p = $in['password'];
        if ($p === null || $p === '' || $p === false) {
            $f['password_hash'] = null;
        } else {
            $p = (string) $p;
            if (mb_strlen($p) < SHARE_MIN_PASSWORD || mb_strlen($p) > 200) {
                throw new ValidationError('Parola en az ' . SHARE_MIN_PASSWORD . ' karakter olmalı.', 'password');
            }
            $f['password_hash'] = password_hash($p, PASSWORD_DEFAULT);
        }
    }
    foreach (['allow_download', 'show_location'] as $k) {
        if (array_key_exists($k, $in) || $creating) {
            $f[$k] = api_bool($in[$k] ?? false) ? 1 : 0;
        }
    }
    return $f;
}

/** Yeni bağlantı. Dönüş: ['link' => json, 'token' => düz belirteç, 'url' => tam adres]. */
function share_create(int $userId, array $in): array
{
    $kind = (string) ($in['kind'] ?? '');
    if (!in_array($kind, ['album', 'selection'], true)) {
        throw new ValidationError('Geçersiz paylaşım türü.', 'kind');
    }
    $f = share_fields($in, true);
    $albumId = null;
    $ids = [];
    if ($kind === 'album') {
        $albumId = (string) ($in['album_id'] ?? '');
        if (!is_uuid($albumId)) {
            api_not_found();
        }
        album_require($userId, $albumId, 'own');
        if (($f['title'] ?? '') === '') {
            $f['title'] = (string) db_val('SELECT title FROM albums WHERE id = ?', [$albumId]);
        }
    } else {
        $raw = (array) ($in['ids'] ?? []);
        if (count($raw) > SHARE_MAX_SELECTION) {
            throw new ValidationError('En fazla ' . SHARE_MAX_SELECTION . ' öğe paylaşılabilir.', 'ids');
        }
        $want = array_values(array_unique(array_filter(array_map('strval', array_filter($raw, 'is_string')), 'is_uuid')));
        $own = $want ? array_column(db_all('SELECT id FROM assets WHERE user_id = ? AND trashed_at IS NULL AND id IN (' . db_in($want) . ')', array_merge([$userId], $want)), 'id') : [];
        $ownSet = array_flip($own);
        $ids = array_values(array_filter($want, fn($id) => isset($ownSet[$id])));
        if (!$ids) {
            throw new ValidationError('Paylaşılacak fotoğraf seçin.', 'ids');
        }
        if (($f['title'] ?? '') === '') {
            $f['title'] = count($ids) === 1 ? 'Bir fotoğraf' : count($ids) . ' fotoğraf';
        }
    }
    $token = share_token_new();
    db_exec('INSERT INTO share_links (token_hash, user_id, kind, album_id, title, created_at, expires_at, password_hash, allow_download, show_location)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        share_token_hash($token), $userId, $kind, $albumId, $f['title'], now_utc(), $f['expires_at'], $f['password_hash'] ?? null, $f['allow_download'], $f['show_location'],
    ]);
    $id = (int) db()->lastInsertId();
    foreach ($ids as $i => $aid) {
        db_exec('INSERT IGNORE INTO share_link_assets (link_id, asset_id, sort_order) VALUES (?, ?, ?)', [$id, $aid, $i]);
    }
    audit($userId, 'share.create', "link=$id kind=$kind" . ($albumId ? " album=$albumId" : ' n=' . count($ids)));
    $link = db_row('SELECT * FROM share_links WHERE id = ?', [$id]);
    return ['link' => share_to_json($link), 'token' => $token, 'url' => share_public_url($token)];
}

/** Sahibin bağlantısı; başkasınınki veya yoksa 404. */
function share_own(int $userId, int $id): array
{
    $l = db_row('SELECT * FROM share_links WHERE id = ? AND user_id = ?', [$id, $userId]);
    if ($l === null) {
        api_not_found();
    }
    return $l;
}

function share_update(int $userId, int $id, array $in): array
{
    share_own($userId, $id);
    $f = share_fields($in, false);
    if (isset($f['title']) && $f['title'] === '') {
        unset($f['title']);
    }
    if ($f) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
        db_exec("UPDATE share_links SET $sets WHERE id = ? AND user_id = ?", array_merge(array_values($f), [$id, $userId]));
    }
    return share_to_json(share_own($userId, $id));
}

function share_revoke(int $userId, int $id): array
{
    share_own($userId, $id);
    db_exec('UPDATE share_links SET revoked_at = COALESCE(revoked_at, ?) WHERE id = ? AND user_id = ?', [now_utc(), $id, $userId]);
    audit($userId, 'share.revoke', "link=$id");
    return share_to_json(share_own($userId, $id));
}

/** Yeni belirteç: eski adres hemen çalışmaz olur (parola çerezleri de). */
function share_rotate(int $userId, int $id): array
{
    $l = share_own($userId, $id);
    if ($l['revoked_at'] !== null) {
        throw new ApiError(409, 'revoked', 'İptal edilmiş bağlantı yenilenemez.');
    }
    $token = share_token_new();
    db_exec('UPDATE share_links SET token_hash = ? WHERE id = ? AND user_id = ?', [share_token_hash($token), $id, $userId]);
    audit($userId, 'share.rotate', "link=$id");
    return ['link' => share_to_json(share_own($userId, $id)), 'token' => $token, 'url' => share_public_url($token)];
}

function share_delete(int $userId, int $id): bool
{
    share_own($userId, $id);
    db_exec('DELETE FROM share_link_assets WHERE link_id = ?', [$id]);
    db_exec('DELETE FROM share_links WHERE id = ? AND user_id = ?', [$id, $userId]);
    audit($userId, 'share.delete', "link=$id");
    return true;
}

function share_list(int $userId, array $q = []): array
{
    $where = 'user_id = ?';
    $args = [$userId];
    if (!empty($q['album']) && is_uuid((string) $q['album'])) {
        $where .= ' AND album_id = ?';
        $args[] = (string) $q['album'];
    }
    $memo = [];
    $out = [];
    foreach (db_all("SELECT * FROM share_links WHERE $where ORDER BY created_at DESC, id DESC LIMIT 500", $args) as $l) {
        $out[] = share_to_json($l, $memo);
    }
    return $out;
}

/* -------------------------------------------------------------- API uçları */

function api_shares_list(array $user, array $q): array
{
    return api_ok(['links' => share_list((int) $user['id'], $q), 'public_url' => share_base_url(), 'funnel' => (string) config('shares.public_url', '') !== '']);
}

function api_share_create(array $user, array $b): array
{
    if (!rate_limit('share-create:' . $user['id'], 120, 3600)) {
        throw new ApiError(429, 'rate_limited', t('auth.too_many'));
    }
    return api_ok(share_create((int) $user['id'], $b) + ['funnel' => (string) config('shares.public_url', '') !== ''], 201);
}

/* ---------------------------------------------------------- tarih ve metin */

const SHARE_MONTHS_TR = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

/** "12–18 Ağustos 2025", "28 Temmuz – 3 Ağustos 2025", "Aralık 2024 – Mart 2025". */
function share_date_range(?string $from, ?string $to): string
{
    if ($from === null || $to === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $from, $a) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $to, $b)) {
        return '';
    }
    [$y1, $m1, $d1] = [(int) $a[1], (int) $a[2], (int) $a[3]];
    [$y2, $m2, $d2] = [(int) $b[1], (int) $b[2], (int) $b[3]];
    $mn = fn(int $m) => SHARE_MONTHS_TR[$m - 1] ?? '';
    if ($y1 === $y2 && $m1 === $m2) {
        return ($d1 === $d2 ? "$d1" : "{$d1}–{$d2}") . ' ' . $mn($m1) . " $y1";
    }
    if ($y1 === $y2) {
        return "$d1 " . $mn($m1) . " – $d2 " . $mn($m2) . " $y2";
    }
    return $mn($m1) . " $y1 – " . $mn($m2) . " $y2";
}

/** Asset'in yerel çekim tarihi 'Y-m-d' (taken_at + dengeleme). */
function share_local_day(array $a): ?string
{
    if ($a['taken_at'] === null) {
        return null;
    }
    $ts = (int) strtotime($a['taken_at'] . ' UTC') + (int) ($a['taken_tz_offset'] ?? 0) * 60;
    return gmdate('Y-m-d', $ts);
}

function share_local_time(array $a): string
{
    $ts = (int) strtotime(($a['taken_at'] ?? '1970-01-01 00:00:00') . ' UTC') + (int) ($a['taken_tz_offset'] ?? 0) * 60;
    return gmdate('H:i', $ts);
}

/** "Mehmet Balkay" → "Mehmet". */
function share_first_name(string $name): string
{
    $name = trim($name);
    return $name === '' ? '' : (string) preg_split('/\s+/u', $name)[0];
}

/** İndirme/zip içi güvenli dosya adı. */
function share_safe_filename(string $name, string $fallback = 'dosya'): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = (string) preg_replace('/[\x00-\x1F\x7F]|[\/\\\\:*?"<>|]/u', '_', $name);
    $name = trim($name, " .");
    return $name === '' ? $fallback : mb_substr($name, 0, 180);
}

/* ------------------------------------------------- konum silme (orijinal) */

/** JPEG'ten APP1 (EXIF/XMP) ve APP13 (IPTC) bölümlerini çıkarır; görüntü verisi aynen kalır. */
function share_jpeg_strip(string $jpg): string
{
    if (!str_starts_with($jpg, "\xFF\xD8")) {
        return $jpg;
    }
    $out = "\xFF\xD8";
    $i = 2;
    $n = strlen($jpg);
    while ($i + 4 <= $n) {
        if ($jpg[$i] !== "\xFF") {
            break;
        }
        $marker = ord($jpg[$i + 1]);
        if ($marker === 0xDA || $marker === 0xD9) {
            break; // SOS: kalan veri olduğu gibi
        }
        if ($marker === 0xFF) {
            $i++;
            continue;
        }
        $len = unpack('n', substr($jpg, $i + 2, 2))[1];
        $seg = substr($jpg, $i, 2 + $len);
        if ($marker !== 0xE1 && $marker !== 0xED) {
            $out .= $seg;
        }
        $i += 2 + $len;
    }
    return $out . substr($jpg, $i);
}

/** exiftool ile konum etiketlerini silerek stdout'a yazan komut. */
function share_exiftool_argv(string $bin, string $path): array
{
    return [$bin, '-q', '-q', '-m', '-gps:all=', '-xmp:all=', '-iptc:all=', '-Keys:GPSCoordinates=', '-UserData:GPSCoordinates=', '-ItemList:GPSCoordinates=', '-o', '-', $path];
}

/**
 * Konumu silinmiş fotoğraf akışı açar. Dönüş: [kaynak, kapat(), ilk parça] veya null (araç/biçim yok ya da
 * exiftool dosyayı yazamadı: BMP, bozuk dosya…). İlk parça önceden okunur: boş çıktı (0 baytlık indirme)
 * hiçbir zaman sunulmaz, çağıran metadatasız önizlemeye düşer.
 * exiftool varsa her biçim; yoksa yalnız gerçek JPEG (bellekte, en fazla 96 MB).
 */
function share_photo_clean_open(array $a)
{
    $path = blob_path($a['blob_sha1']);
    if (!is_file($path)) {
        return null;
    }
    $bin = tools_detect()['exiftool'];
    if ($bin && function_exists('proc_open')) {
        $proc = @proc_open(share_exiftool_argv($bin, $path), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (is_resource($proc)) {
            $head = '';
            while ($head === '' && !feof($pipes[1])) {
                $c = fread($pipes[1], 65536);
                if ($c === false) {
                    break;
                }
                $head .= $c;
            }
            if ($head !== '') {
                return [$pipes[1], function () use ($proc, $pipes) {
                    @fclose($pipes[1]);
                    proc_close($proc);
                }, $head];
            }
            @fclose($pipes[1]);
            proc_close($proc);
        }
    }
    $isJpeg = in_array(strtolower((string) $a['mime']), ['image/jpeg', 'image/jpg'], true);
    if ($isJpeg && filesize($path) <= 96 * 1024 * 1024) {
        $raw = (string) file_get_contents($path);
        if (!str_starts_with($raw, "\xFF\xD8")) {
            return null; // JPEG değil (yanlış etiketli HEIC vb.): ham bayt asla sunulmaz
        }
        $f = fopen('php://memory', 'w+b');
        fwrite($f, share_jpeg_strip($raw));
        unset($raw);
        rewind($f);
        return [$f, fn() => fclose($f), ''];
    }
    return null;
}

/* ----------------------------------------------------- video (metadatasız) */

/** Tarayıcıda doğrudan oynatılabilen video türleri. */
function share_video_playable(array $a): bool
{
    return in_array(strtolower((string) $a['mime']), ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v'], true);
}

/**
 * Konum (ve tüm genel metadata) silinmiş video kopyası; önbelleklenir: derived/share-web|share-orig.
 * $from: 'web' (oynatma: H.264 kopyası varsa o) | 'orig' (indirme). Başarısızsa null. Orijinale dokunmaz.
 */
function share_video_clean(array $a, string $from): ?string
{
    $orig = blob_path($a['blob_sha1']);
    $src = $from === 'web' ? (derived_find_video($a['id']) ?? (share_video_playable($a) ? $orig : null)) : $orig;
    if ($src === null || !is_file($src)) {
        return null;
    }
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $fmt = in_array($ext, ['mov', 'qt'], true) ? 'mov' : 'mp4';
    $dest = derived_path($a['id'], $from === 'web' ? 'share-web' : 'share-orig', $fmt === 'mov' ? 'mov' : 'mp4');
    $fresh = fn() => is_file($dest) && filesize($dest) > 0 && filemtime($dest) >= filemtime($src);
    if ($fresh()) {
        return $dest;
    }
    @mkdir(dirname($dest), 0750, true);
    $lock = @fopen($dest . '.lock', 'c');
    if ($lock !== false) {
        flock($lock, LOCK_EX);
    }
    try {
        if ($fresh()) {
            return $dest;
        }
        $tmp = $dest . '.tmp-' . bin2hex(random_bytes(4)) . '.' . $fmt;
        $t = tools_detect();
        $ok = false;
        if ($t['ffmpeg']) {
            $created = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime(($a['taken_at'] ?? now_utc()) . ' UTC'));
            $r = tool_run([$t['ffmpeg'], '-v', 'error', '-y', '-i', $src, '-map', '0:v?', '-map', '0:a?', '-c', 'copy',
                '-map_metadata', '-1', '-map_metadata:s:v', '-1', '-map_metadata:s:a', '-1', '-map_chapters', '-1',
                '-metadata', 'creation_time=' . $created, '-movflags', '+faststart', '-f', $fmt, $tmp], 1800);
            $ok = $r['code'] === 0 && is_file($tmp) && filesize($tmp) > 0;
        }
        if (!$ok && $t['exiftool']) {
            @unlink($tmp);
            $argv = share_exiftool_argv($t['exiftool'], $src);
            array_splice($argv, -3, 3, ['-o', $tmp, $src]);
            $r = tool_run($argv, 1800);
            $ok = is_file($tmp) && filesize($tmp) > 0;
        }
        if (!$ok) {
            @unlink($tmp);
            app_log('share', 'video metadatası silinemedi: ' . $a['id']);
            return null;
        }
        rename($tmp, $dest);
        return $dest;
    } finally {
        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($dest . '.lock');
        }
    }
}

/** Asset kalıcı silinince paylaşım önbelleği de silinir (storage.php derived_delete'e ek). */
function share_derived_delete(string $assetId): void
{
    foreach (['share-web' => ['mp4', 'mov'], 'share-orig' => ['mp4', 'mov'], 'og' => ['jpg']] as $v => $exts) {
        foreach ($exts as $e) {
            @unlink(derived_path($assetId, $v, $e));
        }
    }
}

/* ------------------------------------------------------------ zip akışı */

/**
 * Geçici dosya olmadan zip yazar (sıkıştırmasız, veri tanımlayıcılı; gerekince ZIP64).
 * $entries: [['name', 'mtime', 'open' => fn(): resource|[resource, close]|null, 'size_hint' => ?int]]
 * $write: fn(string $chunk).
 */
function share_zip_write(iterable $entries, callable $write): void
{
    $offset = 0;
    $central = [];
    $emit = function (string $s) use (&$offset, $write) {
        $write($s);
        $offset += strlen($s);
    };
    $dos = function (int $ts): array {
        $d = getdate(max($ts, 315532800)); // 1980 öncesi DOS'ta yok
        return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    };
    foreach ($entries as $e) {
        $opened = ($e['open'])();
        if ($opened === null || $opened === false) {
            continue;
        }
        [$fh, $close, $head] = is_array($opened) ? $opened + [2 => ''] : [$opened, fn() => fclose($opened), ''];
        $name = (string) $e['name'];
        [$time, $date] = $dos((int) ($e['mtime'] ?? time()));
        $start = $offset;
        $z64 = $start >= 0xFFFFFFFF || (int) ($e['size_hint'] ?? 0) >= 0xF0000000;
        $flags = 0x0008 | 0x0800; // veri tanımlayıcı + UTF-8 ad
        $ver = $z64 ? 45 : 20;
        $extra = $z64 ? pack('vv', 0x0001, 16) . pack('PP', 0, 0) : '';
        $emit(pack('VvvvvvVVVvv', 0x04034b50, $ver, $flags, 0, $time, $date, 0, $z64 ? 0xFFFFFFFF : 0, $z64 ? 0xFFFFFFFF : 0, strlen($name), strlen($extra)) . $name . $extra);
        $crc = hash_init('crc32b');
        $size = 0;
        if ($head !== '') {
            hash_update($crc, $head);
            $size += strlen($head);
            $emit($head);
        }
        while (!feof($fh)) {
            $buf = fread($fh, 1048576);
            if ($buf === false || $buf === '') {
                if (feof($fh)) {
                    break;
                }
                continue;
            }
            hash_update($crc, $buf);
            $size += strlen($buf);
            $emit($buf);
        }
        $close();
        $crcVal = unpack('N', hash_final($crc, true))[1];
        if (!$z64 && $size >= 0xFFFFFFFF) {
            throw new RuntimeException('zip: beklenmedik büyük dosya');
        }
        $emit($z64 ? pack('VVPP', 0x08074b50, $crcVal, $size, $size) : pack('VVVV', 0x08074b50, $crcVal, $size, $size));
        $central[] = compact('name', 'time', 'date', 'crcVal', 'size', 'start', 'z64', 'flags', 'ver');
    }
    $cdStart = $offset;
    foreach ($central as $c) {
        $extra = $c['z64'] ? pack('vv', 0x0001, 24) . pack('PPP', $c['size'], $c['size'], $c['start']) : '';
        $emit(pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0300 | $c['ver'], $c['ver'], $c['flags'], 0, $c['time'], $c['date'], $c['crcVal'],
            $c['z64'] ? 0xFFFFFFFF : $c['size'], $c['z64'] ? 0xFFFFFFFF : $c['size'], strlen($c['name']), strlen($extra), 0, 0, 0, 0100644 << 16,
            $c['z64'] ? 0xFFFFFFFF : $c['start']) . $c['name'] . $extra);
    }
    $cdSize = $offset - $cdStart;
    $n = count($central);
    if ($n >= 0xFFFF || $cdStart >= 0xFFFFFFFF || $cdSize >= 0xFFFFFFFF) {
        $z64eocd = $offset;
        $emit(pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $n, $n, $cdSize, $cdStart));
        $emit(pack('VVPV', 0x07064b50, 0, $z64eocd, 1));
        $emit(pack('VvvvvVVv', 0x06054b50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0));
        return;
    }
    $emit(pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, $cdSize, $cdStart, 0));
}
