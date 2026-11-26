<?php
/**
 * OwnMemories — dış araç tespiti (exiftool, ffmpeg, vips) ve kabuksuz çalıştırma.
 * Paylaşımlı hostingde araçlar olmayabilir; çağıranlar PHP eklentilerine geri düşer.
 */
declare(strict_types=1);

/** Testler ve admin için tespit sonucunu ezer (null = temizle). */
function tools_override(?array $o): void
{
    $GLOBALS['__om_tools_override'] = $o;
}

function tool_find(string $name): ?string
{
    if (!function_exists('proc_open')) {
        return null;
    }
    $configured = config('tools.' . $name);
    if (is_string($configured) && $configured !== '' && is_executable($configured)) {
        return $configured;
    }
    foreach (explode(PATH_SEPARATOR, (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')) as $dir) {
        foreach (['', '.exe'] as $suffix) {
            $p = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $suffix;
            if (@is_file($p) && @is_executable($p)) {
                return $p;
            }
        }
    }
    return null;
}

/**
 * Kullanılabilir araçlar. Sonuç site_settings.tools_json'a önbelleklenir ($fresh ile yenilenir).
 * @return array{exiftool:?string,ffmpeg:?string,ffprobe:?string,vipsthumbnail:?string,imagick:bool,imagick_heic:bool,gd:bool,gd_webp:bool,zip:bool,exec:bool}
 */
function tools_detect(bool $fresh = false): array
{
    static $cache = null;
    if (!$fresh && $cache === null && !defined('OM_TESTING')) {
        $json = site_setting('tools_json');
        $decoded = is_string($json) ? json_decode($json, true) : null;
        if (is_array($decoded)) {
            $cache = $decoded;
        }
    }
    if ($fresh || $cache === null) {
        $imagickFormats = class_exists('Imagick') ? array_map('strtoupper', Imagick::queryFormats()) : [];
        $gd = function_exists('gd_info') ? gd_info() : [];
        $cache = [
            'exiftool' => tool_find('exiftool'),
            'ffmpeg' => tool_find('ffmpeg'),
            'ffprobe' => tool_find('ffprobe'),
            'vipsthumbnail' => tool_find('vipsthumbnail'),
            'imagick' => class_exists('Imagick'),
            'imagick_heic' => in_array('HEIC', $imagickFormats, true),
            'gd' => !empty($gd),
            'gd_webp' => !empty($gd['WebP Support']),
            'zip' => class_exists('ZipArchive'),
            'exec' => function_exists('proc_open'),
        ];
        if (!defined('OM_TESTING')) {
            try {
                site_setting_set('tools_json', json_encode($cache));
            } catch (Throwable $e) {
                // kurulum öncesi: veritabanı yok
            }
        }
    }
    $o = $GLOBALS['__om_tools_override'] ?? null;
    return is_array($o) ? array_merge($cache, $o) : $cache;
}

/**
 * Bir aracı kabuk kullanmadan çalıştırır.
 * @return array{code:int,out:string,err:string}
 */
function tool_run(array $argv, int $timeoutSec = 600): array
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open(array_map('strval', $argv), $spec, $pipes);
    if (!is_resource($proc)) {
        return ['code' => -1, 'out' => '', 'err' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $deadline = microtime(true) + $timeoutSec;
    while (true) {
        $r = [$pipes[1], $pipes[2]];
        $w = $x = null;
        if (@stream_select($r, $w, $x, 0, 200000) > 0) {
            foreach ($r as $p) {
                $chunk = fread($p, 65536);
                if ($chunk !== false) {
                    $p === $pipes[1] ? $out .= $chunk : $err .= $chunk;
                }
            }
        }
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return ['code' => (int) $st['exitcode'], 'out' => $out, 'err' => $err];
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return ['code' => -2, 'out' => $out, 'err' => $err . "\ntimeout"];
        }
    }
}
