<?php
/**
 * OwnMemories yedek durum dosyası yardımcısı (yalnızca CLI; backup.sh / restore.sh / setup-backup.sh çağırır).
 *
 *   php backup-status.php FILE configure            < {"targets":[{id,type,label,location,upload_limit_kib}], schedule, timezone, include_derived, retention}
 *   php backup-status.php FILE start TRIGGER PID
 *   php backup-status.php FILE backup-result ID ok|failed DURATION_S [RESTIC_JSON_FILE]   < hata metni (stdin)
 *   php backup-status.php FILE stats ID SNAPSHOTS_JSON_FILE STATS_JSON_FILE
 *   php backup-status.php FILE check ID ok|failed   < hata metni (stdin)
 *   php backup-status.php FILE finish ok|failed     < {"db_dump_bytes":…, "source_bytes":…, "source_files":…}
 *   php backup-status.php FILE restore-test         < {"ok":true, "target":"disk", "files_checked":30, "db_ok":true, "message":"…"}
 *   php backup-status.php FILE get KEY [ID]         (KEY: üst düzey alan veya ID verilirse hedef alanı)
 *   php backup-status.php FILE summary RESTIC_JSON_FILE   (restic backup --json özetini okunur satıra çevirir)
 *
 * Dosya atomik yazılır; root çalıştırıyorsa sahibi root:www-data, izni 0640 olur. Gizli bilgi bu dosyaya yazılmaz.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc < 3) {
    fwrite(STDERR, "kullanım: php backup-status.php FILE KOMUT …\n");
    exit(2);
}
$file = $argv[1];
$cmd = $argv[2];
$args = array_slice($argv, 3);

function iso(?int $t = null): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $t ?? time());
}

function stdin_text(): string
{
    if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
        return '';
    }
    $s = stream_get_contents(STDIN);
    return is_string($s) ? $s : '';
}

function stdin_json(): array
{
    $d = json_decode(stdin_text(), true);
    return is_array($d) ? $d : [];
}

/** Ortamdaki gizli değerleri metinden siler (ikinci savunma hattı; asıl maskeleme backup-common.sh'de). */
function scrub(string $s): string
{
    foreach (getenv() as $k => $v) {
        if (is_string($v) && strlen($v) >= 6 && preg_match('/(PASSWORD|SECRET|_KEY|KEY_ID|TOKEN)/', (string) $k)) {
            $s = str_replace($v, '***', $s);
        }
    }
    $s = (string) preg_replace('#(https?://)[^/@\s]+@#', '$1', $s);
    return mb_substr(trim($s), 0, 1200);
}

function load(string $file): array
{
    $raw = is_file($file) ? @file_get_contents($file) : false;
    $d = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($d) ? $d : ['version' => 1, 'targets' => []];
}

function save(string $file, array $d): void
{
    $d['version'] = 1;
    $d['updated_at'] = iso();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $tmp = $file . '.tmp-' . getmypid();
    file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    chmod($tmp, 0640);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $group = getenv('OM_WEB_GROUP') ?: 'www-data';
        if (function_exists('posix_getgrnam') && posix_getgrnam($group) !== false) {
            @chgrp($tmp, $group);
        }
    }
    rename($tmp, $file);
}

function &target(array &$d, string $id): array
{
    foreach ($d['targets'] as $i => $t) {
        if (($t['id'] ?? null) === $id) {
            return $d['targets'][$i];
        }
    }
    $d['targets'][] = ['id' => $id, 'last_status' => 'never'];
    return $d['targets'][array_key_last($d['targets'])];
}

/** restic backup --json çıktısındaki son "summary" satırı. */
function restic_summary(?string $f): ?array
{
    if ($f === null || !is_file($f)) {
        return null;
    }
    $found = null;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_contains($line, '"summary"')) {
            $j = json_decode($line, true);
            if (is_array($j) && ($j['message_type'] ?? '') === 'summary') {
                $found = $j;
            }
        }
    }
    return $found;
}

$lock = fopen($file . '.lock', 'c');
if ($lock !== false) {
    flock($lock, LOCK_EX);
}
$d = load($file);
$d['targets'] = array_values(array_filter((array) ($d['targets'] ?? []), 'is_array'));

