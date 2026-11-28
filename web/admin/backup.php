<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';
$admin = admin_page_start('backup');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    try {
        $r = backup_request_run($admin);
        flash('ok', t($r['already'] ? 'admin.bk_already' : 'admin.bk_requested'));
    } catch (ApiError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/backup.php');
}

$o = backup_overview();
$now = time();
$busy = $o['running'] || $o['requested'];

function bk_when(?string $iso): string
{
    $ts = backup_ts($iso);
    return $ts === null ? e(t('admin.never')) : admin_time(gmdate('Y-m-d H:i:s', $ts));
}

function bk_ago(?string $iso): string
{
    $ts = backup_ts($iso);
    return $ts === null ? '' : e(t('admin.bk_ago', ['t' => admin_ago($ts)]));
}

function bk_dur(?int $s): string
{
    if ($s === null) {
        return '–';
    }
    return $s < 90 ? $s . ' sn' : ($s < 5400 ? round($s / 60) . ' dk' : number_format($s / 3600, 1, ',', '.') . ' sa');
}

function bk_num($n): string
{
    return $n === null ? '–' : number_format((int) $n, 0, ',', '.');
}

$levelText = ['ok' => t('admin.bk_overall_ok'), 'warn' => t('admin.bk_overall_warn'), 'error' => t('admin.bk_overall_error')];
$icon = fn(string $level) => match ($level) {
    'ok' => '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6L12 3Z"/><path d="m8.6 12.2 2.4 2.4 4.6-4.8"/>',
    'warn' => '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6L12 3Z"/><path d="M12 8v4.6M12 15.8v.1"/>',
    default => '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6L12 3Z"/><path d="m9.5 9.5 5 5m0-5-5 5"/>',
};

// Çalışırken veya istek beklerken sayfa kendini yeniler.
if ($busy) {
    header('Refresh: 15');
}
admin_head(t('admin.backup'));
?>
<p class="lead muted"><?= e(t('admin.bk_lead')) ?></p>

<?php if (!$o['configured']): ?>
<section class="bk-empty" aria-labelledby="bkEmptyTitle">
  <div class="bk-empty-art" aria-hidden="true"><svg viewBox="0 0 24 24"><?= $icon('warn') ?></svg></div>
  <div class="bk-empty-body">
    <h2 id="bkEmptyTitle"><?= e(t('admin.bk_unconfigured_title')) ?></h2>
    <p><?= e(t('admin.bk_unconfigured_text')) ?></p>
    <ol class="bk-steps">
      <li><?= e(t('admin.bk_step1')) ?></li>
      <li><?= e(t('admin.bk_step2')) ?>
        <div class="bk-code"><code id="bkCmd"><?= e($o['setup_command']) ?></code><button type="button" class="btn btn-small" data-copy="bkCmd" data-done="<?= e(t('admin.bk_copied')) ?>"><?= e(t('admin.bk_copy')) ?></button></div></li>
      <li><?= e(t('admin.bk_step3')) ?></li>
      <li><?= e(t('admin.bk_step4')) ?></li>
    </ol>
    <p class="small muted"><?= e(t('admin.bk_docs')) ?></p>
  </div>
</section>
<?php else: ?>
<section class="bk-hero" data-level="<?= e($o['level']) ?>" aria-live="polite">
  <div class="bk-hero-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><?= $icon($o['level']) ?></svg></div>
  <div class="bk-hero-text">
    <h2><?= e($levelText[$o['level']] ?? $o['level']) ?></h2>
    <p class="muted">
      <?php if ($o['running']): ?><span class="bk-spin" aria-hidden="true"></span><?= e(t('admin.bk_running')) ?> <?= e(t('admin.bk_running_since', ['t' => bk_ago($o['running_since'])])) ?>
      <?php elseif ($o['last_run']): ?><?= e(t('admin.bk_last_run')) ?>: <?= bk_when($o['last_run']['finished_at'] ?? null) ?> · <?= e(t('admin.bk_trigger_' . ($o['last_run']['trigger'] ?? 'scheduled'))) ?>
      <?php else: ?><?= e(t('admin.bk_r_first_pending')) ?><?php endif; ?>
    </p>
  </div>
  <form method="post" class="bk-hero-action"><?= csrf_field() ?><input type="hidden" name="action" value="run">
    <button class="btn btn-primary" <?= $busy || !$o['can_request'] ? 'disabled aria-disabled="true"' : '' ?>><?= e($o['running'] ? t('admin.bk_running') : ($o['requested'] ? t('admin.bk_requested') : t('admin.bk_run_now'))) ?></button>
  </form>
