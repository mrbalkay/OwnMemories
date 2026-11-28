<?php
/**
 * OwnMemories — fotoğraftaki yazı (OCR) ile arama.
 *
 * Yazıyı ownmemories-ml dizinleyicisi okur (ml/ownmemories_ml/ocr.py; PP-OCRv6 algılama + tanıma, Türkçe + İngilizce)
 * ve asset_text tablosuna yazar (migration 011). Burada:
 *   - ocr_fold(): arama katlaması (Python ocr.fold() ile birebir; ortak örnekler tests/fixtures/ocr/fold.json)
 *   - ocr_search(): GET /v1/search'ün yazı eşleşmeleri (sözcük başı / tam sözcük / ≥4 harf sözcük içi)
 *   - ocr_snippet(): sonuçta gösterilecek satır ve vurgulanacak sözcükler ("“fatura” yazısı bulundu")
 *   - GET /v1/assets/{id}/text: görüntüleyicinin "Fotoğraftaki yazı" paneli
 *   - ocr_progress(): yönetim paneli ve /v1/search/status ilerlemesi
 * asset_text yoksa (migration uygulanmadıysa) her şey sessizce boş döner.
 */
declare(strict_types=1);

const OCR_SEARCH_LIMIT = 2000;  // aday satır sınırı (kullanıcı başına yazılı fotoğraflarda LIKE taraması)
const OCR_MAX_TOKENS = 8;
const OCR_SNIPPET_MAX = 90;

/** asset_text tablosu yoksa (1146) true; diğer hatalar yeniden fırlatılır. */
function ocr_missing_table(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? 0) === 1146;
}

/** Türkçe duyarlı katlama: "İSTANBUL" = "ISTANBUL" = "ıstanbul" → "istanbul"; yalnız [a-z0-9] sözcükler. */
function ocr_fold(string $s): string
{
    return places_fold($s);
}

/** Sorgu sözcükleri (katlanmış, tekrarsız, en çok OCR_MAX_TOKENS). */
function ocr_query_tokens(string $q): array
{
    $f = ocr_fold(mb_substr($q, 0, 200));
    if ($f === '') {
        return [];
    }
    return array_slice(array_values(array_unique(explode(' ', $f))), 0, OCR_MAX_TOKENS);
}

/** Tek sözcüğün katlanmış metindeki eşleşmesi: 3 tam sözcük, 2 sözcük başı, 1 sözcük içi (≥4 harf), 0 yok. */
function ocr_token_score(string $folded, string $tok): int
{
    if ($tok === '') {
        return 0;
    }
    if (str_contains($folded, " $tok ")) {
        return 3;
    }
    if (strlen($tok) >= 2 && str_contains($folded, " $tok")) {
        // iki harfli sözcük başları ("su" → "sunum") çok gürültülü: yalnız tam sözcük
        return strlen($tok) >= 3 ? 2 : 0;
    }
    return strlen($tok) >= 4 && str_contains($folded, $tok) ? 1 : 0;
}

/** Tüm sözcükler eşleşmeli; puan en zayıf sözcüğünkü. $folded başta/sonda boşluklu olmalı. */
function ocr_match_score(string $folded, array $tokens): int
{
    if (!$tokens) {
        return 0;
    }
    $min = 3;
    foreach ($tokens as $t) {
        $min = min($min, ocr_token_score($folded, (string) $t));
        if ($min === 0) {
            return 0;
        }
    }
    return $min;
}

/** LIKE koşulu (kaba süzgeç; kesin puan PHP'de): uzun sözcükler her yerde, kısalar sözcük başında. */
function ocr_like(string $tok): string
{
    $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $tok);
    if (strlen($tok) >= 4) {
        return "%$esc%";
    }
    return strlen($tok) === 3 ? "% $esc%" : "% $esc %";
}

