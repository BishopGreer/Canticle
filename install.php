<?php
/**
 * Canticle Installer
 * Access this file once via your browser to set up the instance.
 * Delete or rename it after installation.
 */

define('CANTICLE_ROOT', __DIR__);
define('CANTICLE_VERSION', '1.1.2');

// Block access if already installed
if (file_exists(__DIR__ . '/config.php')) {
    die('<h1>Already installed</h1><p>Delete config.php if you want to reinstall.</p>');
}

$step   = (int) ($_POST['step'] ?? $_GET['step'] ?? 1);
$errors = [];
$data   = $_POST;

function check(string $label, bool $ok): array { return ['label' => $label, 'ok' => $ok]; }

$checks = [
    check('PHP 8.1+',              version_compare(PHP_VERSION, '8.1.0', '>=')),
    check('PDO + PDO_MySQL',       extension_loaded('pdo') && extension_loaded('pdo_mysql')),
    check('OpenSSL',               extension_loaded('openssl')),
    check('cURL',                  extension_loaded('curl')),
    check('GD or Imagick',         extension_loaded('gd') || extension_loaded('imagick')),
    check('storage/ writable',     is_writable(__DIR__ . '/storage') || @mkdir(__DIR__ . '/storage', 0750, true)),
    check('storage/media writable',is_writable(__DIR__ . '/storage/media') || @mkdir(__DIR__ . '/storage/media', 0750, true)),
    check('storage/cache writable',is_writable(__DIR__ . '/storage/cache') || @mkdir(__DIR__ . '/storage/cache', 0750, true)),
    check('storage/logs writable', is_writable(__DIR__ . '/storage/logs')  || @mkdir(__DIR__ . '/storage/logs',  0750, true)),
];
$allOk = !in_array(false, array_column($checks, 'ok'));

if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate DB credentials and create the database if it doesn't exist yet.
    // We connect WITHOUT a dbname first so the connection succeeds even when
    // the target database hasn't been created yet.
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4',
                $data['db_host'] ?? 'localhost',
                $data['db_port'] ?? 3306
            ),
            $data['db_user'] ?? '',
            $data['db_pass'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $dbName = $data['db_name'] ?? '';
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $dbName) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (\PDOException $e) {
        $errors[] = 'Database connection failed: ' . $e->getMessage();
        $step = 2;
    }
}

