<?php
/**
 * OwnMemories — ilk kurulum sihirbazı. Kurulumdan sonra 404 döner.
 * Gereksinimleri ve dış araçları gösterir, veritabanını kurar, config/config.php yazar,
 * sahip (yönetici) hesabını oluşturur.
 */
declare(strict_types=1);

require __DIR__ . '/includes/core.php';

if (is_installed()) {
    http_response_code(404);
    exit('Not found');
}
session_start_secure();
locale('tr');

$checks = [
    ['PHP 8.1+', version_compare(PHP_VERSION, '8.1.0', '>='), true, PHP_VERSION],
    ['pdo_mysql', extension_loaded('pdo_mysql'), true, ''],
    ['zip (Takeout zip dosyaları)', class_exists('ZipArchive'), true, ''],
    ['mbstring', extension_loaded('mbstring'), true, ''],
    ['intl (Unicode dosya adları)', class_exists('Normalizer'), true, ''],
    ['GD veya Imagick (küçük resimler)', extension_loaded('gd') || class_exists('Imagick'), true, ''],
    ['exif', function_exists('exif_read_data'), false, ''],
    ['config/ yazılabilir', is_writable(__DIR__ . '/config'), true, ''],
];
$tools = tools_detect(true);
$toolRows = [
    ['exiftool', $tools['exiftool'], 'En doğru meta veri okuma'],
    ['ffmpeg', $tools['ffmpeg'], 'Video küçük resimleri'],
    ['ffprobe', $tools['ffprobe'], 'Video süresi ve boyutu'],
    ['vipsthumbnail', $tools['vipsthumbnail'], 'Hızlı küçük resim, HEIC'],
    ['Imagick HEIC', $tools['imagick_heic'] ? 'var' : null, 'iPhone HEIC fotoğrafları'],
];
$required_ok = array_reduce($checks, fn($c, $r) => $c && ($r[1] || !$r[2]), true);

$defaultStorage = dirname(__DIR__) . '/storage';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$defaultUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/setup.php')), '/');

