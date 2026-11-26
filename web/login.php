<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (current_user() !== null) {
    redirect('/app/');
}
$email = '';
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!rate_limit('login:' . client_ip() . ':' . mb_strtolower($email), 10, 900)) {
        $error = t('auth.too_many');
    } else {
        $u = user_verify_password($email, $password);
        if ($u === null) {
            $error = t('auth.invalid_credentials');
            audit(null, 'login_failed', $email);
        } else {
            login_user($u);
            $next = (string) ($_GET['next'] ?? '/app/');
            if (!str_starts_with($next, '/') || str_starts_with($next, '//')) {
                $next = '/app/';
            }
            redirect($next);
        }
    }
}
render_simple_head(t('auth.login_title'));
?>
<h1><?= e(t('auth.login_title')) ?></h1>
<p class="muted"><?= e(t('auth.login_sub')) ?></p>
<?php if (isset($_GET['installed'])): ?><div class="alert alert-ok"><?= e(t('auth.installed')) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<form method="post">
  <?= csrf_field() ?>
  <label for="email"><?= e(t('auth.email')) ?></label>
  <input id="email" name="email" type="email" autocomplete="username" value="<?= e($email) ?>" required autofocus>
  <label for="password"><?= e(t('auth.password')) ?></label>
  <input id="password" name="password" type="password" autocomplete="current-password" required>
  <button type="submit" class="block"><?= e(t('auth.login')) ?></button>
</form>
<?php render_simple_foot();