if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Build and write config
    $appKey = bin2hex(random_bytes(32));
    $cfg = [
        'db_host'     => $data['db_host'] ?? 'localhost',
        'db_port'     => (int) ($data['db_port'] ?? 3306),
        'db_name'     => $data['db_name'] ?? '',
        'db_user'     => $data['db_user'] ?? '',
        'db_pass'     => $data['db_pass'] ?? '',
        'db_charset'  => 'utf8mb4',
        'domain'      => rtrim(strtolower($data['domain'] ?? ''), '/'),
        'site_name'   => $data['site_name'] ?? 'Canticle',
        'site_desc'   => $data['site_desc'] ?? '',
        'site_lang'   => $data['site_lang'] ?? 'en',
        'contact_email'=> $data['admin_email'] ?? '',
        'registrations'=> 'closed',
        'max_chars'   => 500,
        'max_poll_options' => 4,
        'max_media'   => 4,
        'max_media_mb'=> 40,
        'app_key'     => $appKey,
        'app_env'     => 'production',
        'storage_path'=> __DIR__ . '/storage',
        'media_url'   => '',
        'alttext_provider' => '',
        'alttext_api_key'  => '',
        'alttext_model'    => '',
        'alttext_endpoint' => '',
        'queue_batch_size' => 5,
        'mail_from'   => $data['admin_email'] ?? '',
        'mail_method' => 'mail',
        'smtp_host'   => '',
        'smtp_port'   => 587,
        'smtp_user'   => '',
        'smtp_pass'   => '',
        'smtp_tls'    => true,
        'rate_limit_api'    => 300,
        'rate_limit_public' => 100,
    ];

    file_put_contents(__DIR__ . '/config.php', '<?php return ' . var_export($cfg, true) . ";\n");

    // Ensure the database exists before bootstrapping (belt-and-suspenders:
    // step 2 already created it, but this covers a fresh step-3 POST).
    try {
        $pdoNodb = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']),
            $cfg['db_user'],
            $cfg['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdoNodb->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $cfg['db_name']) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (\PDOException $e) {
        $errors[] = 'Could not create database: ' . $e->getMessage();
    }

    // Run migrations
    if (empty($errors)) {
        require_once __DIR__ . '/bootstrap.php';
        $sql   = file_get_contents(__DIR__ . '/migrations/001_initial.sql');
        $stmts = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($stmts as $stmt) {
            if ($stmt) {
                try { db()->getPdo()->exec($stmt); }
                catch (\Throwable $e) {
                    if (!str_contains($e->getMessage(), 'already exists')) {
                        $errors[] = 'Migration error: ' . $e->getMessage();
                    }
                }
            }
        }
    }

    // Create admin user
    if (empty($errors)) {
        require_once __DIR__ . '/models/User.php';
        require_once __DIR__ . '/core/Auth.php';

        $adminUsername = strtolower(trim($data['admin_username'] ?? 'admin'));
        $adminEmail    = strtolower(trim($data['admin_email'] ?? ''));
        $adminPassword = $data['admin_password'] ?? '';

        \Canticle\Models\User::create([
            'username'      => $adminUsername,
            'email'         => $adminEmail,
            'password_hash' => \Canticle\Core\Auth::hashPassword($adminPassword),
            'display_name'  => $adminUsername,
            'role'          => 'admin',
            'email_verified'=> 1,
        ]);

        $step = 4;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Install Canticle</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
* { box-sizing: border-box; }
body { font-family: system-ui, sans-serif; background: #f5f5f5; margin: 0; padding: 2rem; color: #222; }
.wrap { max-width: 640px; margin: auto; background: #fff; border-radius: 8px; padding: 2rem; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
h1 { margin-top: 0; }
.check { display: flex; align-items: center; gap: .5rem; margin: .4rem 0; }
.ok  { color: green; } .fail { color: red; }
label { display: block; margin-top: 1rem; font-weight: 600; }
input, select { width: 100%; padding: .5rem; margin-top: .25rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; }
button { margin-top: 1.5rem; padding: .7rem 1.5rem; background: #5c6bc0; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
button:hover { background: #3949ab; }
.error { background: #fdecea; color: #b71c1c; padding: .75rem; border-radius: 4px; margin-bottom: 1rem; }
.step  { color: #999; font-size: .9rem; margin-bottom: 1rem; }
.success { background: #e8f5e9; color: #1b5e20; padding: 1rem; border-radius: 4px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>🎵 Install Canticle</h1>

<?php if ($step === 1): ?>
  <p class="step">Step 1 of 3 — Requirements check</p>
  <?php foreach ($checks as $c): ?>
    <div class="check">
      <span class="<?= $c['ok'] ? 'ok' : 'fail' ?>"><?= $c['ok'] ? '✓' : '✗' ?></span>
      <?= htmlspecialchars($c['label']) ?>
    </div>
  <?php endforeach; ?>
  <?php if ($allOk): ?>
    <form method="POST" action="?step=2">
      <input type="hidden" name="step" value="2">
      <button type="submit">Continue →</button>
    </form>
  <?php else: ?>
    <p style="color:red">Please fix the failed requirements before continuing.</p>
  <?php endif; ?>

<?php elseif ($step === 2): ?>
  <p class="step">Step 2 of 3 — Configuration</p>
  <?php foreach ($errors as $e): ?><div class="error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  <form method="POST" action="?step=3">
    <input type="hidden" name="step" value="3">
    <input type="hidden" name="db_host" value="<?= htmlspecialchars($data['db_host'] ?? 'localhost') ?>">
    <input type="hidden" name="db_port" value="<?= htmlspecialchars($data['db_port'] ?? '3306') ?>">
    <input type="hidden" name="db_name" value="<?= htmlspecialchars($data['db_name'] ?? '') ?>">
    <input type="hidden" name="db_user" value="<?= htmlspecialchars($data['db_user'] ?? '') ?>">
    <input type="hidden" name="db_pass" value="<?= htmlspecialchars($data['db_pass'] ?? '') ?>">

    <fieldset style="border:1px solid #eee;padding:1rem;border-radius:4px;margin-bottom:1rem">
      <legend>Database</legend>
      <label>Host <input name="db_host" value="<?= htmlspecialchars($data['db_host'] ?? 'localhost') ?>" required></label>
      <label>Port <input name="db_port" type="number" value="<?= htmlspecialchars($data['db_port'] ?? '3306') ?>" required></label>
      <label>Database name <input name="db_name" value="<?= htmlspecialchars($data['db_name'] ?? '') ?>" required></label>
      <label>Username <input name="db_user" value="<?= htmlspecialchars($data['db_user'] ?? '') ?>" required></label>
      <label>Password <input name="db_pass" type="password" value=""></label>
    </fieldset>

    <fieldset style="border:1px solid #eee;padding:1rem;border-radius:4px;margin-bottom:1rem">
      <legend>Instance</legend>
      <label>Domain (no https://) <input name="domain" placeholder="social.example.com" value="<?= htmlspecialchars($data['domain'] ?? '') ?>" required></label>
      <label>Site name <input name="site_name" value="<?= htmlspecialchars($data['site_name'] ?? 'Canticle') ?>" required></label>
      <label>Description <input name="site_desc" value="<?= htmlspecialchars($data['site_desc'] ?? '') ?>"></label>
      <label>Language <input name="site_lang" value="<?= htmlspecialchars($data['site_lang'] ?? 'en') ?>"></label>
    </fieldset>

    <fieldset style="border:1px solid #eee;padding:1rem;border-radius:4px">
      <legend>Admin account</legend>
      <label>Username <input name="admin_username" value="<?= htmlspecialchars($data['admin_username'] ?? 'admin') ?>" required></label>
      <label>Email <input name="admin_email" type="email" value="<?= htmlspecialchars($data['admin_email'] ?? '') ?>" required></label>
      <label>Password <input name="admin_password" type="password" minlength="8" required></label>
    </fieldset>

    <button type="submit">Install →</button>
  </form>

<?php elseif ($step === 3 && !empty($errors)): ?>
  <?php foreach ($errors as $e): ?><div class="error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  <a href="?step=2">← Back</a>

<?php elseif ($step === 4): ?>
  <div class="success">
    <h2>✓ Canticle installed successfully!</h2>
    <p>Your instance is running at <strong>https://<?= htmlspecialchars(config('domain')) ?></strong></p>
    <p><strong>Next steps:</strong></p>
    <ol>
      <li>Delete or rename <code>install.php</code> — it's a security risk to leave it accessible.</li>
      <li>Set up the Apache2 virtual host (see <code>apache2.conf.example</code>).</li>
      <li>Add a cron job for the queue worker:<br>
        <code>* * * * * php <?= __DIR__ ?>/artisan.php worker --once >> <?= __DIR__ ?>/storage/logs/worker.log 2>&amp;1</code>
      </li>
      <li>Visit <a href="/admin">Admin panel</a> to configure settings.</li>
      <li>Sign in at <a href="/auth/sign_in">Sign in</a>.</li>
    </ol>
  </div>
<?php endif; ?>

</div>
</body>
</html>
