<?php
/**
 * Yönetim paneli düzeni. Her sayfa: define('OM_ADMIN', true); require __DIR__ . '/_layout.php'; $admin = admin_page_start('users');
 */
declare(strict_types=1);

define('OM_ADMIN', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

function admin_page_start(string $active): array
{
    $admin = require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
    }
    $GLOBALS['__admin_active'] = $active;
    return $admin;
}

function admin_head(string $title): void
{
    $active = $GLOBALS['__admin_active'] ?? '';
    $items = ['index' => 'overview', 'users' => 'users', 'imports' => 'imports', 'jobs' => 'jobs', 'backup' => 'backup', 'system' => 'system'];
    echo '<!doctype html><html lang="' . e(locale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' · ' . e(t('admin.title')) . '</title>'
        . '<link rel="icon" href="' . e(asset('img/icon.svg')) . '" type="image/svg+xml">'
        . '<link rel="icon" href="' . e(asset('img/favicon-32.png')) . '" type="image/png" sizes="32x32">'
        . '<link rel="apple-touch-icon" href="' . e(asset('img/apple-touch-icon.png')) . '">'
        . '<link rel="stylesheet" href="' . e(asset('admin/admin.css')) . '"></head><body><div class="adm">'
        . '<aside class="adm-side"><a class="adm-logo" href="/admin/"><img src="' . e(asset('img/icon.svg')) . '" alt="" width="28" height="28"> ' . e(t('admin.title')) . '</a><nav>';
    foreach ($items as $file => $key) {
        echo '<a href="/admin/' . ($file === 'index' ? '' : $file . '.php') . '"' . ($active === $file ? ' class="active" aria-current="page"' : '') . '>' . e(t('admin.' . $key)) . '</a>';
    }
    echo '</nav><a class="adm-back" href="/app/">' . e(t('admin.back_to_app')) . '</a></aside><main class="adm-main"><h1>' . e($title) . '</h1>';
    foreach (flashes() as $f) {
        echo '<div class="alert alert-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
}

function admin_foot(): void
{
    echo '</main></div></body></html>';
}

function admin_time(?string $dt): string
{
    return $dt ? e(gmdate('d.m.Y H:i', (int) strtotime($dt . ' UTC') + 3 * 3600)) : e(t('admin.never'));
}

function admin_ago(int $ts): string
{
    if ($ts <= 0) {
        return e(t('admin.never'));
    }
    $d = time() - $ts;
    return $d < 120 ? $d . ' sn' : ($d < 7200 ? round($d / 60) . ' dk' : ($d < 172800 ? round($d / 3600) . ' sa' : round($d / 86400) . ' gün'));
}
