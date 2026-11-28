<?php
/**
 * OwnMemories — akıllı arama (GET /v1/search, /v1/search/status).
 *
 * Doğal dil sorguları ("kumsalda köpek") yerel ML hizmetine (ownmemories-ml, varsayılan
 * http://127.0.0.1:8765; yapılandırma: config ml.url / ml.timeout / ml.min_score) gider; hizmet
 * kullanıcının vektörleriyle sıralı asset kimlikleri döner. Kimlikler burada zaman çizelgesinin
 * görünürlük kurallarıyla (yalnızca kendi kütüphanesi, çöp/arşiv hariç, Live Photo videosu ve
 * düzenlenmiş orijinal gizli) süzülür, sıra korunur. Dosya adı/açıklama/kamera/kişi eşleşmeleri
 * öne alınır (hibrit). Hizmete ulaşılamazsa ya da sonuç yoksa metin aramasına düşülür.
 */
declare(strict_types=1);

const SEARCH_ML_LIMIT = 500;   // ML'den istenen en fazla sonuç
const SEARCH_TEXT_BOOST = 200; // öne alınan metin eşleşmesi sınırı

/**
 * Test dikişi: ML isteklerini yanıtlayan işlev fn(string $method, string $path, array $payload): ?array.
 * null verilirse gerçek HTTP istemcisi kullanılır.
 */
function ml_client_override(?callable $fn = null, bool $set = true): ?callable
{
    static $override = null;
    if ($set) {
        $override = $fn;
    }
    return $override;
}

function ml_url(): string
{
    return rtrim((string) config('ml.url', 'http://127.0.0.1:8765'), '/');
}

/**
 * ML hizmetine JSON isteği. Hata, zaman aşımı, 2xx dışı yanıt ya da bozuk JSON → null.
 * GET'te $payload sorgu dizesi olur.
 */
