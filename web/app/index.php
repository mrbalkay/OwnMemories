<?php
/**
 * OwnMemories web uygulaması kabuğu. Arayüz assets/app/*.js (ES modülleri) ile çizilir.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
$boot = [
    'user' => user_public($user),
    'csrf' => csrf_token(),
    'locale' => locale(),
    't' => t_group('app'),
    'version' => OM_VERSION,
];
$nonce = csp_nonce();
?><!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#f7f5fc" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#17151f" media="(prefers-color-scheme: dark)">
<title>OwnMemories</title>
<link rel="icon" href="<?= e(asset('img/icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app/app.css')) ?>">
</head>
<body>
<div id="app" class="app">
  <aside class="sidebar" id="sidebar">
    <a class="logo" href="#/photos"><img src="<?= e(asset('img/icon.svg')) ?>" alt="" width="30" height="30"><span>OwnMemories</span></a>
    <nav class="nav" id="nav"></nav>
    <div class="me">
      <div class="me-name"><?= e($user['name']) ?></div>
      <div class="me-usage" id="usage"></div>
      <div class="me-links">
        <?php if ((int) $user['is_admin']): ?><a href="/admin/"><?= e(t('app.admin')) ?></a><?php endif; ?>
        <form method="post" action="/logout.php"><?= csrf_field() ?><button type="submit" class="linklike"><?= e(t('auth.logout')) ?></button></form>
      </div>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" id="menuBtn" aria-label="<?= e(t('app.menu')) ?>"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
      <form class="search" id="searchForm" role="search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>
        <input type="search" id="searchInput" placeholder="<?= e(t('app.search_placeholder')) ?>" aria-label="<?= e(t('app.search')) ?>">
      </form>
      <label class="btn btn-primary upload-btn">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0-4.5 4.5M12 4l4.5 4.5M5 15v4h14v-4"/></svg>
        <span><?= e(t('app.upload')) ?></span>
        <input type="file" id="fileInput" multiple accept="image/*,video/*,.heic,.heif,.dng,.mov,.mkv,.mts" hidden>
      </label>
    </header>
    <div class="selbar" id="selbar" hidden></div>
    <main class="view" id="view" tabindex="-1"></main>
  </div>
</div>
<div class="uploads" id="uploads" hidden></div>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<div class="drop-hint" id="dropHint" hidden><?= e(t('app.drop_here')) ?></div>
<script nonce="<?= e($nonce) ?>">window.OM = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script type="module" src="<?= e(asset('app/main.js')) ?>"></script>
</body>
</html>