$v = [
    'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'app_url' => $defaultUrl, 'storage' => $defaultStorage, 'name' => '', 'email' => '', 'password' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($v as $k => $_) {
        $v[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $v['db_pass'] = (string) ($_POST['db_pass'] ?? '');
    $v['password'] = (string) ($_POST['password'] ?? '');

    if (!$required_ok) {
        $errors[] = 'Zorunlu gereksinimler karşılanmıyor.';
    }
    if ($v['db_name'] === '' || $v['db_user'] === '') {
        $errors[] = 'Veritabanı adı ve kullanıcısı zorunlu.';
    }
    if (!filter_var($v['app_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Uygulama adresi geçerli bir URL olmalı.';
    }
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Geçerli bir e-posta girin.';
    }
    if (mb_strlen($v['password']) < 10) {
        $errors[] = 'Parola en az 10 karakter olmalı.';
    }
    if ($v['name'] === '') {
        $errors[] = 'Adınızı girin.';
    }
    $storage = rtrim($v['storage'], '/');
    if (!$errors) {
        if (!is_dir($storage) && !@mkdir($storage, 0750, true)) {
            $errors[] = 'Depolama klasörü oluşturulamadı: ' . $storage;
        } elseif (!is_writable($storage)) {
            $errors[] = 'Depolama klasörü yazılabilir değil: ' . $storage;
        }
    }
    if (!$errors) {
        $dbc = ['host' => $v['db_host'], 'port' => (int) $v['db_port'], 'name' => $v['db_name'], 'user' => $v['db_user'], 'pass' => $v['db_pass']];
        try {
            $pdo = db_connect($dbc);
        } catch (Throwable $e) {
            $errors[] = 'Veritabanına bağlanılamadı: ' . $e->getMessage();
        }
    }
    if (!$errors) {
        try {
            // Depo web kökü içindeyse doğrudan erişimi kapat.
            $realStorage = realpath($storage) ?: $storage;
            $realWeb = realpath(__DIR__) ?: __DIR__;
            if (str_starts_with($realStorage . '/', $realWeb . '/')) {
                file_put_contents($storage . '/.htaccess', "Require all denied\n");
            }
            foreach (['blobs', 'derived', 'uploads', 'import', 'logs', 'sessions', 'tmp'] as $sub) {
                @mkdir($storage . '/' . $sub, 0750, true);
            }
            $config = [
                'app' => ['url' => rtrim($v['app_url'], '/'), 'name' => 'OwnMemories', 'debug' => false],
                'db' => $dbc,
                'storage' => ['path' => $storage],
                'cron' => ['key' => bin2hex(random_bytes(16))],
                'tools' => [],
            ];
            config_all($config);
            db($pdo);
            schema_install($pdo);
            $exists = db_val('SELECT COUNT(*) FROM users');
            if ((int) $exists === 0) {
                db_exec('INSERT INTO users (email, password_hash, name, is_admin, locale, created_at) VALUES (?, ?, ?, 1, ?, ?)', [
                    mb_strtolower($v['email']), password_hash($v['password'], PASSWORD_DEFAULT), $v['name'], 'tr', now_utc(),
                ]);
            }
            tools_detect(true);
            $php = "<?php\n// OwnMemories yapılandırması — setup.php tarafından yazıldı.\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents(__DIR__ . '/config/config.php', $php, LOCK_EX) === false) {
                throw new RuntimeException('config/config.php yazılamadı.');
            }
            @chmod(__DIR__ . '/config/config.php', 0640);
            file_put_contents(__DIR__ . '/config/installed.lock', gmdate('c'));
            header('Location: ' . rtrim($v['app_url'], '/') . '/login.php?installed=1', true, 302);
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Kurulum başarısız: ' . $e->getMessage();
        }
    }
}

render_simple_head('Kurulum', 'simple wide');
?>
<h1>Kurulum</h1>
<p class="muted">OwnMemories'i bu sunucuya kurun. Bu sayfa kurulumdan sonra kapanır.</p>

<h2>Gereksinimler</h2>
<table class="checks">
<?php foreach ($checks as [$label, $ok, $req, $info]): ?>
  <tr><td><?= e($label) ?> <?= $info !== '' ? '<span class="muted">(' . e($info) . ')</span>' : '' ?></td>
      <td class="<?= $ok ? 'yes' : ($req ? 'no' : 'opt') ?>"><?= $ok ? 'Tamam' : ($req ? 'Eksik' : 'Yok (isteğe bağlı)') ?></td></tr>
<?php endforeach; ?>
</table>

<h2>Medya araçları <span class="muted">(isteğe bağlı, varsa kullanılır)</span></h2>
<table class="checks">
<?php foreach ($toolRows as [$label, $found, $why]): ?>
  <tr><td><?= e($label) ?> <span class="muted">— <?= e($why) ?></span></td>
      <td class="<?= $found ? 'yes' : 'opt' ?>"><?= $found ? 'Bulundu' : 'Yok' ?></td></tr>
<?php endforeach; ?>
</table>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" autocomplete="off">
  <?= csrf_field() ?>
  <h2>Veritabanı (MySQL / MariaDB)</h2>
  <div class="row">
    <div><label for="db_host">Sunucu</label><input id="db_host" name="db_host" type="text" value="<?= e($v['db_host']) ?>"></div>
    <div><label for="db_port">Port</label><input id="db_port" name="db_port" type="number" value="<?= e($v['db_port']) ?>"></div>
  </div>
  <label for="db_name">Veritabanı adı</label><input id="db_name" name="db_name" type="text" value="<?= e($v['db_name']) ?>" required>
  <div class="row">
    <div><label for="db_user">Kullanıcı</label><input id="db_user" name="db_user" type="text" value="<?= e($v['db_user']) ?>" required></div>
    <div><label for="db_pass">Parola</label><input id="db_pass" name="db_pass" type="password" value=""></div>
  </div>

  <h2>Uygulama</h2>
  <label for="app_url">Adres</label><input id="app_url" name="app_url" type="url" value="<?= e($v['app_url']) ?>" required>
  <label for="storage">Depolama klasörü</label><input id="storage" name="storage" type="text" value="<?= e($v['storage']) ?>" required>
  <small class="hint">Fotoğraf ve videoların saklanacağı klasör. Mümkünse web kökünün dışında olmalı; içindeyse erişimi otomatik kapatılır.</small>

  <h2>Sahip hesabı</h2>
  <label for="name">Ad</label><input id="name" name="name" type="text" value="<?= e($v['name']) ?>" required>
  <label for="email">E-posta</label><input id="email" name="email" type="email" value="<?= e($v['email']) ?>" required>
  <label for="password">Parola (en az 10 karakter)</label><input id="password" name="password" type="password" minlength="10" required>

  <button type="submit" class="block">Kur</button>
</form>
<?php render_simple_foot();
