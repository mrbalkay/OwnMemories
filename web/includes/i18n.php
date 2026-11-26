<?php
/**
 * OwnMemories — çeviri. t('app.photos') → lang/{locale}/app.php içindeki 'photos'.
 * Bulunamazsa Türkçeye, o da yoksa anahtarın kendisine düşer.
 */
declare(strict_types=1);

const OM_LOCALES = ['tr', 'en'];

function locale(?string $set = null): string
{
    static $loc = null;
    if ($set !== null && in_array($set, OM_LOCALES, true)) {
        $loc = $set;
    }
    if ($loc !== null) {
        return $loc;
    }
    $cand = $_GET['lang'] ?? $_COOKIE['lang'] ?? null;
    if (is_string($cand) && in_array($cand, OM_LOCALES, true)) {
        return $loc = $cand;
    }
    $al = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    return $loc = ($al !== '' && !str_starts_with($al, 'tr') && str_starts_with($al, 'en')) ? 'en' : 'tr';
}

function i18n_group(string $loc, string $group): array
{
    static $cache = [];
    $k = $loc . '/' . $group;
    if (!isset($cache[$k])) {
        $f = OM_WEB . '/lang/' . $loc . '/' . preg_replace('/[^a-z0-9_]/', '', $group) . '.php';
        $cache[$k] = is_file($f) ? (array) (require $f) : [];
    }
    return $cache[$k];
}

function i18n_format(string $s, array $vars): string
{
    foreach ($vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string) $v, $s);
    }
    return $s;
}

function t(string $key, array $vars = []): string
{
    $dot = strpos($key, '.');
    if ($dot === false) {
        return $key;
    }
    $group = substr($key, 0, $dot);
    $name = substr($key, $dot + 1);
    $s = i18n_group(locale(), $group)[$name] ?? i18n_group('tr', $group)[$name] ?? $key;
    return i18n_format((string) $s, $vars);
}

/** JS'ye gönderilecek bir çeviri grubunun tamamı (eksikler TR'den). */
function t_group(string $group): array
{
    return i18n_group(locale(), $group) + i18n_group('tr', $group);
}
