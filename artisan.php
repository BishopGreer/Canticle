#!/usr/bin/env php
<?php
// Canticle CLI — queue worker and maintenance commands
// Usage:
//   php artisan.php worker          # run forever, processing queue
//   php artisan.php worker --once   # process one batch and exit
//   php artisan.php migrate         # run pending migrations

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use Canticle\Core\{Queue, HttpClient, Pruner};

$command = $argv[1] ?? 'help';
$once    = in_array('--once', $argv);

match ($command) {
    'worker'          => runWorker($once),
    'migrate'         => runMigrations(),
    'prune'           => runPrune($argv),
    'check:actor'     => runCheckActor($argv),
    'regenerate:keys' => runRegenerateKeys($argv),
    'help'            => printHelp(),
    default           => printHelp(),
};

function runWorker(bool $once): void
{
    $queue  = new Queue(db());
    $client = new HttpClient();

    echo "[Canticle Worker] Starting. Queue: default\n";

    do {
        $job = $queue->pop('default');
        if (!$job) {
            if (!$once) sleep(2);
            continue;
        }

        $payload = json_decode($job['payload'], true);
        $jobType = $payload['job'] ?? '';
        $data    = $payload['data'] ?? [];

        try {
            match ($jobType) {
                'DeliverActivity' => deliverActivity($client, $data),
                default           => throw new \RuntimeException("Unknown job: $jobType"),
            };
            $queue->complete($job['id']);
            echo "[Worker] Job #{$job['id']} ($jobType) done\n";
        } catch (\Throwable $e) {
            $queue->fail($job['id'], $e->getMessage());
            echo "[Worker] Job #{$job['id']} ($jobType) FAILED: {$e->getMessage()}\n";
        }

    } while (!$once);
}

function deliverActivity(HttpClient $client, array $data): void
{
    $result = $client->postActivity(
        $data['inbox_url'],
        $data['activity'],
        $data['actor_url'],
        $data['private_key']
    );

    // status=0 means cURL failed entirely (DNS, connection refused, SSL error, etc.)
    if ($result['status'] === 0) {
        throw new \RuntimeException("Connection failed to {$data['inbox_url']}: " . ($result['error'] ?? 'curl error'));
    }

    // 4xx = our fault (bad signature, not found, etc.) — don't retry endlessly
    // 5xx = their fault — worth retrying
    if ($result['status'] >= 400) {
        // Include the remote body so we can see e.g. "Invalid signature" vs
        // "Request not signed" vs "Could not find actor" in the queue log.
        $remoteError = trim(strip_tags($result['body'] ?? ''));
        $remoteError = mb_substr($remoteError, 0, 300);  // cap length
        throw new \RuntimeException(
            "HTTP {$result['status']} from {$data['inbox_url']}"
            . ($remoteError ? " — $remoteError" : '')
        );
    }
}

function runPrune(array $argv): void
{
    // Parse optional overrides: php artisan.php prune --status-days=60 --actor-days=120
    $statusDays = (int) (config('remote_status_max_days', 90));
    $actorDays  = (int) (config('remote_actor_max_days',  180));

    foreach ($argv as $arg) {
        if (preg_match('/--status-days=(\d+)/', $arg, $m)) $statusDays = (int) $m[1];
        if (preg_match('/--actor-days=(\d+)/',  $arg, $m)) $actorDays  = (int) $m[1];
    }

    // Read from settings table if present (overrides config)
    $settingStatus = db()->fetch("SELECT value FROM settings WHERE key_ = 'remote_status_max_days'");
    $settingActor  = db()->fetch("SELECT value FROM settings WHERE key_ = 'remote_actor_max_days'");
    if ($settingStatus && (int) $settingStatus['value'] >= 0) $statusDays = (int) $settingStatus['value'];
    if ($settingActor  && (int) $settingActor['value']  >= 0) $actorDays  = (int) $settingActor['value'];

    echo "[Prune] Starting content prune\n";
    echo "[Prune] Remote status retention: " . ($statusDays > 0 ? "$statusDays days" : "disabled") . "\n";
    echo "[Prune] Remote actor retention:  " . ($actorDays  > 0 ? "$actorDays days"  : "disabled") . "\n";

    $pruner  = new Pruner(db(), config('storage_path'));
    $summary = $pruner->run($statusDays, $actorDays);

    foreach ($summary['notes'] as $note) {
        echo "[Prune] $note\n";
    }
    echo "[Prune] Done.\n";
}