</section>
<?php if (!$o['can_request']): ?><div class="alert alert-warn"><?= e(t('admin.bk_spool_missing')) ?> <code><?= e($o['setup_command']) ?></code></div><?php endif; ?>

<section class="stats bk-stats">
  <div class="stat"><div class="k"><?= e(t('admin.bk_next_run')) ?></div><div class="v small"><?= $o['next_run_at'] ? bk_when($o['next_run_at']) : '–' ?></div><div class="s">± 20 dk · <?= e(t('admin.bk_trigger_scheduled')) ?></div></div>
  <div class="stat"><div class="k"><?= e(t('admin.bk_scope')) ?></div><div class="v small"><?= e(t($o['include_derived'] ? 'admin.bk_scope_all' : 'admin.bk_scope_originals')) ?></div>
    <div class="s"><?= isset($o['last_run']['source_bytes']) ? e(format_bytes((int) $o['last_run']['source_bytes'])) . ' · ' . bk_num($o['last_run']['source_files'] ?? null) . ' ' . e(mb_strtolower(t('admin.bk_files'))) : '' ?></div></div>
  <div class="stat"><div class="k"><?= e(t('admin.bk_retention')) ?></div><div class="v small"><?php $r = $o['retention'] ?? []; ?><?= e(t('admin.bk_retention_value', ['d' => $r['daily'] ?? 7, 'w' => $r['weekly'] ?? 8, 'm' => $r['monthly'] ?? 24, 'y' => $r['yearly'] ?? 5])) ?></div><div class="s">restic · AES-256</div></div>
  <?php $rt = $o['restore_test']; ?>
  <div class="stat bk-rt" data-ok="<?= $rt === null ? '' : (!empty($rt['ok']) ? '1' : '0') ?>"><div class="k"><?= e(t('admin.bk_restore_test')) ?></div>
    <?php if ($rt === null): ?><div class="v small"><?= e(t('admin.bk_check_never')) ?></div><div class="s"><code>restore.sh test</code></div>
    <?php else: ?><div class="v small"><?= !empty($rt['ok']) ? e(t('admin.bk_check_ok')) : e(t('admin.bk_restore_test_failed')) ?></div><div class="s"><?= bk_when($rt['at'] ?? null) ?> · <?= !empty($rt['ok']) ? e(t('admin.bk_restore_test_ok', ['n' => (int) ($rt['files_checked'] ?? 0)])) : e((string) ($rt['message'] ?? '')) ?></div><?php endif; ?>
  </div>
</section>

