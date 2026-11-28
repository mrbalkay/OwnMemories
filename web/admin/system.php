<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';
$admin = admin_page_start('system');
$geoAction = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'geocode';
$tools = tools_detect($_SERVER['REQUEST_METHOD'] === 'POST' && !$geoAction);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($geoAction) {
        csrf_verify();
        places_enqueue_backfill();
        flash('ok', t('admin.geo_queued'));
        redirect('/admin/system.php');
    }
    flash('ok', t('admin.saved'));
    redirect('/admin/system.php');
}
admin_head(t('admin.system'));
$rows = [
    ['exiftool', $tools['exiftool']], ['ffmpeg', $tools['ffmpeg']], ['ffprobe', $tools['ffprobe']], ['vipsthumbnail', $tools['vipsthumbnail']],
    ['Imagick', $tools['imagick'] ? 'PHP eklentisi' : null], ['Imagick HEIC', $tools['imagick_heic'] ? 'destekleniyor' : null],
    ['GD', $tools['gd'] ? ($tools['gd_webp'] ? 'WebP ile' : 'WebP yok') : null], ['ZipArchive', $tools['zip'] ? 'var' : null], ['proc_open', $tools['exec'] ? 'var' : null],
];
$limits = ['upload_max_filesize', 'post_max_size', 'memory_limit', 'max_execution_time'];
?>
<h2><?= e(t('admin.tools')) ?></h2>
<table class="tbl"><tbody>
<?php foreach ($rows as [$n, $v]): ?><tr><td><?= e($n) ?></td><td class="<?= $v ? 'yes' : 'no' ?>"><?= $v ? e(t('admin.found')) : e(t('admin.missing')) ?></td><td class="muted small"><?= e((string) $v) ?></td></tr><?php endforeach; ?>
</tbody></table>
<form method="post"><?= csrf_field() ?><button class="btn"><?= e(t('admin.refresh_tools')) ?></button></form>
<?php $geo = admin_places_status(); ?>
<h2><?= e(t('admin.geo')) ?></h2>
<table class="tbl"><tbody>
<tr><td><?= e(t('admin.geo_state')) ?></td><td class="<?= $geo['ready'] ? 'yes' : 'no' ?>"><?= e($geo['ready'] ? t('admin.geo_ready') : t('admin.geo_missing')) ?></td>
  <td class="muted small"><?= $geo['ready'] ? e(t('admin.geo_places', ['n' => number_format($geo['places'], 0, ',', '.')])) : '<code>sudo bash server/install-geo.sh</code>' ?></td></tr>
<tr><td><?= e(t('admin.geo_imported')) ?></td><td><?= $geo['imported_at'] ? admin_ago($geo['imported_at']) : '—' ?></td><td></td></tr>
<tr><td><?= e(t('admin.geo_located')) ?></td><td><?= e(number_format($geo['located'], 0, ',', '.')) ?></td><td class="muted small"><?= e(t('admin.geo_pending', ['n' => number_format($geo['pending'], 0, ',', '.')])) ?></td></tr>
</tbody></table>
<?php if ($geo['ready']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="geocode"><button class="btn"><?= e(t('admin.geo_run')) ?></button></form><?php endif; ?>
<p class="muted small"><?= e(t('admin.geo_note')) ?></p>
<h2><?= e(t('admin.php_limits')) ?></h2>
<table class="tbl"><tbody>
<tr><td>PHP</td><td><?= e(PHP_VERSION) ?> (<?= e(PHP_SAPI) ?>)</td></tr>
<?php foreach ($limits as $l): ?><tr><td><?= e($l) ?></td><td><?= e((string) ini_get($l)) ?></td></tr><?php endforeach; ?>
<tr><td>MySQL</td><td><?= e((string) db()->query('SELECT VERSION()')->fetchColumn()) ?></td></tr>
<tr><td><?= e(t('admin.version')) ?></td><td><?= e(OM_VERSION) ?> · şema <?= e((string) site_setting('schema_version')) ?></td></tr>
<tr><td><?= e(t('admin.cron_last')) ?></td><td><?= admin_ago((int) site_setting('cron_last_run', '0')) ?></td></tr>
<tr><td><?= e(t('admin.worker_last')) ?></td><td><?= admin_ago((int) site_setting('worker_last_seen', '0')) ?></td></tr>
<tr><td><?= e(t('admin.storage')) ?></td><td class="path"><?= e(storage_root()) ?></td></tr>
<tr><td><?= e(t('admin.cron_url')) ?></td><td class="path"><?= e(url('cron.php?key=' . config('cron.key'))) ?></td></tr>
</tbody></table>
<?php admin_foot();
