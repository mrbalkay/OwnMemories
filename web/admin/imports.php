<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';
$admin = admin_page_start('imports');

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0 && isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="takeout-import-' . $id . '.csv"');
    echo "\xEF\xBB\xBF" . import_report_csv($id);
    exit;
}
if ($id > 0 && isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $imp = db_row('SELECT id, status, files_total, files_done, current_zip, last_error FROM imports WHERE id = ?', [$id]);
    echo json_encode($imp + ['status_label' => t('admin.st_' . $imp['status']), 'counts' => import_counts($id)]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'start') {
            $newId = import_create((int) $_POST['user_id'], trim((string) $_POST['dir']));
            audit((int) $admin['id'], 'import_start', "#$newId " . $_POST['dir']);
            redirect('/admin/imports.php?id=' . $newId);
        }
        if (in_array($action, ['pause', 'resume'], true)) {
            admin_import_set_status((int) $_POST['id'], $action);
            redirect('/admin/imports.php?id=' . (int) $_POST['id']);
        }
    } catch (ValidationError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/imports.php');
}

if ($id > 0) {
    $r = import_report($id);
    $imp = $r['import'];
    admin_head(t('admin.imports') . ' #' . $id);
    $pct = (int) $imp['files_total'] > 0 ? round((int) $imp['files_done'] / (int) $imp['files_total'] * 100) : 0;
    $running = !in_array($imp['status'], ['done', 'failed', 'paused'], true);
    ?>
    <div class="card" id="progress" data-id="<?= $id ?>" data-running="<?= $running ? '1' : '0' ?>">
      <div class="row-between"><span class="pill pill-<?= e($imp['status']) ?>" id="pStatus"><?= e(t('admin.st_' . $imp['status'])) ?></span>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
          <?php if ($running): ?><button class="btn" name="action" value="pause"><?= e(t('admin.pause')) ?></button>
          <?php elseif (in_array($imp['status'], ['paused', 'failed'], true)): ?><button class="btn btn-primary" name="action" value="resume"><?= e(t('admin.resume')) ?></button><?php endif; ?>
        </form></div>
      <progress max="100" value="<?= $pct ?>" id="pBar"></progress>
      <div class="muted"><span id="pDone"><?= (int) $imp['files_done'] ?></span> / <span id="pTotal"><?= (int) $imp['files_total'] ?></span> <?= e(t('admin.items')) ?> <span id="pZip"><?= e((string) $imp['current_zip']) ?></span></div>
      <div class="muted small"><?= e($imp['source_dir']) ?> · <?= e(t('admin.started')) ?>: <?= admin_time($imp['started_at']) ?><?= $imp['finished_at'] ? ' · ' . e(t('admin.finished')) . ': ' . admin_time($imp['finished_at']) : '' ?></div>
      <?php if ($imp['last_error']): ?><div class="alert alert-error"><?= e(t('admin.last_error')) ?>: <?= e($imp['last_error']) ?></div><?php endif; ?>
    </div>
    <h2><?= e(t('admin.counts')) ?></h2>
    <table class="tbl" id="counts"><tbody>
    <?php foreach ($r['counts'] as $k => $v): ?><tr><td><?= e(t('admin.r_' . $k)) ?></td><td class="num" data-k="<?= e($k) ?>"><?= number_format((int) $v, 0, ',', '.') ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <p><a class="btn" href="/admin/imports.php?id=<?= $id ?>&amp;csv=1"><?= e(t('admin.download_csv')) ?></a></p>
    <?php foreach (['failed', 'unsupported', 'no_metadata', 'skipped_trashed'] as $res): if (!$r['items'][$res]) continue; ?>
      <h2><?= e(t('admin.r_' . $res)) ?> <span class="muted">(<?= count($r['items'][$res]) ?><?= count($r['items'][$res]) >= 500 ? '+' : '' ?>)</span></h2>
      <table class="tbl small"><tbody>
      <?php foreach ($r['items'][$res] as $it): ?><tr><td class="path"><?= e($it['entry_path']) ?><div class="muted"><?= e($it['zip_name']) ?></div></td><td><?= e((string) $it['detail']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    <?php endforeach; ?>
    <script nonce="<?= e(csp_nonce()) ?>">
    (function () {
      var box = document.getElementById('progress');
      if (box.dataset.running !== '1') return;
      var timer = setInterval(async function () {
        try {
          var r = await (await fetch('/admin/imports.php?id=' + box.dataset.id + '&json=1', { credentials: 'same-origin' })).json();
          document.getElementById('pStatus').textContent = r.status_label;
          document.getElementById('pDone').textContent = r.files_done;
          document.getElementById('pTotal').textContent = r.files_total;
          document.getElementById('pZip').textContent = r.current_zip || '';
          document.getElementById('pBar').value = r.files_total > 0 ? Math.round(r.files_done / r.files_total * 100) : 0;
          document.querySelectorAll('#counts [data-k]').forEach(function (td) { td.textContent = (r.counts[td.dataset.k] || 0).toLocaleString('tr-TR'); });
          if (['done', 'failed', 'paused'].indexOf(r.status) >= 0) { clearInterval(timer); location.reload(); }
        } catch (e) {}
      }, 5000);
    })();
    </script>
    <?php
    admin_foot();
    exit;
}

$defaultDir = storage_path('import');
$zips = glob($defaultDir . '/*.zip') ?: [];
$users = db_all("SELECT id, name, email FROM users WHERE status = 'active' ORDER BY id");
$imports = db_all('SELECT i.*, u.name AS user_name FROM imports i JOIN users u ON u.id = i.user_id ORDER BY i.id DESC LIMIT 50');
admin_head(t('admin.imports'));
?>
<form method="post" class="card form-grid">
  <?= csrf_field() ?><input type="hidden" name="action" value="start">
  <p class="span muted"><?= e(t('admin.import_help')) ?></p>
  <label><?= e(t('admin.import_user')) ?><select name="user_id"><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) $u['id'] === (int) $admin['id'] ? ' selected' : '' ?>><?= e($u['name']) ?> (<?= e($u['email']) ?>)</option><?php endforeach; ?></select></label>
  <label><?= e(t('admin.import_dir')) ?><input name="dir" value="<?= e($defaultDir) ?>" required></label>
  <div class="span"><strong><?= e(t('admin.zip_files')) ?>:</strong>
    <?php if (!$zips): ?><span class="muted"><?= e(t('admin.no_zips')) ?></span><?php else: ?>
    <ul class="ziplist"><?php foreach ($zips as $z): ?><li><?= e(basename($z)) ?> <span class="muted"><?= e(format_bytes((int) filesize($z))) ?></span></li><?php endforeach; ?></ul><?php endif; ?></div>
  <div><button class="btn btn-primary"><?= e(t('admin.start_import')) ?></button></div>
</form>
<?php if ($imports): ?>
<table class="tbl">
  <thead><tr><th>#</th><th><?= e(t('admin.import_user')) ?></th><th><?= e(t('admin.status')) ?></th><th class="num"><?= e(t('admin.progress')) ?></th><th><?= e(t('admin.r_imported')) ?></th><th><?= e(t('admin.started')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($imports as $i): $c = json_decode((string) $i['counts_json'], true) ?: []; ?>
    <tr><td><a href="/admin/imports.php?id=<?= (int) $i['id'] ?>">#<?= (int) $i['id'] ?></a></td><td><?= e($i['user_name']) ?></td>
      <td><span class="pill pill-<?= e($i['status']) ?>"><?= e(t('admin.st_' . $i['status'])) ?></span></td>
      <td class="num"><?= (int) $i['files_done'] ?> / <?= (int) $i['files_total'] ?></td><td class="num"><?= (int) ($c['imported'] ?? 0) ?></td><td><?= admin_time($i['created_at']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php admin_foot();