function ml_request(string $method, string $path, array $payload = []): ?array
{
    $fn = ml_client_override(null, false);
    if ($fn !== null) {
        return $fn($method, $path, $payload);
    }
    if (defined('OM_TESTING')) {
        return null; // testler asla gerçek ağa çıkmaz
    }
    $timeout = (float) config('ml.timeout', 3.0);
    $url = ml_url() . $path;
    $body = null;
    if ($method === 'GET') {
        if ($payload) {
            $url .= '?' . http_build_query($payload);
        }
    } else {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    $raw = null;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(1000, $timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_PROXY => '', // yerel hizmet: ortamdaki vekil sunucuyu kullanma
            CURLOPT_NOPROXY => '*',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $raw = is_string($res) ? $res : null;
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'timeout' => $timeout, 'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $body ?? '',
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        $raw = is_string($res) ? $res : null;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    if ($raw === null || $code < 200 || $code >= 300) {
        return null;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

/**
 * Kullanıcının vektörlerinde metin araması: [['id' => uuid, 'score' => float], ...] (puana göre azalan)
 * ya da hizmet kullanılamıyorsa null.
 */
function ml_search(int $userId, string $text, int $limit = SEARCH_ML_LIMIT): ?array
{
    $minScore = config('ml.min_score');
    $r = ml_request('POST', '/search', [
        'user_id' => $userId, 'text' => $text, 'limit' => max(1, min(SEARCH_ML_LIMIT, $limit)),
        'min_score' => is_numeric($minScore) ? (float) $minScore : null,
    ]);
    if ($r === null || !isset($r['results']) || !is_array($r['results'])) {
        return null;
    }
    $out = [];
    foreach ($r['results'] as $row) {
        if (is_array($row) && is_string($row['id'] ?? null) && is_uuid($row['id'])) {
            $out[] = ['id' => $row['id'], 'score' => (float) ($row['score'] ?? 0)];
        }
    }
    return $out;
}

/** Dizin durumu {indexed,total,pending,model,ready} ya da null. */
function ml_status(int $userId): ?array
{
    $r = ml_request('GET', '/status', ['user_id' => $userId]);
    if ($r === null || !array_key_exists('indexed', $r)) {
        return null;
    }
    return [
        'indexed' => (int) $r['indexed'],
        'total' => (int) ($r['total'] ?? 0),
        'pending' => (int) ($r['pending'] ?? 0),
        'model' => isset($r['model']) ? (string) $r['model'] : null,
        'ready' => (bool) ($r['ready'] ?? false),
    ];
}

/** Hizmet sağlığı (/health) ya da null — yönetim paneli için. */
function ml_health(): ?array
{
    $r = ml_request('GET', '/health');
    return $r !== null && isset($r['ok']) ? $r : null;
}

/** Arama dışı süzgeçler (tarih, favori, tür): api_asset_filters'a giden sorgu. */
function search_base_query(array $q): array
{
    $base = [];
    foreach (['from', 'to', 'favorite', 'type'] as $k) {
        if (isset($q[$k]) && is_string($q[$k]) && $q[$k] !== '') {
            $base[$k] = $q[$k];
        }
    }
    return $base;
}

function api_search(array $user, array $q): array
{
    $uid = (int) $user['id'];
    $text = mb_substr(trim((string) ($q['q'] ?? '')), 0, 200);
    $limit = max(1, min(200, (int) ($q['limit'] ?? 60)));
    $offset = max(0, (int) ($q['offset'] ?? 0));
    $byDate = ($q['sort'] ?? '') === 'date';
    $status = $offset === 0 ? ml_status($uid) : null;
    if ($text === '') {
        return api_ok(['mode' => 'text', 'assets' => [], 'next_offset' => null, 'total' => 0, 'status' => $status]);
    }
    $base = search_base_query($q);
    $ranked = ml_search($uid, $text, SEARCH_ML_LIMIT);
    if ($ranked) {
        [$where, $args] = api_asset_filters($user, $base);
        $ids = array_values(array_unique(array_column($ranked, 'id')));
        $rows = db_all("SELECT a.* FROM assets a WHERE $where AND a.id IN (" . db_in($ids) . ')', array_merge($args, $ids));
        $byId = [];
        foreach ($rows as $r) {
            $byId[$r['id']] = $r;
        }
        // Metin eşleşmeleri (dosya adı, açıklama, kamera, kişi) öne: akıllı sırada olanlar önce, kalanlar tarihe göre.
        [$tw, $ta] = api_asset_filters($user, $base + ['q' => $text]);
        $textRows = db_all("SELECT a.* FROM assets a WHERE $tw ORDER BY a.taken_at DESC, a.id DESC LIMIT " . SEARCH_TEXT_BOOST, $ta);
        $textIds = [];
        foreach ($textRows as $r) {
            $textIds[$r['id']] = true;
            $byId[$r['id']] ??= $r;
        }
        $order = [];
        foreach ($ids as $id) {
            if (isset($byId[$id], $textIds[$id])) {
                $order[$id] = true;
            }
        }
        foreach ($textRows as $r) {
            $order[$r['id']] = true;
        }
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $order[$id] = true;
            }
        }
        $list = array_map(fn($id) => $byId[$id], array_keys($order));
        if ($list) {
            if ($byDate) {
                usort($list, fn($x, $y) => [$y['taken_at'], $y['id']] <=> [$x['taken_at'], $x['id']]);
            }
            $page = array_slice($list, $offset, $limit);
            $next = $offset + $limit < count($list) ? $offset + $limit : null;
            return api_ok(['mode' => 'smart', 'assets' => array_map('asset_to_json', $page), 'next_offset' => $next, 'total' => count($list), 'status' => $status]);
        }
    }
    // Metin araması (yedek): tarihe göre yeniden eskiye.
    [$where, $args] = api_asset_filters($user, $base + ['q' => $text]);
    $rows = db_all("SELECT a.* FROM assets a WHERE $where ORDER BY a.taken_at DESC, a.id DESC LIMIT $offset, " . ($limit + 1), $args);
    $next = null;
    if (count($rows) > $limit) {
        array_pop($rows);
        $next = $offset + $limit;
    }
    $total = $offset === 0 && $next === null ? count($rows) : (int) db_val("SELECT COUNT(*) FROM assets a WHERE $where", $args);
    return api_ok(['mode' => 'text', 'assets' => array_map('asset_to_json', $rows), 'next_offset' => $next, 'total' => $total, 'status' => $status]);
}

function api_search_status(array $user): array
{
    $s = ml_status((int) $user['id']);
    return api_ok($s === null ? ['available' => false] : ['available' => true] + $s);
}

/** Yönetim paneli: kullanıcı başına dizinlenmiş öğe sayısı (asset_embeddings yoksa boş). */
function search_index_counts(?string $model = null): array
{
    try {
        $sql = 'SELECT user_id, COUNT(*) AS n FROM asset_embeddings' . ($model !== null ? ' WHERE model = ?' : '') . ' GROUP BY user_id';
        $out = [];
        foreach (db_all($sql, $model !== null ? [$model] : []) as $r) {
            $out[(int) $r['user_id']] = (int) $r['n'];
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Yönetim paneli özeti: hizmet sağlığı (/health) ve kullanıcı başına dizin ilerlemesi.
 * ['health' => ?array, 'users' => [['id','name','email','total','indexed'], ...]]
 */
function admin_smart_search(): array
{
    $health = ml_health();
    $model = isset($health['model']) ? (string) $health['model'] : null;
    $counts = search_index_counts($model);
    $users = [];
    foreach (db_all("SELECT u.id, u.name, u.email, (SELECT COUNT(*) FROM assets a WHERE a.user_id = u.id) AS total FROM users u ORDER BY u.id") as $u) {
        $users[] = ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'total' => (int) $u['total'], 'indexed' => min((int) $u['total'], $counts[(int) $u['id']] ?? 0)];
    }
    return ['health' => $health, 'users' => $users];
}