/** Sonuçta gösterilecek satır: en çok sözcüğün eşleştiği satır; eşleşen özgün sözcükler 'm' => true. */
function ocr_snippet(string $text, array $tokens): array
{
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn($l) => $l !== ''));
    if (!$lines) {
        return ['snippet' => '', 'parts' => []];
    }
    $match = function (string $word) use ($tokens): bool {
        $w = ocr_fold($word);
        if ($w === '') {
            return false;
        }
        foreach (explode(' ', $w) as $part) {
            foreach ($tokens as $t) {
                if ($part === $t || (strlen($t) >= 3 && str_starts_with($part, $t)) || (strlen($t) >= 4 && str_contains($part, $t))) {
                    return true;
                }
            }
        }
        return false;
    };
    $best = 0;
    $bestHits = -1;
    foreach ($lines as $i => $l) {
        $hits = 0;
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $l, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $hits += $match($w) ? 1 : 0;
        }
        if ($hits > $bestHits) {
            [$best, $bestHits] = [$i, $hits];
        }
    }
    $line = $lines[$best];
    $segs = preg_split('/([\p{L}\p{N}]+)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    // Uzun satır: ilk eşleşmenin çevresinden bir pencere.
    if (mb_strlen($line) > OCR_SNIPPET_MAX) {
        $first = 0;
        $pos = 0;
        foreach ($segs as $s) {
            if (preg_match('/^[\p{L}\p{N}]+$/u', $s) && $match($s)) {
                $first = $pos;
                break;
            }
            $pos += mb_strlen($s);
        }
        $start = max(0, $first - 30);
        $win = mb_substr($line, $start, OCR_SNIPPET_MAX - 2);
        if ($start > 0) {
            $win = '…' . (string) preg_replace('/^[\p{L}\p{N}]*[^\p{L}\p{N}]+/u', '', $win, 1);
        }
        if ($start + OCR_SNIPPET_MAX - 2 < mb_strlen($line)) {
            $win = (string) preg_replace('/[^\p{L}\p{N}]+[\p{L}\p{N}]*$/u', '', $win) . '…';
        }
        $line = $win;
        $segs = preg_split('/([\p{L}\p{N}]+)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $parts = [];
    foreach ($segs as $s) {
        $m = preg_match('/^[\p{L}\p{N}]+$/u', $s) === 1 && $match($s);
        $n = count($parts);
        if ($n && $parts[$n - 1]['m'] === $m && !$m) {
            $parts[$n - 1]['t'] .= $s;
        } else {
            $parts[] = ['t' => $s, 'm' => $m];
        }
    }
    return ['snippet' => $line, 'parts' => $parts];
}

/** Dizinleyicinin yaptığı yazmanın PHP karşılığı (testler, bakım). */
function ocr_store(string $assetId, int $userId, string $text, ?string $lang, string $engine): void
{
    $text = trim($text);
    $f = ocr_fold($text);
    $words = $f === '' ? 0 : count(explode(' ', $f));
    db_exec('INSERT INTO asset_text (asset_id, user_id, text, folded, lang, words, engine, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), text = VALUES(text), folded = VALUES(folded), lang = VALUES(lang),
        words = VALUES(words), engine = VALUES(engine), computed_at = VALUES(computed_at)',
        [$assetId, $userId, $text, $f === '' ? '' : " $f ", $text === '' ? null : $lang, $words, $engine, now_utc()]);
}

/** InnoDB'nin varsayılan FULLTEXT durak sözcükleri (≥ 3 harfli olanlar): bunlarla LIKE kullanılır. */
const OCR_FT_STOPWORDS = ['about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];
const OCR_FT_ENOUGH = 50; // sözcük başı eşleşme bu kadarsa sözcük içi LIKE taraması yapılmaz

/**
 * Yazı eşleşmeleri: [id => ['score' => 1..3, 'row' => assets satırı, 'text' => tanınan yazı]], puana sonra tarihe
 * göre sıralı (yeni → eski). $base: api_asset_filters süzgeçleri (tarih, favori, tür…).
 * Sözcük başı eşleşmeler FULLTEXT dizininden (hızlı), sözcük içi (≥ 4 harf) ve kısa sözcükler LIKE taramasıyla.
 */
function ocr_search(array $user, array $base, string $q): array
{
    $tokens = ocr_query_tokens($q);
    if (!$tokens) {
        return [];
    }
    [$where, $args] = api_asset_filters($user, $base);
    $sql = "SELECT a.*, t.text AS ocr_text, t.folded AS ocr_folded FROM asset_text t JOIN assets a ON a.id = t.asset_id
        WHERE t.user_id = ? AND t.words > 0 AND %s AND $where ORDER BY a.taken_at DESC, a.id DESC LIMIT " . OCR_SEARCH_LIMIT;
    $uid = (int) $user['id'];
    $rows = null;
    $ftOk = !array_filter($tokens, fn($t) => strlen($t) < 3 || in_array($t, OCR_FT_STOPWORDS, true));
    try {
        if ($ftOk) {
            try {
                $boolean = implode(' ', array_map(fn($t) => "+$t*", $tokens));
                $rows = db_all(sprintf($sql, 'MATCH(t.folded) AGAINST(? IN BOOLEAN MODE)'), array_merge([$uid, $boolean], $args));
            } catch (PDOException $e) {
                if (ocr_missing_table($e) || ($e->errorInfo[1] ?? 0) !== 1191) { // 1191: FULLTEXT dizini yok
                    throw $e;
                }
                $rows = null;
            }
        }
        $wantInner = (bool) array_filter($tokens, fn($t) => strlen($t) >= 4);
        if ($rows === null || ($wantInner && count($rows) < OCR_FT_ENOUGH)) {
            $conds = implode(' AND ', array_fill(0, count($tokens), 't.folded LIKE ?'));
            $like = db_all(sprintf($sql, $conds), array_merge([$uid], array_map('ocr_like', $tokens), $args));
            $rows = array_values(array_column($rows ?? [], null, 'id') + array_column($like, null, 'id'));
        }
    } catch (PDOException $e) {
        if (ocr_missing_table($e)) {
            return [];
        }
        throw $e;
    }
    $out = [];
    foreach ($rows as $r) {
        $score = ocr_match_score((string) $r['ocr_folded'], $tokens);
        if ($score === 0) {
            continue;
        }
        $text = (string) $r['ocr_text'];
        unset($r['ocr_text'], $r['ocr_folded']);
        $out[$r['id']] = ['score' => $score, 'row' => $r, 'text' => $text];
    }
    // puan azalan; eşitlikte yeni → eski
    uasort($out, fn($x, $y) => [$y['score'], $y['row']['taken_at'], $y['row']['id']] <=> [$x['score'], $x['row']['taken_at'], $x['row']['id']]);
    return $out;
}

/** Arama sonucuna eklenen bilgi: {"term": sorgu (kullanıcının yazdığı), "snippet", "parts"}. */
function ocr_text_match(string $q, string $text): array
{
    $s = ocr_snippet($text, ocr_query_tokens($q));
    return ['term' => trim(mb_substr($q, 0, 60)), 'snippet' => $s['snippet'], 'parts' => $s['parts']];
}

/** GET /v1/assets/{id}/text */
function api_asset_text(array $user, string $id): array
{
    if (asset_access((int) $user['id'], $id) === null) {
        api_not_found();
    }
    try {
        $r = db_row('SELECT text, lang, words, engine, computed_at FROM asset_text WHERE asset_id = ?', [$id]);
    } catch (PDOException $e) {
        if (ocr_missing_table($e)) {
            return api_ok(['status' => 'unavailable', 'text' => '', 'lines' => [], 'words' => 0, 'lang' => null, 'computed_at' => null]);
        }
        throw $e;
    }
    if ($r === null) {
        return api_ok(['status' => 'pending', 'text' => '', 'lines' => [], 'words' => 0, 'lang' => null, 'computed_at' => null]);
    }
    $text = (string) $r['text'];
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn($l) => $l !== ''));
    return api_ok([
        'status' => $text === '' ? 'none' : 'done',
        'text' => $text,
        'lines' => $lines,
        'words' => (int) $r['words'],
        'lang' => $r['lang'],
        'engine' => $r['engine'],
        'computed_at' => iso_utc($r['computed_at']),
    ]);
}

/** İlerlemede sayılan küme: çöpte olmayan fotoğraflar (dizinleyici küçük resmi hazır olanları okur). */
const OCR_SCANNABLE = "a.type = 'photo' AND a.trashed_at IS NULL";

/**
 * İlerleme: ['all' => [total, scanned, with_text], 'users' => [[id, name, email, total, scanned, with_text], ...]]
 * Tablo yoksa scanned/with_text 0.
 */
function ocr_progress(?int $userId = null): array
{
    $cond = OCR_SCANNABLE . ($userId !== null ? ' AND a.user_id = ' . (int) $userId : '');
    try {
        $rows = db_all("SELECT a.user_id, COUNT(*) AS total, COUNT(t.asset_id) AS scanned, SUM(t.words > 0) AS with_text
            FROM assets a LEFT JOIN asset_text t ON t.asset_id = a.id WHERE $cond GROUP BY a.user_id");
    } catch (PDOException $e) {
        if (!ocr_missing_table($e)) {
            throw $e;
        }
        $rows = db_all("SELECT a.user_id, COUNT(*) AS total, 0 AS scanned, 0 AS with_text FROM assets a WHERE $cond GROUP BY a.user_id");
    }
    $by = array_column($rows, null, 'user_id');
    $all = ['total' => 0, 'scanned' => 0, 'with_text' => 0];
    $users = [];
    $list = $userId !== null ? db_all('SELECT id, name, email FROM users WHERE id = ?', [$userId]) : db_all('SELECT id, name, email FROM users ORDER BY id');
    foreach ($list as $u) {
        $r = $by[$u['id']] ?? ['total' => 0, 'scanned' => 0, 'with_text' => 0];
        $row = ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'total' => (int) $r['total'],
            'scanned' => min((int) $r['total'], (int) $r['scanned']), 'with_text' => (int) $r['with_text']];
        foreach (['total', 'scanned', 'with_text'] as $k) {
            $all[$k] += $row[$k];
        }
        $users[] = $row;
    }
    return ['all' => $all, 'users' => $users];
}

/** Kalıcı silmede yazı satırını siler (tablo yoksa sessizce geçer). */
function ocr_purge_asset(string $id): void
{
    try {
        db_exec('DELETE FROM asset_text WHERE asset_id = ?', [$id]);
    } catch (PDOException $e) {
        if (!ocr_missing_table($e)) {
            throw $e;
        }
    }
}