function runMigrations(): void
{
    $migrationsDir = CANTICLE_ROOT . '/migrations';
    $files         = glob("$migrationsDir/*.sql");
    sort($files);

    foreach ($files as $file) {
        $name = basename($file);
        $ran  = db()->fetch('SELECT id FROM migrations WHERE filename = ?', [$name]);
        if ($ran) {
            echo "[Migrate] Skipping $name (already ran)\n";
            continue;
        }

        $sql = file_get_contents($file);
        // Strip single-line SQL comments (-- ...) so semicolons inside them
        // don't cause false statement splits, then split on real semicolons.
        $sql = preg_replace('/^--[^\n]*$/m', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt) {
                try {
                    db()->getPdo()->exec($stmt);
                } catch (\Throwable $e) {
                    // Ignore "already exists" errors
                    if (!str_contains($e->getMessage(), 'already exists')) {
                        throw $e;
                    }
                }
            }
        }

        db()->query("INSERT IGNORE INTO migrations (filename) VALUES (?)", [$name]);
        echo "[Migrate] Ran $name\n";
    }
    echo "[Migrate] Done.\n";
}

function runCheckActor(array $argv): void
{
    $username = $argv[2] ?? null;
    if (!$username) {
        // Default to first local user
        $user = db()->fetch('SELECT * FROM users WHERE suspended = 0 ORDER BY id LIMIT 1');
    } else {
        $user = \Canticle\Models\User::findByUsername($username);
    }

    if (!$user) {
        echo "[check:actor] No user found.\n";
        exit(1);
    }

    echo "[check:actor] Checking actor for: {$user['username']}\n";
    $actorUrl = actorUrl($user['username']);
    echo "[check:actor] Actor URL: $actorUrl\n";

    // 1. Verify the stored keypair is internally consistent
    echo "\n[check:actor] --- Keypair validation ---\n";
    if (empty($user['private_key'])) {
        echo "[FAIL] Private key is EMPTY in database!\n";
    } elseif (empty($user['public_key'])) {
        echo "[FAIL] Public key is EMPTY in database!\n";
    } else {
        $privKey = openssl_pkey_get_private($user['private_key']);
        if (!$privKey) {
            echo "[FAIL] Private key is INVALID: " . openssl_error_string() . "\n";
        } else {
            $details = openssl_pkey_get_details($privKey);
            $bits    = $details['bits'] ?? 0;
            echo "[OK]   Private key loaded. Type: RSA, Bits: $bits\n";

            // Sign a test string and verify with the stored public key
            $testMsg = 'canticle-keypair-test';
            openssl_sign($testMsg, $sig, $privKey, OPENSSL_ALGO_SHA256);
            $pubKey = openssl_pkey_get_public($user['public_key']);
            if (!$pubKey) {
                echo "[FAIL] Public key is INVALID: " . openssl_error_string() . "\n";
            } else {
                $ok = openssl_verify($testMsg, $sig, $pubKey, OPENSSL_ALGO_SHA256);
                if ($ok === 1) {
                    echo "[OK]   Private and public keys are a MATCHING PAIR.\n";
                } else {
                    echo "[FAIL] Keys DO NOT MATCH — public key in DB does not pair with private key!\n";
                    echo "       You need to regenerate this user's keypair.\n";
                }
            }
        }
    }

    // 2. Fetch the actor URL from the network (simulating what mastodon.social does)
    echo "\n[check:actor] --- Actor document reachability ---\n";
    $client = new HttpClient(10);
    $actor  = $client->fetchActor($actorUrl);
    if (!$actor) {
        echo "[FAIL] Could not fetch actor document at $actorUrl\n";
        echo "       This means remote servers CANNOT verify our signatures (no public key).\n";
    } else {
        echo "[OK]   Actor document fetched successfully.\n";
        $remoteKeyId  = $actor['publicKey']['id'] ?? '(none)';
        $remoteKeyPem = $actor['publicKey']['publicKeyPem'] ?? '';
        $expectedKeyId = $actorUrl . '#main-key';
        echo "       keyId in document:  $remoteKeyId\n";
        echo "       Expected keyId:     $expectedKeyId\n";
        if ($remoteKeyId === $expectedKeyId) {
            echo "[OK]   keyId matches.\n";
        } else {
            echo "[FAIL] keyId MISMATCH — remote servers will look for '{$expectedKeyId}' but document has '$remoteKeyId'\n";
        }
        if ($remoteKeyPem && !empty($user['public_key'])) {
            $normRemote = preg_replace('/\s+/', '', $remoteKeyPem);
            $normLocal  = preg_replace('/\s+/', '', $user['public_key']);
            if ($normRemote === $normLocal) {
                echo "[OK]   Public key in actor document matches database.\n";
            } else {
                echo "[FAIL] Public key in actor document DIFFERS from database.\n";
                echo "       Cached or stale actor document? Clear Apache/proxy cache.\n";
            }
        }
        echo "       Actor type: " . ($actor['type'] ?? '?') . "\n";
        echo "       Inbox:      " . ($actor['inbox'] ?? '?') . "\n";
    }

    echo "\n[check:actor] Done.\n";
}

