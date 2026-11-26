<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';
$admin = admin_page_start('users');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'create') {
            admin_create_user($admin, $_POST);
            flash('ok', t('admin.user_created'));
        } elseif (($_POST['action'] ?? '') === 'update') {
            admin_update_user($admin, (int) $_POST['id'], [
                'name' => $_POST['name'] ?? null, 'quota_gb' => $_POST['quota_gb'] ?? '', 'password' => $_POST['password'] ?? '', 'status' => $_POST['status'] ?? null,
            ]);
            flash('ok', t('admin.saved'));
        }
    } catch (ValidationError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/users.php');
}
$users = db_all('SELECT * FROM users ORDER BY id');
admin_head(t('admin.users'));
?>
<?php foreach ($users as $u): ?>
<details class="card user-card">
  <summary><strong><?= e($u['name']) ?></strong> <span class="muted"><?= e($u['email']) ?></span>
    <?php if ((int) $u['is_admin']): ?><span class="pill"><?= e(t('admin.admin')) ?></span><?php endif; ?>
    <?php if ($u['status'] !== 'active'): ?><span class="pill pill-failed"><?= e(t('admin.suspended')) ?></span><?php endif; ?>
    <span class="muted right"><?= e(format_bytes((int) $u['used_bytes'])) ?> / <?= $u['quota_bytes'] !== null ? e(format_bytes((int) $u['quota_bytes'])) : e(t('admin.unlimited')) ?></span></summary>
  <form method="post" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
    <label><?= e(t('admin.name')) ?><input name="name" value="<?= e($u['name']) ?>"></label>
    <label><?= e(t('admin.quota_gb')) ?><input name="quota_gb" inputmode="decimal" value="<?= $u['quota_bytes'] !== null ? e(rtrim(rtrim(number_format((int) $u['quota_bytes'] / 1024 ** 3, 2, '.', ''), '0'), '.')) : '' ?>"></label>
    <label><?= e(t('admin.new_password')) ?><input name="password" type="password" minlength="10" autocomplete="new-password"></label>
    <label><?= e(t('admin.status')) ?><select name="status"><option value="active"<?= $u['status'] === 'active' ? ' selected' : '' ?>><?= e(t('admin.active')) ?></option><option value="suspended"<?= $u['status'] === 'suspended' ? ' selected' : '' ?>><?= e(t('admin.suspended')) ?></option></select></label>
    <div><button class="btn"><?= e(t('admin.save')) ?></button></div>
  </form>
</details>
<?php endforeach; ?>

<h2><?= e(t('admin.create_user')) ?></h2>
<form method="post" class="card form-grid">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label><?= e(t('admin.name')) ?><input name="name" required></label>
  <label><?= e(t('admin.email')) ?><input name="email" type="email" required></label>
  <label><?= e(t('admin.password')) ?><input name="password" type="password" minlength="10" required autocomplete="new-password"></label>
  <label><?= e(t('admin.quota_gb')) ?><input name="quota_gb" inputmode="decimal"></label>
  <label class="check"><input type="checkbox" name="is_admin" value="1"> <?= e(t('admin.is_admin')) ?></label>
  <div><button class="btn btn-primary"><?= e(t('admin.create_user')) ?></button></div>
</form>
<?php admin_foot();
