<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';
$admin = admin_page_start('jobs');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    $n = $a === 'retry' ? admin_retry_failed() : ($a === 'rerender' ? admin_rerender_all() : 0);
    audit((int) $admin['id'], 'jobs_' . $a, (string) $n);
    flash('ok', t('admin.enqueued', ['n' => $n]));
    redirect('/admin/jobs.php');
}
$counts = [];
foreach (db_all('SELECT type, status, COUNT(*) n FROM jobs GROUP BY type, status ORDER BY type') as $r) {
    $counts[$r['type']][$r['status']] = (int) $r['n'];
}
$failed = db_all("SELECT * FROM jobs WHERE status = 'failed' ORDER BY id DESC LIMIT 100");
admin_head(t('admin.jobs'));
$statuses = ['queued', 'running', 'done', 'skipped', 'failed'];
?>
<table class="tbl">
  <thead><tr><th></th><?php foreach ($statuses as $s): ?><th class="num"><?= e(t('admin.' . $s)) ?></th><?php endforeach; ?></tr></thead>
  <tbody><?php foreach ($counts as $type => $c): ?><tr><td><?= e($type) ?></td><?php foreach ($statuses as $s): ?><td class="num"><?= number_format($c[$s] ?? 0, 0, ',', '.') ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
</table>
<form method="post" class="actions"><?= csrf_field() ?>
  <button class="btn" name="action" value="retry"><?= e(t('admin.retry_failed')) ?></button>
  <button class="btn" name="action" value="rerender"><?= e(t('admin.rerender')) ?></button>
</form>
<?php if ($failed): ?>
<h2><?= e(t('admin.failed')) ?></h2>
<table class="tbl small"><tbody>
<?php foreach ($failed as $j): ?><tr><td>#<?= (int) $j['id'] ?> <?= e($j['type']) ?><div class="muted"><?= e($j['payload']) ?></div></td><td><?= e((string) $j['last_error']) ?></td><td><?= admin_time($j['updated_at']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php admin_foot();
