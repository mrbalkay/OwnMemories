<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';
admin_page_start('index');
$o = admin_overview();
admin_head(t('admin.overview'));
$lastRun = max($o['cron_last_run'], $o['worker_last_seen']);
?>
<?php if ($lastRun === 0 || time() - $lastRun > 600): ?><div class="alert alert-warn"><?= e(t('admin.no_cron')) ?></div><?php endif; ?>
<section class="stats">
  <div class="stat"><div class="k"><?= e(t('admin.originals')) ?></div><div class="v"><?= e(format_bytes($o['storage_bytes'])) ?></div><div class="s"><?= number_format($o['blob_count'], 0, ',', '.') ?> dosya</div></div>
  <div class="stat"><div class="k"><?= e(t('admin.disk_free')) ?></div><div class="v"><?= $o['disk_free'] !== null ? e(format_bytes($o['disk_free'])) : '–' ?></div><div class="s"><?= $o['disk_total'] ? e(format_bytes($o['disk_total'])) . ' toplam' : '' ?></div></div>
  <div class="stat"><div class="k"><?= e(t('admin.queue')) ?></div><div class="v"><?= (int) ($o['jobs']['queued'] ?? 0) ?></div><div class="s"><?= (int) ($o['jobs']['failed'] ?? 0) ?> <?= e(mb_strtolower(t('admin.failed'))) ?></div></div>
  <div class="stat"><div class="k"><?= e(t('admin.cron_last')) ?></div><div class="v small"><?= admin_ago($o['cron_last_run']) ?></div><div class="s"><?= e(t('admin.worker_last')) ?>: <?= admin_ago($o['worker_last_seen']) ?></div></div>
</section>

<h2><?= e(t('admin.users')) ?></h2>
<table class="tbl">
  <thead><tr><th><?= e(t('admin.name')) ?></th><th class="num"><?= e(t('admin.photos')) ?></th><th class="num"><?= e(t('admin.videos')) ?></th><th class="num"><?= e(t('admin.used')) ?></th><th><?= e(t('admin.quota')) ?></th><th><?= e(t('admin.last_seen')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($o['users'] as $u): ?>
    <tr><td><?= e($u['name']) ?> <span class="muted"><?= e($u['email']) ?></span></td>
      <td class="num"><?= number_format((int) $u['assets'] - (int) $u['videos'], 0, ',', '.') ?></td>
      <td class="num"><?= number_format((int) $u['videos'], 0, ',', '.') ?></td>
      <td class="num"><?= e(format_bytes((int) $u['used_bytes'])) ?></td>
      <td><?= $u['quota_bytes'] !== null ? e(format_bytes((int) $u['quota_bytes'])) : e(t('admin.unlimited')) ?></td>
      <td><?= admin_time($u['last_seen_at']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2><?= e(t('admin.devices')) ?></h2>
<?php if (!$o['devices']): ?><p class="muted"><?= e(t('admin.no_devices')) ?></p><?php else: ?>
<table class="tbl">
  <thead><tr><th><?= e(t('admin.device')) ?></th><th><?= e(t('admin.name')) ?></th><th class="num"><?= e(t('admin.pending')) ?></th><th><?= e(t('admin.last_backup')) ?></th><th><?= e(t('admin.last_seen')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($o['devices'] as $d): ?>
    <tr><td><?= e($d['name']) ?> <span class="muted small"><?= e(trim(($d['model'] ?? '') . ' · iOS ' . ($d['os_version'] ?? '?') . ' · ' . ($d['app_version'] ?? ''))) ?></span>
      <?php if ($d['last_error']): ?><div class="small no"><?= e($d['last_error']) ?></div><?php endif; ?></td>
      <td><?= e($d['user_name']) ?></td><td class="num"><?= (int) $d['pending'] ?></td>
      <td><?= admin_time($d['last_backup_at']) ?></td><td><?= admin_time($d['last_seen_at']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2><?= e(t('admin.recent_imports')) ?></h2>
<?php if (!$o['imports']): ?><p class="muted"><?= e(t('admin.none')) ?></p><?php else: ?>
<table class="tbl">
  <tbody>
  <?php foreach ($o['imports'] as $i): ?>
    <tr><td><a href="/admin/imports.php?id=<?= (int) $i['id'] ?>">#<?= (int) $i['id'] ?></a></td><td><?= e($i['user_name']) ?></td>
      <td><span class="pill pill-<?= e($i['status']) ?>"><?= e(t('admin.st_' . $i['status'])) ?></span></td>
      <td class="num"><?= (int) $i['files_done'] ?> / <?= (int) $i['files_total'] ?></td><td><?= admin_time($i['created_at']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php admin_foot();
