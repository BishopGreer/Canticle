<?php
/**
 * Canticle Updater
 * Run from CLI: php update.php
 * Or access via browser (admin credentials required).
 */

define('CANTICLE_ROOT', __DIR__);
define('CANTICLE_VERSION', '1.0.0');

require_once __DIR__ . '/bootstrap.php';

if (php_sapi_name() === 'cli') {
    runUpdate();
    exit;
}

// Web mode — require admin session
use Canticle\Core\{Auth, Session};

Session::start();
$user = Auth::user();
if (!$user || $user['role'] !== 'admin') {
    die('<h1>Access denied</h1><p>Must be logged in as admin.</p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
    runUpdate();
    $output = ob_get_clean();
    echo '<pre>' . htmlspecialchars($output) . '</pre>';
    echo '<p><a href="/admin">← Back to admin</a></p>';
    exit;
}

echo <<<HTML
<!DOCTYPE html><html><head><title>Update Canticle</title></head>
<body style="font-family:sans-serif;max-width:600px;margin:2rem auto">
<h1>Update Canticle</h1>
<p>This will run any pending database migrations.</p>
<form method="POST"><button type="submit" style="padding:.5rem 1.5rem;background:#5c6bc0;color:#fff;border:none;border-radius:4px;cursor:pointer">Run Update</button></form>
</body></html>
HTML;

function runUpdate(): void
{
    echo "Canticle Updater v" . CANTICLE_VERSION . "\n";
    echo "Running migrations...\n";

    $migrationsDir = CANTICLE_ROOT . '/migrations';
    $files         = glob("$migrationsDir/*.sql");
    sort($files);

    foreach ($files as $file) {
        $name = basename($file);
        $ran  = db()->fetch('SELECT id FROM migrations WHERE filename = ?', [$name]);
        if ($ran) {
            echo "  SKIP: $name\n";
            continue;
        }

        $sql    = file_get_contents($file);
        $stmts  = array_filter(array_map('trim', explode(';', $sql)));
        $errors = 0;
        foreach ($stmts as $stmt) {
            if ($stmt) {
                try { db()->getPdo()->exec($stmt); }
                catch (\Throwable $e) {
                    if (!str_contains($e->getMessage(), 'already exists')) {
                        echo "  ERROR in $name: " . $e->getMessage() . "\n";
                        $errors++;
                    }
                }
            }
        }

        if ($errors === 0) {
            db()->query("INSERT IGNORE INTO migrations (filename) VALUES (?)", [$name]);
            echo "  OK: $name\n";
        }
    }

    // Clear file cache
    $cacheDir = config('storage_path') . '/cache';
    if (is_dir($cacheDir)) {
        foreach (glob("$cacheDir/*.cache") as $f) {
            @unlink($f);
        }
        echo "Cache cleared.\n";
    }

    echo "Done.\n";
}