switch ($cmd) {
    case 'configure':
        $in = stdin_json();
        $old = [];
        foreach ($d['targets'] as $t) {
            $old[$t['id']] = $t;
        }
        $targets = [];
        foreach ((array) ($in['targets'] ?? []) as $t) {
            $id = (string) $t['id'];
            $keep = $old[$id] ?? ['last_status' => 'never'];
            // Konum değiştiyse eski geçmiş başka bir depoya aittir.
            if (isset($keep['location']) && $keep['location'] !== ($t['location'] ?? null)) {
                $keep = ['last_status' => 'never'];
            }
            $targets[] = array_merge($keep, [
                'id' => $id, 'type' => (string) ($t['type'] ?? 'local'), 'label' => (string) ($t['label'] ?? $id),
                'location' => (string) ($t['location'] ?? ''), 'upload_limit_kib' => isset($t['upload_limit_kib']) && $t['upload_limit_kib'] !== '' ? (int) $t['upload_limit_kib'] : null,
            ]);
        }
        $d['targets'] = $targets;
        $d['configured'] = true;
        $d['configured_at'] ??= iso();
        foreach (['schedule', 'timezone', 'include_derived', 'retention'] as $k) {
            if (array_key_exists($k, $in)) {
                $d[$k] = $in[$k];
            }
        }
        break;

    case 'start':
        $d['running'] = ['started_at' => iso(), 'trigger' => $args[0] ?? 'scheduled', 'pid' => (int) ($args[1] ?? 0)];
        break;

    case 'backup-result':
        [$id, $result, $duration] = [$args[0], $args[1], (int) ($args[2] ?? 0)];
        $t = &target($d, $id);
        $now = iso();
        $t['last_attempt_at'] = $now;
        $t['duration_s'] = $duration;
        $t['last_status'] = $result === 'ok' ? 'ok' : 'failed';
        if ($result === 'ok') {
            $t['last_success_at'] = $now;
            $t['error'] = null;
            $s = restic_summary($args[3] ?? null);
            if ($s !== null) {
                $t['snapshot_id'] = substr((string) ($s['snapshot_id'] ?? ''), 0, 8);
                $t['data_added'] = (int) ($s['data_added'] ?? 0);
                $t['files_total'] = (int) ($s['total_files_processed'] ?? 0);
                $t['files_new'] = (int) ($s['files_new'] ?? 0);
                $t['files_changed'] = (int) ($s['files_changed'] ?? 0);
                $t['source_bytes'] = (int) ($s['total_bytes_processed'] ?? 0);
            }
        } else {
            $t['last_failure_at'] = $now;
            $t['error'] = scrub(stdin_text()) ?: 'Bilinmeyen hata';
        }
        unset($t);
        break;

    case 'stats':
        $t = &target($d, $args[0]);
        $snaps = json_decode((string) @file_get_contents($args[1] ?? ''), true);
        if (is_array($snaps)) {
            $t['snapshot_count'] = count($snaps);
        }
        $st = json_decode((string) @file_get_contents($args[2] ?? ''), true);
        if (is_array($st) && isset($st['total_size'])) {
            $t['repo_size'] = (int) $st['total_size'];
        }
        unset($t);
        break;

    case 'check':
        $t = &target($d, $args[0]);
        $t['last_check_at'] = iso();
        $t['last_check_ok'] = ($args[1] ?? '') === 'ok';
        $t['last_check_error'] = $t['last_check_ok'] ? null : (scrub(stdin_text()) ?: 'Bilinmeyen hata');
        unset($t);
        break;

    case 'finish':
        $in = stdin_json();
        $started = $d['running']['started_at'] ?? iso();
        $d['last_run'] = ['started_at' => $started, 'finished_at' => iso(), 'trigger' => $d['running']['trigger'] ?? 'scheduled', 'ok' => ($args[0] ?? '') === 'ok'] + $in;
        $d['running'] = null;
        break;

    case 'restore-test':
        $in = stdin_json();
        $in['message'] = scrub((string) ($in['message'] ?? ''));
        $d['restore_test'] = ['at' => iso()] + $in;
        break;

    case 'get':
        $v = null;
        if (isset($args[1])) {
            foreach ($d['targets'] as $t) {
                if ($t['id'] === $args[1]) {
                    $v = $t[$args[0]] ?? null;
                }
            }
        } else {
            $v = $d[$args[0]] ?? null;
        }
        echo is_scalar($v) ? (is_bool($v) ? ($v ? '1' : '0') : (string) $v) : '';
        exit(0);

    case 'summary':
        $s = restic_summary($args[0] ?? null);
        if ($s === null) {
            exit(1);
        }
        $mb = fn($b) => number_format(((int) $b) / 1048576, 1, ',', '.') . ' MB';
        printf("anlık görüntü %s · %d dosya (%d yeni, %d değişti) · eklenen %s · %ss\n", substr((string) $s['snapshot_id'], 0, 8),
            $s['total_files_processed'] ?? 0, $s['files_new'] ?? 0, $s['files_changed'] ?? 0, $mb($s['data_added'] ?? 0), round((float) ($s['total_duration'] ?? 0)));
        exit(0);

    default:
        fwrite(STDERR, "bilinmeyen komut: $cmd\n");
        exit(2);
}
save($file, $d);
