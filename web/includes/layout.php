<?php
/**
 * OwnMemories — basit sayfa düzeni (giriş, kurulum gibi tek kartlı sayfalar).
 */
declare(strict_types=1);

function render_simple_head(string $title, string $bodyClass = 'simple'): void
{
    $lang = function_exists('locale') ? locale() : 'tr';
    echo '<!doctype html><html lang="' . e($lang) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . e($title) . ' · OwnMemories</title>'
        . '<link rel="icon" href="' . e(asset('img/icon.svg')) . '" type="image/svg+xml">'
        . '<link rel="stylesheet" href="' . e(asset('css/site.css')) . '">'
        . '</head><body class="' . e($bodyClass) . '"><main class="card">'
        . '<div class="brand"><img src="' . e(asset('img/icon.svg')) . '" alt="" width="40" height="40"><span>OwnMemories</span></div>';
}

function render_simple_foot(): void
{
    echo '</main></body></html>';
}

function render_flashes(array $list): void
{
    foreach ($list as $f) {
        echo '<div class="alert alert-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
}
