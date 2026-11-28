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
$skin = $boot['user']['theme'];
?><!doctype html>
<html lang="<?= e(locale()) ?>" data-skin="<?= e($skin) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>OwnMemories</title>
<link rel="icon" href="<?= e(asset('img/icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app/app.css')) ?>">
<?php foreach (UI_THEMES as $th): ?>
<link rel="stylesheet" id="skin-<?= e($th) ?>" href="<?= e(asset('app/theme-' . $th . '.css')) ?>"<?= $th === $skin ? '' : ' disabled' ?>>
<?php endforeach; ?>
<meta name="theme-color" id="themeColor" content="<?= $skin === 'sinema' ? '#0b0b0e' : ($skin === 'galeri' ? '#f6f4f0' : '#f2f2f7') ?>">
</head>
<body>
<div id="app" class="app">
  <div class="ambient" id="ambient" aria-hidden="true"></div>
  <aside class="sidebar" id="sidebar">
    <a class="logo" href="#/photos"><img src="<?= e(asset('img/icon.svg')) ?>" alt="" width="30" height="30"><span>OwnMemories</span></a>
    <nav class="nav" id="nav"></nav>
    <a class="nav-item nav-settings" id="navSettings" href="#/settings"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2.8v2.4M12 18.8v2.4M2.8 12h2.4M18.8 12h2.4M5.5 5.5l1.7 1.7M16.8 16.8l1.7 1.7M5.5 18.5l1.7-1.7M16.8 7.2l1.7-1.7"/></svg><span><?= e(t('app.settings')) ?></span></a>
    <div class="me">
      <div class="me-name"><span class="me-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span><span class="me-name-text"><?= e($user['name']) ?></span></div>
      <div class="me-usage" id="usage"></div>
      <div class="me-bar" id="usageBar" aria-hidden="true"><i></i></div>
      <div class="me-links">
        <?php if ((int) $user['is_admin']): ?><a href="/admin/"><?= e(t('app.admin')) ?></a><?php endif; ?>
        <form method="post" action="/logout.php"><?= csrf_field() ?><button type="submit" class="linklike"><?= e(t('auth.logout')) ?></button></form>
      </div>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" id="menuBtn" aria-label="<?= e(t('app.menu')) ?>"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
      <div class="page-title" id="pageTitle"><h1 id="pageTitleText"></h1><p id="pageSub"></p></div>
      <form class="search" id="searchForm" role="search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>
        <input type="search" id="searchInput" placeholder="<?= e(t('app.search_placeholder')) ?>" aria-label="<?= e(t('app.search')) ?>">
        <kbd class="search-kbd" aria-hidden="true">/</kbd>
      </form>
      <span class="top-spacer" aria-hidden="true"></span>
      <label class="btn btn-primary upload-btn">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0-4.5 4.5M12 4l4.5 4.5M5 15v4h14v-4"/></svg>
        <span><?= e(t('app.upload')) ?></span>
        <input type="file" id="fileInput" multiple accept="image/*,video/*,.heic,.heif,.dng,.mov,.mkv,.mts" hidden>
      </label>
      <span class="top-avatar" title="<?= e($user['name']) ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span>
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