function runRegenerateKeys(array $argv): void
{
    $username = $argv[2] ?? null;
    if (!$username) {
        echo "Usage: php artisan.php regenerate:keys <username>\n";
        echo "WARNING: After regenerating, remote servers must re-fetch your actor\n";
        echo "         before they can verify new signatures. Existing queued\n";
        echo "         deliveries with the old key will fail — clear the queue first.\n";
        exit(1);
    }

    $user = \Canticle\Models\User::findByUsername($username);
    if (!$user) {
        echo "[regenerate:keys] User '$username' not found.\n";
        exit(1);
    }

    echo "[regenerate:keys] Generating new RSA-2048 keypair for: $username\n";
    $keys = \Canticle\Core\HttpSignature::generateKeypair();

    if (!$keys['private'] || !$keys['public']) {
        echo "[FAIL] openssl_pkey_new failed. Check that PHP OpenSSL extension is installed.\n";
        exit(1);
    }

    db()->update('users', [
        'private_key' => $keys['private'],
        'public_key'  => $keys['public'],
    ], 'id = ?', [$user['id']]);

    echo "[OK]   New keypair stored in database.\n";
    echo "\n";
    echo "Next steps:\n";
    echo "  1. Remote servers cache actor documents (up to 1 hour or more).\n";
    echo "     Your new public key won't be picked up until they re-fetch you.\n";
    echo "  2. Clear any pending DeliverActivity queue jobs (they carry the old key).\n";
    echo "  3. Run: php artisan.php check:actor $username\n";
    echo "     to confirm the new keypair is valid and the actor endpoint serves it.\n";
}

function printHelp(): void
{
    echo <<<HELP
Canticle CLI

Commands:
  worker                    Process queue jobs until stopped (Ctrl+C to stop)
  worker --once             Process one batch of jobs and exit
  migrate                   Run pending database migrations
  prune                     Delete old remote content per retention settings
  prune --status-days=N     Override status retention (0 = keep forever)
  prune --actor-days=N      Override actor retention  (0 = keep forever)
  check:actor [username]    Verify actor keypair and public endpoint (debug 401s)
  regenerate:keys <user>    Generate a fresh RSA keypair for a user (fixes key mismatches)

Cron setup (add to crontab):
  # Queue worker — every minute
  * * * * * php /path/to/canticle/artisan.php worker --once >> /path/to/canticle/storage/logs/worker.log 2>&1

  # Content pruner — once daily at 3 AM
  0 3 * * * php /path/to/canticle/artisan.php prune >> /path/to/canticle/storage/logs/prune.log 2>&1

Retention is configured in Admin → Settings → Content Retention,
or with the --status-days / --actor-days flags above.

HELP;
}
