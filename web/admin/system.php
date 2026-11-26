<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';
$admin = admin_page_start('system');
$tools = tools_detect($_SERVER['REQUEST_METHOD'] === 'POST');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
