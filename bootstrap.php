<?php
// Canticle bootstrap — loaded by index.php and artisan.php

declare(strict_types=1);

define('CANTICLE_ROOT', __DIR__);
define('CANTICLE_VERSION', '1.2.0');

// Force UTC for all PHP date functions. Without this, date() and strtotime()
// use the server's system timezone, causing timestamps stored as UTC in the DB
// to be misread, and new timestamps written by PHP to be in the wrong zone.
date_default_timezone_set('UTC');

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    // Canticle\Core\Database  → core/Database.php
    // Canticle\Models\User    → models/User.php
    // Canticle\Handlers\...   → handlers/.../....php
    if (!str_starts_with($class, 'Canticle\\')) return;

    $rel       = str_replace('\\', '/', substr($class, strlen('Canticle\\')));
    $parts     = explode('/', $rel);
    $className = array_pop($parts);                      // keep class name case-sensitive
    $dirs      = array_map('strtolower', $parts);        // lowercase every directory segment
    $file      = CANTICLE_ROOT . '/' . implode('/', [...$dirs, $className]) . '.php';
    if (file_exists($file)) require_once $file;
});

// ── Config ────────────────────────────────────────────────────────────────────
$configFile = CANTICLE_ROOT . '/config.php';
if (!file_exists($configFile)) {
    // Redirect to installer if not yet configured
    if (php_sapi_name() !== 'cli') {
        header('Location: /install.php');
    } else {
        fwrite(STDERR, "config.php not found. Run install.php first.\n");
    }
    exit;
}

$config = require $configFile;
if (!is_array($config)) {
    die('config.php must return an array.');
}

define('CANTICLE_ENV',    $config['app_env']    ?? 'production');
define('CANTICLE_DOMAIN', $config['domain']     ?? '');
define('BASE_URL',        'https://' . CANTICLE_DOMAIN);

// ── Error handling ────────────────────────────────────────────────────────────
if (CANTICLE_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

set_exception_handler(function (\Throwable $e) {
    $logMsg = '[' . date('Y-m-d H:i:s') . '] ' . get_class($e) . ': '
        . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
        . "\n" . $e->getTraceAsString() . "\n";
    error_log('[Canticle] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $logFile = CANTICLE_ROOT . '/storage/logs/error.log';
    if (is_writable(dirname($logFile)) || is_writable($logFile)) {
        file_put_contents($logFile, $logMsg, FILE_APPEND | LOCK_EX);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        // Always expose the error message so the instance owner can diagnose issues.
        // Once your instance is stable, change app_env to 'production' in config.php
        // and errors will show only as "Internal server error".
        if (CANTICLE_ENV === 'development') {
            echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        } else {
            echo json_encode(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
        }
    }
    exit(1);
});

// ── Database ──────────────────────────────────────────────────────────────────
use Canticle\Core\Database;
$db = Database::getInstance($config);

// ── Globals available everywhere ──────────────────────────────────────────────
$GLOBALS['config'] = $config;
$GLOBALS['db']     = $db;

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['config'][$key] ?? $default;
}

function db(): Database
{
    return $GLOBALS['db'];
}

function baseUrl(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function actorUrl(string $username): string
{
    return BASE_URL . '/users/' . urlencode($username);
}

function statusUrl(string $username, string $id): string
{
    return BASE_URL . '/users/' . urlencode($username) . '/statuses/' . $id;
}