<h2><?= e(t('admin.bk_targets')) ?></h2>
<div class="bk-targets">
<?php foreach ($o['targets'] as $tg): $lv = $tg['health']['level']; ?>
  <article class="bk-target" data-level="<?= e($lv) ?>">
    <header>
      <span class="bk-dot" aria-hidden="true"></span>
      <div class="bk-target-title">
        <h3><?= e((string) ($tg['label'] ?? $tg['id'])) ?> <span class="pill"><?= e(t('admin.bk_t_' . ($tg['type'] ?? 'local'))) ?></span></h3>
        <div class="bk-loc path small muted"><?= e((string) ($tg['location'] ?? '')) ?></div>
      </div>
      <span class="bk-state"><?= e(t('admin.bk_r_' . $tg['health']['reason'])) ?></span>
    </header>
    <dl class="bk-facts">
      <div><dt><?= e(t('admin.bk_last_success')) ?></dt><dd><?= bk_when($tg['last_success_at'] ?? null) ?> <span class="muted small"><?= bk_ago($tg['last_success_at'] ?? null) ?></span></dd></div>
      <div><dt><?= e(t('admin.bk_snapshot')) ?></dt><dd><?= isset($tg['snapshot_id']) ? '<code>' . e((string) $tg['snapshot_id']) . '</code>' : '–' ?> <span class="muted small"><?= isset($tg['snapshot_count']) ? e(t('admin.bk_snapshots', ['n' => (int) $tg['snapshot_count']])) : '' ?></span></dd></div>
      <div><dt><?= e(t('admin.bk_repo_size')) ?></dt><dd><?= isset($tg['repo_size']) ? e(format_bytes((int) $tg['repo_size'])) : '–' ?></dd></div>
      <div><dt><?= e(t('admin.bk_added')) ?></dt><dd><?= isset($tg['data_added']) ? e(format_bytes((int) $tg['data_added'])) : '–' ?> <span class="muted small"><?= isset($tg['files_new']) ? e(t('admin.bk_files_delta', ['new' => bk_num($tg['files_new']), 'changed' => bk_num($tg['files_changed'] ?? 0)])) : '' ?></span></dd></div>
      <div><dt><?= e(t('admin.bk_duration')) ?></dt><dd><?= bk_dur(isset($tg['duration_s']) ? (int) $tg['duration_s'] : null) ?></dd></div>
      <div><dt><?= e(t('admin.bk_check')) ?></dt><dd><?php if (!isset($tg['last_check_at'])): ?><?= e(t('admin.bk_check_never')) ?><?php else: ?><span class="<?= !empty($tg['last_check_ok']) ? 'yes' : 'no' ?>"><?= e(t(!empty($tg['last_check_ok']) ? 'admin.bk_check_ok' : 'admin.bk_check_failed')) ?></span> <span class="muted small"><?= bk_ago($tg['last_check_at']) ?></span><?php endif; ?></dd></div>
      <?php if (!empty($tg['upload_limit_kib'])): ?><div><dt><?= e(t('admin.bk_upload_limit')) ?></dt><dd><?= bk_num($tg['upload_limit_kib']) ?> KiB/s</dd></div><?php endif; ?>
    </dl>
    <?php if (!empty($tg['error']) && ($tg['last_status'] ?? '') === 'failed'): ?>
    <div class="bk-err"><strong><?= e(t('admin.bk_last_error')) ?> · <?= bk_when($tg['last_failure_at'] ?? null) ?></strong><pre><?= e((string) $tg['error']) ?></pre></div>
    <?php elseif (!empty($tg['last_check_error']) && empty($tg['last_check_ok'])): ?>
    <div class="bk-err"><strong><?= e(t('admin.bk_check_failed')) ?></strong><pre><?= e((string) $tg['last_check_error']) ?></pre></div>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>

<section class="bk-help">
  <p><?= e(t('admin.bk_change')) ?></p>
  <div class="bk-code"><code id="bkCmd"><?= e($o['setup_command']) ?></code><button type="button" class="btn btn-small" data-copy="bkCmd" data-done="<?= e(t('admin.bk_copied')) ?>"><?= e(t('admin.bk_copy')) ?></button></div>
  <p class="small muted"><?= e(t('admin.bk_restore_help')) ?> · <?= e(t('admin.bk_docs')) ?></p>
</section>
<?php endif; ?>
<script nonce="<?= e(csp_nonce()) ?>">
document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
  const text = document.getElementById(b.dataset.copy).textContent;
  try { await navigator.clipboard.writeText(text); } catch { const r = document.createRange(); r.selectNodeContents(document.getElementById(b.dataset.copy)); getSelection().removeAllRanges(); getSelection().addRange(r); return; }
  const old = b.textContent; b.textContent = b.dataset.done; setTimeout(() => { b.textContent = old; }, 1600);
}));
</script>
<?php admin_foot();
