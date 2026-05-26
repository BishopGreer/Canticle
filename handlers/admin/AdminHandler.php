<?php
namespace Canticle\Handlers\Admin;

use Canticle\Core\{Request, Response, Auth, Session, Queue, Pruner};
use Canticle\Models\{User, Instance};

class AdminHandler
{
    private function requireAdmin(Request $req, Response $res): array
    {
        Session::start();
        $user = Auth::user();
        if (!$user || !in_array($user['role'], ['admin', 'moderator'])) {
            if ($req->accepts('application/json')) $res->error('Forbidden', 403);
            $res->redirect('/auth/sign_in');
        }
        return $user;
    }

    /** GET /admin */
    public function dashboard(Request $req, Response $res): void
    {
        $admin = $this->requireAdmin($req, $res);
        $stats = [
            'users'         => db()->fetch('SELECT COUNT(*) c FROM users')['c'],
            'statuses'      => db()->fetch('SELECT COUNT(*) c FROM statuses WHERE deleted_at IS NULL')['c'],
            'instances'     => db()->fetch('SELECT COUNT(*) c FROM instances')['c'],
            'blocked'       => db()->fetch("SELECT COUNT(*) c FROM instances WHERE status='blocked'")['c'],
            'queue_pending' => db()->fetch('SELECT COUNT(*) c FROM queue_jobs')['c'],
            'queue_failed'  => db()->fetch('SELECT COUNT(*) c FROM queue_failed')['c'],
        ];
        $res->html($this->render('admin/dashboard', ['admin' => $admin, 'stats' => $stats, 'title' => 'Admin Dashboard']));
    }

    /** GET /admin/settings */
    public function settingsForm(Request $req, Response $res): void
    {
        $admin = $this->requireAdmin($req, $res);
        Session::start();
        $res->html($this->render('admin/settings', [
            'admin'         => $admin,
            'config'        => $GLOBALS['config'],
            'title'         => 'Instance Settings',
            'flash_success' => Session::flash('success'),
            'flash_error'   => Session::flash('error'),
        ]));
    }

    /** POST /admin/settings */
    public function settingsSave(Request $req, Response $res): void
    {
        $admin = $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            $res->error('Invalid CSRF', 403);
        }

        $configFile = CANTICLE_ROOT . '/config.php';
        $cfg        = require $configFile;

        $editable = ['site_name','site_desc','contact_email','registrations','max_chars','max_poll_options','max_media','max_media_mb','alttext_provider','alttext_api_key','alttext_model','alttext_endpoint'];
        foreach ($editable as $key) {
            $val = $req->input($key);
            if ($val !== null) $cfg[$key] = is_numeric($cfg[$key] ?? null) ? (int) $val : $val;
        }

        file_put_contents($configFile, '<?php return ' . var_export($cfg, true) . ";\n");
        Session::flash('success', 'Settings saved.');
        $res->redirect('/admin/settings');
    }

    /** GET /admin/users */
    public function users(Request $req, Response $res): void
    {
        $admin = $this->requireAdmin($req, $res);
        $q     = trim($req->query('q', ''));
        if ($q) {
            $users = db()->fetchAll("SELECT * FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 50", ["%$q%", "%$q%"]);
        } else {
            $users = db()->fetchAll("SELECT * FROM users ORDER BY id DESC LIMIT 50");
        }
        $res->html($this->render('admin/users', ['admin' => $admin, 'users' => $users, 'q' => $q, 'title' => 'Users']));
    }

    /** POST /admin/users/:id/suspend */
    public function suspendUser(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        $id  = (int) $req->param('id');
        User::update($id, ['suspended' => 1]);
        $res->redirect('/admin/users');
    }

    /** POST /admin/users/:id/unsuspend */
    public function unsuspendUser(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        $id  = (int) $req->param('id');
        User::update($id, ['suspended' => 0]);
        $res->redirect('/admin/users');
    }

    /** POST /admin/users/:id/promote */
    public function promoteUser(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        $id   = (int) $req->param('id');
        $role = $req->input('role', 'user');
        if (in_array($role, ['user','moderator','admin'])) {
            User::update($id, ['role' => $role]);
        }
        $res->redirect('/admin/users');
    }

    /** GET /admin/federation */
    public function federation(Request $req, Response $res): void
    {
        $admin     = $this->requireAdmin($req, $res);
        \Canticle\Core\Session::start();
        $instances = Instance::all();
        $res->html($this->render('admin/federation', [
            'admin'         => $admin,
            'instances'     => $instances,
            'flash_success' => \Canticle\Core\Session::flash('success'),
            'flash_error'   => \Canticle\Core\Session::flash('error'),
            'title'         => 'Federation',
        ]));
    }

    /** POST /admin/federation/block */
    public function blockDomain(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        $domain        = trim(strtolower($req->input('domain', '')));
        $publicReason  = trim($req->input('public_reason', ''));
        $privateReason = trim($req->input('private_reason', ''));
        if ($domain) Instance::block($domain, $publicReason, $privateReason);
        $res->redirect('/admin/federation');
    }

    /** POST /admin/federation/unblock */
    public function unblockDomain(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        $domain = trim($req->input('domain', ''));
        if ($domain) Instance::unblock($domain);
        $res->redirect('/admin/federation');
    }

    /** POST /admin/federation/silence */
    public function silenceDomain(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        $domain        = trim($req->input('domain', ''));
        $publicReason  = trim($req->input('public_reason', ''));
        $privateReason = trim($req->input('private_reason', ''));
        if ($domain) Instance::silence($domain, $publicReason, $privateReason);
        $res->redirect('/admin/federation');
    }

    /** GET /admin/federation/export — download CSV of all blocked/silenced instances */
    public function exportBlocks(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        $csv      = Instance::exportCsv();
        $filename = 'federation-blocklist-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit;
    }

    /** POST /admin/federation/import — upload CSV block list */
    public function importBlocks(Request $req, Response $res): void
    {
        $admin = $this->requireAdmin($req, $res);
        \Canticle\Core\Session::start();

        if (!empty($_FILES['blocklist']['tmp_name']) && $_FILES['blocklist']['error'] === UPLOAD_ERR_OK) {
            $content   = file_get_contents($_FILES['blocklist']['tmp_name']);
            $overwrite = (bool) $req->input('overwrite', true);
            $result    = Instance::importCsv($content, $overwrite);

            $msg = "Imported {$result['imported']} entries, skipped {$result['skipped']}.";
            if ($result['errors']) {
                $msg .= ' Errors: ' . implode('; ', array_slice($result['errors'], 0, 5));
            }
            \Canticle\Core\Session::flash('success', $msg);
        } else {
            \Canticle\Core\Session::flash('error', 'No file uploaded or upload error.');
        }

        $res->redirect('/admin/federation');
    }

    /** GET /admin/statuses */
    public function statuses(Request $req, Response $res): void
    {
        $admin    = $this->requireAdmin($req, $res);
        $rows     = db()->fetchAll("SELECT s.*, u.username FROM statuses s LEFT JOIN users u ON s.local_user_id = u.id WHERE s.deleted_at IS NULL ORDER BY s.id DESC LIMIT 50");
        $res->html($this->render('admin/statuses', ['admin' => $admin, 'statuses' => $rows, 'title' => 'Statuses']));
    }

    /** POST /admin/statuses/:id/delete */
    public function deleteStatus(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        \Canticle\Models\Status::delete((int) $req->param('id'));
        $res->redirect('/admin/statuses');
    }

    /** GET /admin/queue */
    public function queue(Request $req, Response $res): void
    {
        $admin  = $this->requireAdmin($req, $res);
        $jobs   = db()->fetchAll("SELECT * FROM queue_jobs ORDER BY id DESC LIMIT 50");
        $failed = db()->fetchAll("SELECT * FROM queue_failed ORDER BY id DESC LIMIT 50");
        $res->html($this->render('admin/queue', ['admin' => $admin, 'jobs' => $jobs, 'failed' => $failed, 'title' => 'Queue']));
    }

    /** POST /admin/queue/retry/:id */
    public function retryJob(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        $failed = db()->fetch('SELECT * FROM queue_failed WHERE id = ?', [(int) $req->param('id')]);
        if ($failed) {
            db()->insert('queue_jobs', ['queue' => $failed['queue'], 'payload' => $failed['payload'], 'attempts' => 0]);
            db()->delete('queue_failed', 'id = ?', [$failed['id']]);
        }
        $res->redirect('/admin/queue');
    }

    /** POST /admin/queue/delete/:id */
    public function deleteJob(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        db()->delete('queue_failed', 'id = ?', [(int) $req->param('id')]);
        $res->redirect('/admin/queue');
    }

    /** POST /admin/queue/clear-failed */
    public function clearFailed(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        db()->query('DELETE FROM queue_failed');
        $res->redirect('/admin/queue');
    }

    /** GET /admin/relays */
    public function relays(Request $req, Response $res): void
    {
        $admin  = $this->requireAdmin($req, $res);
        Session::start();
        $relays    = db()->fetchAll("SELECT * FROM relays ORDER BY id DESC");
        $instances = db()->fetchAll(
            "SELECT domain, software, version, status, last_seen,
                    (SELECT COUNT(*) FROM remote_actors WHERE domain = instances.domain) AS actor_count
             FROM instances
             ORDER BY last_seen DESC
             LIMIT 200"
        );
        $res->html($this->render('admin/relays', [
            'admin'         => $admin,
            'relays'        => $relays,
            'instances'     => $instances,
            'title'         => 'Relays',
            'flash_success' => Session::flash('success'),
            'flash_error'   => Session::flash('error'),
        ]));
    }

    /** POST /admin/relays/add */
    public function addRelay(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid CSRF token.');
            $res->redirect('/admin/relays');
        }

        $url = rtrim(trim($req->input('url', '')), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
            Session::flash('error', 'Please enter a valid HTTPS relay URL.');
            $res->redirect('/admin/relays');
        }

        if (db()->fetch('SELECT id FROM relays WHERE url = ?', [$url])) {
            Session::flash('error', 'That relay is already configured.');
            $res->redirect('/admin/relays');
        }

        // Auto-detect the relay actor URL and inbox by fetching the relay endpoint.
        // Accepts a relay base URL or a direct actor URL (e.g. https://relay.example.com/actor).
        $relay = $this->fetchRelayActor($url);
        if (!$relay) {
            Session::flash('error',
                'Could not reach the relay or parse its actor document. ' .
                'Try entering the direct actor URL instead ' .
                '(e.g. https://relay.example.com/actor). ' .
                'Make sure the relay is online and reachable from your server.'
            );
            $res->redirect('/admin/relays');
        }

        db()->insert('relays', [
            'url'       => $url,
            'actor_url' => $relay['actor_url'],
            'status'    => 'pending',
        ]);

        $this->sendRelayFollow($url, $relay['actor_url'], $relay['inbox_url']);

        Session::flash('success', 'Relay added — Follow queued, waiting for Accept from relay server. Make sure the queue worker is running.');
        $res->redirect('/admin/relays');
    }

    /** POST /admin/relays/:id/retry — re-send a Follow for a pending/rejected relay */
    public function retryRelay(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid CSRF token.');
            $res->redirect('/admin/relays');
        }

        $id    = (int) $req->param('id');
        $relay = db()->fetch('SELECT * FROM relays WHERE id = ?', [$id]);
        if (!$relay) {
            Session::flash('error', 'Relay not found.');
            $res->redirect('/admin/relays');
        }

        // Re-detect actor in case the stored URL was wrong
        $actor = $this->fetchRelayActor($relay['url'])
              ?? $this->fetchRelayActor($relay['actor_url']);

        if (!$actor) {
            Session::flash('error', 'Could not reach the relay server to resend Follow.');
            $res->redirect('/admin/relays');
        }

        // Update the stored actor URL if we learned a better one
        if ($actor['actor_url'] !== $relay['actor_url']) {
            db()->update('relays', ['actor_url' => $actor['actor_url'], 'status' => 'pending'], 'id = ?', [$id]);
        } else {
            db()->update('relays', ['status' => 'pending'], 'id = ?', [$id]);
        }

        $this->sendRelayFollow($relay['url'], $actor['actor_url'], $actor['inbox_url']);

        Session::flash('success', 'Follow resent — watching for Accept from relay server.');
        $res->redirect('/admin/relays');
    }

    /** POST /admin/relays/:id/remove */
    public function removeRelay(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid CSRF token.');
            $res->redirect('/admin/relays');
        }

        $id    = (int) $req->param('id');
        $relay = db()->fetch('SELECT * FROM relays WHERE id = ?', [$id]);
        if ($relay) {
            $this->sendRelayUnfollow($relay['url'], $relay['actor_url']);
            db()->delete('relays', 'id = ?', [$id]);
        }

        Session::flash('success', 'Relay removed.');
        $res->redirect('/admin/relays');
    }

    private function sendRelayFollow(string $relayUrl, string $actorUrl, string $inboxUrl): void
    {
        $admin = db()->fetch("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        if (!$admin) return;

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => actorUrl($admin['username']) . '/relay-follows/' . md5($relayUrl),
            'type'     => 'Follow',
            'actor'    => actorUrl($admin['username']),
            'object'   => $actorUrl,
        ];

        (new Queue(db()))->push('DeliverActivity', [
            'activity'    => $activity,
            'inbox_url'   => $inboxUrl,
            'actor_url'   => actorUrl($admin['username']) . '#main-key',
            'private_key' => $admin['private_key'],
        ]);
    }

    private function sendRelayUnfollow(string $relayUrl, string $actorUrl): void
    {
        $admin = db()->fetch("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        if (!$admin) return;
        $relay = $this->fetchRelayActor($actorUrl) ?? $this->fetchRelayActor(rtrim($relayUrl, '/'));
        $inboxUrl = $relay['inbox_url'] ?? null;
        if (!$inboxUrl) return;

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => actorUrl($admin['username']) . '/relay-unfollows/' . md5($relayUrl),
            'type'     => 'Undo',
            'actor'    => actorUrl($admin['username']),
            'object'   => [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id'       => actorUrl($admin['username']) . '/relay-follows/' . md5($relayUrl),
                'type'     => 'Follow',
                'actor'    => actorUrl($admin['username']),
                'object'   => $actorUrl,
            ],
        ];

        (new Queue(db()))->push('DeliverActivity', [
            'activity'    => $activity,
            'inbox_url'   => $inboxUrl,
            'actor_url'   => actorUrl($admin['username']) . '#main-key',
            'private_key' => $admin['private_key'],
        ]);
    }

    /**
     * Fetch a relay's actor document. Accepts either a relay base URL or a
     * direct actor URL. Tries several well-known actor paths, and falls back
     * to nodeinfo-based discovery if the common paths don't work.
     * Returns ['actor_url' => ..., 'inbox_url' => ...] or null on failure.
     */
    private function fetchRelayActor(string $url): ?array
    {
        $http = new \Canticle\Core\HttpClient(12); // generous timeout
        $base = rtrim($url, '/');

        // Build candidate list — order matters, most common first.
        // If the user entered a direct actor URL (contains /actor or /users/ etc.)
        // put it first and also try its origin as base.
        $candidates = [$base];

        // Always try the standard paths
        foreach (['/actor', '/actors/main-relay', '/users/main-relay', '/relay'] as $path) {
            $candidates[] = $base . $path;
        }

        // If the URL looks like it IS already an actor document, deduplicate naturally
        $candidates = array_unique($candidates);

        foreach ($candidates as $candidate) {
            $actor = $http->fetchActor($candidate);
            if (!is_array($actor) || empty($actor['inbox'])) continue;
            // Sanity-check: must look like an ActivityPub actor
            if (!in_array($actor['type'] ?? '', ['Application', 'Service', 'Person', 'Group', 'Organization'])) continue;
            return [
                'actor_url' => $actor['id'] ?? $candidate,
                'inbox_url' => $actor['inbox'],
            ];
        }

        // Last resort: try nodeinfo to locate the actor
        $nodeinfo = $http->fetchActor($base . '/.well-known/nodeinfo');
        if (is_array($nodeinfo)) {
            $links = $nodeinfo['links'] ?? [];
            foreach ($links as $link) {
                $niUrl = $link['href'] ?? null;
                if (!$niUrl) continue;
                $ni = $http->fetchActor($niUrl);
                // Some nodeinfo docs embed the actor URL — not standard, but check anyway
                $actorHref = $ni['metadata']['actorUrl'] ?? null;
                if ($actorHref) {
                    $actor = $http->fetchActor($actorHref);
                    if (is_array($actor) && !empty($actor['inbox'])) {
                        return ['actor_url' => $actor['id'] ?? $actorHref, 'inbox_url' => $actor['inbox']];
                    }
                }
            }
        }

        return null;
    }

    // ── Content Pruning ───────────────────────────────────────────────────────

    /** GET /admin/prune */
    public function pruneForm(Request $req, Response $res): void
    {
        $admin = $this->requireAdmin($req, $res);
        Session::start();

        // Read current settings from DB (fall back to config)
        $statusDays = $this->getSetting('remote_status_max_days', config('remote_status_max_days', 90));
        $actorDays  = $this->getSetting('remote_actor_max_days',  config('remote_actor_max_days',  180));

        // Stats for the preview panel
        $remoteStatusCount = db()->fetch("SELECT COUNT(*) c FROM statuses WHERE remote_actor_id IS NOT NULL AND deleted_at IS NULL")['c'] ?? 0;
        $remoteActorCount  = db()->fetch("SELECT COUNT(*) c FROM remote_actors")['c'] ?? 0;
        $mediaCount        = db()->fetch("SELECT COUNT(*) c, COALESCE(SUM(file_size),0) s FROM media WHERE file_path != ''") ?? ['c' => 0, 's' => 0];
        try {
            $pruneLogs = db()->fetchAll("SELECT * FROM prune_log ORDER BY id DESC LIMIT 10") ?: [];
        } catch (\Throwable) {
            $pruneLogs = [];  // prune_log table doesn't exist yet — migration 003 not applied
        }

        // How many statuses would be pruned right now at current settings?
        $wouldPruneStatuses = 0;
        $wouldPruneActors   = 0;
        if ($statusDays > 0) {
            $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$statusDays} days"));
            $wouldPruneStatuses = db()->fetch(
                "SELECT COUNT(*) c FROM statuses
                 WHERE remote_actor_id IS NOT NULL AND deleted_at IS NULL AND created_at < ?
                   AND id NOT IN (SELECT status_id FROM favourites WHERE local_user_id IS NOT NULL)
                   AND id NOT IN (SELECT reply_to_id FROM statuses WHERE local_user_id IS NOT NULL AND reply_to_id IS NOT NULL)
                   AND id NOT IN (SELECT reblog_of_id FROM statuses WHERE local_user_id IS NOT NULL AND reblog_of_id IS NOT NULL)",
                [$cutoff]
            )['c'] ?? 0;
        }
        if ($actorDays > 0) {
            $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$actorDays} days"));
            $wouldPruneActors = db()->fetch(
                "SELECT COUNT(*) c FROM remote_actors
                 WHERE fetched_at < ?
                   AND id NOT IN (SELECT DISTINCT remote_actor_id FROM statuses WHERE remote_actor_id IS NOT NULL AND deleted_at IS NULL AND created_at >= ?)
                   AND id NOT IN (SELECT followee_remote_id FROM follows WHERE followee_remote_id IS NOT NULL)
                   AND id NOT IN (SELECT follower_remote_id FROM follows WHERE follower_remote_id IS NOT NULL)",
                [$cutoff, $cutoff]
            )['c'] ?? 0;
        }

        $res->html($this->render('admin/prune', [
            'admin'               => $admin,
            'title'               => 'Content Retention',
            'statusDays'          => (int) $statusDays,
            'actorDays'           => (int) $actorDays,
            'remoteStatusCount'   => (int) $remoteStatusCount,
            'remoteActorCount'    => (int) $remoteActorCount,
            'mediaCount'          => (int) ($mediaCount['c'] ?? 0),
            'mediaBytes'          => (int) ($mediaCount['s'] ?? 0),
            'wouldPruneStatuses'  => (int) $wouldPruneStatuses,
            'wouldPruneActors'    => (int) $wouldPruneActors,
            'pruneLogs'           => $pruneLogs,
            'flash_success'       => Session::flash('success'),
            'flash_error'         => Session::flash('error'),
        ]));
    }

    /** POST /admin/prune/settings — save retention values */
    public function pruneSettings(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid CSRF token.');
            $res->redirect('/admin/prune');
        }

        $statusDays = max(0, (int) $req->input('remote_status_max_days', 90));
        $actorDays  = max(0, (int) $req->input('remote_actor_max_days', 180));

        $this->setSetting('remote_status_max_days', (string) $statusDays);
        $this->setSetting('remote_actor_max_days',  (string) $actorDays);

        Session::flash('success', 'Retention settings saved.');
        $res->redirect('/admin/prune');
    }

    /** POST /admin/prune/run — run a prune immediately (web-triggered) */
    public function pruneRun(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid CSRF token.');
            $res->redirect('/admin/prune');
        }

        $statusDays = (int) $this->getSetting('remote_status_max_days', config('remote_status_max_days', 90));
        $actorDays  = (int) $this->getSetting('remote_actor_max_days',  config('remote_actor_max_days', 180));

        // Override if form fields present
        if ($req->input('remote_status_max_days') !== null) $statusDays = max(0, (int) $req->input('remote_status_max_days'));
        if ($req->input('remote_actor_max_days')  !== null) $actorDays  = max(0, (int) $req->input('remote_actor_max_days'));

        $pruner  = new Pruner(db(), config('storage_path'));
        $summary = $pruner->run($statusDays, $actorDays);

        $msg = sprintf(
            'Prune complete: %d statuses removed, %d media files deleted (%s freed), %d actor profiles removed.',
            $summary['statuses_removed'],
            $summary['media_files_removed'],
            $this->humanBytes($summary['media_bytes_freed']),
            $summary['actors_removed']
        );
        Session::flash('success', $msg);
        $res->redirect('/admin/prune');
    }

    // ── Upgrades & Migrations ─────────────────────────────────────────────────

    /** GET /admin/upgrades */
    public function upgrades(Request $req, Response $res): void
    {
        $admin = $this->requireAdmin($req, $res);
        Session::start();

        $res->html($this->render('admin/upgrades', [
            'admin'         => $admin,
            'title'         => 'Upgrades',
            'migrations'    => $this->getMigrationStatus(),
            'gitInfo'       => $this->getGitInfo(),
            'flash_success' => Session::flash('success'),
            'flash_error'   => Session::flash('error'),
            'flash_log'     => Session::flash('log'),
        ]));
    }

    /** POST /admin/upgrades/migrate */
    public function runMigrations(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid CSRF token.');
            $res->redirect('/admin/upgrades');
        }

        $log    = '';
        $ran    = 0;
        $errors = 0;

        foreach ($this->getMigrationStatus() as $m) {
            if ($m['applied']) continue;

            $file = CANTICLE_ROOT . '/migrations/' . $m['name'];
            $log .= "▶ Running {$m['name']} …\n";

            $sql   = file_get_contents($file);
            $stmts = array_filter(array_map('trim', explode(';', $sql)));
            $ok    = true;

            foreach ($stmts as $stmt) {
                if (!$stmt || str_starts_with(ltrim($stmt), '--')) continue;
                try {
                    db()->getPdo()->exec($stmt);
                } catch (\Throwable $e) {
                    if (str_contains($e->getMessage(), 'already exists') ||
                        str_contains($e->getMessage(), 'Duplicate')) {
                        $log .= "  ⚠ Skipped (already exists): " . substr($e->getMessage(), 0, 120) . "\n";
                    } else {
                        $log  .= "  ✗ Error: " . $e->getMessage() . "\n";
                        $ok    = false;
                        $errors++;
                        break;
                    }
                }
            }

            if ($ok) {
                db()->query("INSERT IGNORE INTO migrations (filename) VALUES (?)", [$m['name']]);
                $log .= "  ✓ Applied.\n";
                $ran++;
            }
        }

        if ($ran === 0 && $errors === 0) {
            $log = "✓ Nothing to do — all migrations already applied.";
        } elseif ($errors) {
            Session::flash('error', "$errors migration(s) failed. See output below.");
        } else {
            Session::flash('success', "$ran migration(s) applied successfully.");
        }

        Session::flash('log', $log);
        $res->redirect('/admin/upgrades');
    }

    /** POST /admin/upgrades/pull */
    public function runGitPull(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid CSRF token.');
            $res->redirect('/admin/upgrades');
        }

        $git = $this->findGit();
        if (!$git) {
            Session::flash('error', 'git not found on this server.');
            $res->redirect('/admin/upgrades');
        }

        $g = [$git, '-c', 'safe.directory=' . CANTICLE_ROOT, '-C', CANTICLE_ROOT];

        // Detect the remote's actual default branch (may be 'main' or 'master')
        // regardless of what the local branch is named.
        $remoteBranch = null;
        $remoteBranches = $this->runCommand([...$g, 'branch', '-r']);
        foreach (['main', 'master'] as $candidate) {
            if (str_contains($remoteBranches, 'origin/' . $candidate)) {
                $remoteBranch = $candidate;
                break;
            }
        }
        if (!$remoteBranch) {
            // Fall back: ask git what origin's HEAD points to
            $symref = trim($this->runCommand([...$g, 'ls-remote', '--symref', 'origin', 'HEAD']));
            if (preg_match('#refs/heads/(\S+)#', $symref, $m)) {
                $remoteBranch = $m[1];
            }
        }
        $remoteBranch = $remoteBranch ?: 'main';

        // Set upstream tracking so future pulls work without specifying remote/branch
        $this->runCommand([...$g, 'branch', '--set-upstream-to=origin/' . $remoteBranch]);

        $log = "▶ Running git pull origin {$remoteBranch} …\n";
        $log .= $this->runCommand([...$g, 'pull', '--ff-only', 'origin', $remoteBranch]);

        // Auto-run any newly available migrations
        $pending = array_filter($this->getMigrationStatus(), fn($m) => !$m['applied']);
        if ($pending) {
            $log .= "\n▶ Running " . count($pending) . " new migration(s) …\n";
            $ran = 0;
            foreach ($pending as $m) {
                $file  = CANTICLE_ROOT . '/migrations/' . $m['name'];
                $sql   = file_get_contents($file);
                $stmts = array_filter(array_map('trim', explode(';', $sql)));
                $ok    = true;
                $log  .= "  → {$m['name']}\n";
                foreach ($stmts as $stmt) {
                    if (!$stmt || str_starts_with(ltrim($stmt), '--')) continue;
                    try {
                        db()->getPdo()->exec($stmt);
                    } catch (\Throwable $e) {
                        if (!str_contains($e->getMessage(), 'already exists') &&
                            !str_contains($e->getMessage(), 'Duplicate')) {
                            $log .= "    ✗ " . $e->getMessage() . "\n";
                            $ok   = false;
                        }
                    }
                }
                if ($ok) {
                    db()->query("INSERT IGNORE INTO migrations (filename) VALUES (?)", [$m['name']]);
                    $ran++;
                }
            }
            $log .= "  ✓ $ran migration(s) applied.\n";
        } else {
            $log .= "\n✓ No new migrations.\n";
        }

        Session::flash('success', 'Git pull complete.');
        Session::flash('log', $log);
        $res->redirect('/admin/upgrades');
    }

    /** POST /admin/upgrades/opcache-flush — legacy redirect, kept for bookmarks */
    public function flushOpcache(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid CSRF token.');
            $res->redirect('/admin/server-status');
        }
        if (function_exists('opcache_reset') && opcache_reset()) {
            Session::flash('success', 'OPcache cleared.');
        } else {
            Session::flash('error', 'OPcache flush failed or OPcache is not enabled.');
        }
        $res->redirect('/admin/server-status');
    }

    // ── Server Status ─────────────────────────────────────────────────────────

    /** GET /admin/server-status */
    public function serverStatus(Request $req, Response $res): void
    {
        $admin = $this->requireAdmin($req, $res);
        Session::start();
        $res->html($this->render('admin/server_status', [
            'admin'         => $admin,
            'title'         => 'Server Status',
            'sysInfo'       => $this->getSystemInfo(),
            'cacheInfo'     => $this->getServerCacheInfo(),
            'flash_success' => Session::flash('success'),
            'flash_error'   => Session::flash('error'),
        ]));
    }

    /** POST /admin/server-status/flush */
    public function flushServerCache(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid CSRF token.');
            $res->redirect('/admin/server-status');
        }

        $type    = $req->input('cache_type', 'all');
        $flushed = [];
        $failed  = [];

        if (in_array($type, ['opcache', 'all'])) {
            if (function_exists('opcache_reset') && @opcache_reset()) {
                $flushed[] = 'OPcache';
            } elseif (function_exists('opcache_reset')) {
                $failed[] = 'OPcache (reset failed — may need PHP-FPM reload)';
            }
        }

        if (in_array($type, ['apcu', 'all'])) {
            if (function_exists('apcu_clear_cache') && @apcu_clear_cache()) {
                $flushed[] = 'APCu';
            } elseif (function_exists('apcu_clear_cache')) {
                $failed[] = 'APCu (clear failed)';
            }
        }

        if (in_array($type, ['realpath', 'all'])) {
            clearstatcache(true);
            $flushed[] = 'Realpath cache';
        }

        $msg = '';
        if ($flushed) $msg .= 'Flushed: ' . implode(', ', $flushed) . '. ';
        if ($failed)  $msg .= 'Failed: ' . implode(', ', $failed) . '.';

        if ($failed && !$flushed) {
            Session::flash('error', trim($msg));
        } else {
            Session::flash('success', trim($msg) ?: 'Cache flush complete.');
        }

        $res->redirect('/admin/server-status');
    }

    /** Gather all PHP/server cache layer information for the status page. */
    private function getServerCacheInfo(): array
    {
        // ── OPcache ───────────────────────────────────────────────
        $ocStatus = function_exists('opcache_get_status') ? (@opcache_get_status() ?: false) : false;
        $ocMem    = $ocStatus ? ($ocStatus['memory_usage'] ?? []) : [];
        $ocUsed   = (int) ($ocMem['used_memory']  ?? 0);
        $ocFree   = (int) ($ocMem['free_memory']  ?? 0);
        $ocWasted = (int) ($ocMem['wasted_memory'] ?? 0);
        $ocTotal  = $ocUsed + $ocFree + $ocWasted;

        $opcache = [
            'available' => function_exists('opcache_get_status'),
            'enabled'   => (bool) ($ocStatus['opcache_enabled'] ?? false),
            'scripts'   => (int) ($ocStatus['opcache_statistics']['num_cached_scripts'] ?? 0),
            'hits'      => (int) ($ocStatus['opcache_statistics']['hits'] ?? 0),
            'misses'    => (int) ($ocStatus['opcache_statistics']['misses'] ?? 0),
            'used'      => $ocUsed,
            'total'     => $ocTotal,
            'pct'       => $ocTotal > 0 ? round($ocUsed / $ocTotal * 100) : 0,
            'max_files' => ini_get('opcache.max_accelerated_files'),
            'ttl'       => ini_get('opcache.revalidate_freq'),
            'validate'  => (bool) ini_get('opcache.validate_timestamps'),
        ];

        // ── APCu ─────────────────────────────────────────────────
        $apcuRaw  = [];
        if (function_exists('apcu_cache_info')) {
            try { $apcuRaw = @apcu_cache_info() ?: []; } catch (\Throwable) {}
        }
        $apcuMem = function_exists('apcu_sma_info') ? (@apcu_sma_info() ?: []) : [];
        $apcu = [
            'available' => function_exists('apcu_cache_info'),
            'enabled'   => !empty($apcuRaw),
            'entries'   => (int) ($apcuRaw['num_entries'] ?? 0),
            'hits'      => (int) ($apcuRaw['num_hits']    ?? 0),
            'misses'    => (int) ($apcuRaw['num_misses']  ?? 0),
            'mem_avail' => (int) ($apcuMem['avail_mem']   ?? 0),
            'mem_size'  => (int) ($apcuMem['seg_size']    ?? 0),
        ];

        // ── PHP Realpath cache ────────────────────────────────────
        $rpEntries = function_exists('realpath_cache_get') ? count(@realpath_cache_get() ?: []) : 0;
        $rpSize    = function_exists('realpath_cache_size') ? (int) @realpath_cache_size() : 0;
        $realpath  = [
            'available' => function_exists('realpath_cache_size'),
            'size'      => $rpSize,
            'entries'   => $rpEntries,
            'ttl'       => (int) ini_get('realpath_cache_ttl'),
            'max_size'  => ini_get('realpath_cache_size'),
        ];

        // ── SAPI / PHP-FPM ───────────────────────────────────────
        $sapi   = php_sapi_name();
        $isFpm  = $sapi === 'fpm-fcgi';
        // FPM pool isolation: opcache_reset() only resets the worker handling this request.
        // Other workers in the pool continue serving cached bytecode until TTL or reload.
        $fpmWarning = $isFpm && ($opcache['enabled'] ?? false) && !($opcache['validate'] ?? false);

        // ── Web server ────────────────────────────────────────────
        $serverSw   = $_SERVER['SERVER_SOFTWARE'] ?? '';
        $swLower    = strtolower($serverSw);
        $isApache   = str_contains($swLower, 'apache');
        $isNginx    = str_contains($swLower, 'nginx');
        $isLs       = str_contains($swLower, 'litespeed') || str_contains($swLower, 'lsws');
        $apacheMods = ($isApache && function_exists('apache_get_modules')) ? apache_get_modules() : [];

        $webserver = [
            'software'     => $serverSw ?: 'Unknown',
            'is_apache'    => $isApache,
            'is_nginx'     => $isNginx,
            'is_litespeed' => $isLs,
            'mod_cache'    => in_array('mod_cache',    $apacheMods),
            'mod_expires'  => in_array('mod_expires',  $apacheMods),
            'mod_deflate'  => in_array('mod_deflate',  $apacheMods),
        ];

        // ── Session cache ─────────────────────────────────────────
        $session = [
            'handler'  => ini_get('session.save_handler'),
            'lifetime' => (int) ini_get('session.gc_maxlifetime'),
            'path'     => ini_get('session.save_path'),
        ];

        return compact('opcache', 'apcu', 'realpath', 'sapi', 'isFpm', 'fpmWarning', 'webserver', 'session');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function getMigrationStatus(): array
    {
        $files   = glob(CANTICLE_ROOT . '/migrations/*.sql') ?: [];
        sort($files);
        $applied = [];
        try {
            $rows = db()->fetchAll('SELECT filename, ran_at FROM migrations ORDER BY filename');
            foreach ($rows as $r) {
                $applied[$r['filename']] = $r['ran_at'];
            }
        } catch (\Throwable) {}   // migrations table may not exist yet on fresh install

        return array_map(function (string $path) use ($applied): array {
            $name = basename($path);
            return [
                'name'       => $name,
                'applied'    => isset($applied[$name]),
                'applied_at' => $applied[$name] ?? null,
            ];
        }, $files);
    }

    private function getSystemInfo(): array
    {
        $dbVersion = '—';
        try {
            $row = db()->fetch('SELECT VERSION() AS v');
            $dbVersion = $row['v'] ?? '—';
        } catch (\Throwable) {}

        $storageDir   = CANTICLE_ROOT . '/storage';
        $diskFree     = @disk_free_space($storageDir);
        $diskTotal    = @disk_total_space($storageDir);

        return [
            'canticle_version' => CANTICLE_VERSION,
            'php_version'      => PHP_VERSION,
            'db_version'       => $dbVersion,
            'memory_limit'     => ini_get('memory_limit'),
            'upload_max'       => ini_get('upload_max_filesize'),
            'post_max'         => ini_get('post_max_size'),
            'max_exec'         => ini_get('max_execution_time') . 's',
            'disk_free'        => $diskFree !== false ? $this->humanBytes((int)$diskFree) : '—',
            'disk_total'       => $diskTotal !== false ? $this->humanBytes((int)$diskTotal) : '—',
            'dirs' => [
                'storage'       => is_writable($storageDir),
                'storage/media' => is_writable($storageDir . '/media'),
                'storage/cache' => is_writable($storageDir . '/cache'),
                'storage/logs'  => is_writable($storageDir . '/logs'),
            ],
            'extensions' => [
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'openssl'   => extension_loaded('openssl'),
                'curl'      => extension_loaded('curl'),
                'gd'        => extension_loaded('gd') || extension_loaded('imagick'),
                'mbstring'  => extension_loaded('mbstring'),
            ],
        ];
    }

    private function getGitInfo(): array
    {
        $git    = $this->findGit();
        $hasDir = is_dir(CANTICLE_ROOT . '/.git');

        if (!$git && !$hasDir) {
            return ['available' => false, 'reason' => 'no_git_no_repo'];
        }
        if (!$git) {
            return ['available' => false, 'reason' => 'no_git'];
        }
        if (!$hasDir) {
            return ['available' => false, 'reason' => 'no_repo', 'git_path' => $git];
        }

        // -c safe.directory bypasses the "dubious ownership" error that occurs when
        // the directory owner differs from the PHP-FPM user (www-data).
        $g = [$git, '-c', 'safe.directory=' . CANTICLE_ROOT, '-C', CANTICLE_ROOT];

        $branch  = trim($this->runCommand([...$g, 'rev-parse', '--abbrev-ref', 'HEAD']));
        $commit  = trim($this->runCommand([...$g, 'log', '-1', '--format=%h %s']));
        $date    = trim($this->runCommand([...$g, 'log', '-1', '--format=%ci']));
        $remote  = trim($this->runCommand([...$g, 'remote', 'get-url', 'origin']));

        // Ignore expected local-only files (config, storage, logs) when checking dirty state.
        // These will always differ from the repo on a live server and are not relevant to updates.
        $statusLines = array_filter(
            explode("\n", trim($this->runCommand([...$g, 'status', '--porcelain']))),
            fn($line) => $line !== '' &&
                         !preg_match('#^\s*.\s+(config\.php|storage/|\.env)#', $line)
        );
        $dirty = !empty($statusLines);

        // Check if remote has new commits
        $this->runCommand([...$g, 'fetch', '--quiet']);
        $behind  = trim($this->runCommand([...$g, 'rev-list', '--count', 'HEAD..@{u}']));

        $info = [
            'available' => true,
            'branch'    => $branch ?: 'unknown',
            'commit'    => $commit ?: 'unknown',
            'date'      => $date ? substr($date, 0, 10) : '—',
            'dirty'     => $dirty,
            'remote'    => $remote ?: '',
            'behind'    => is_numeric($behind) ? (int)$behind : 0,
        ];

        // Cache result so the admin layout can show update badges without re-fetching
        $this->writeUpdateCache($info);

        return $info;
    }

    // ── Update check cache ────────────────────────────────────────────────────

    /** POST /admin/upgrades/check-updates */
    public function checkUpdates(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid CSRF token.');
            $res->redirect('/admin/upgrades');
        }

        $info = $this->getGitInfo();   // fetches from remote and writes cache

        if (!$info['available']) {
            Session::flash('error', 'Git is not available on this server.');
        } elseif ($info['behind'] > 0) {
            Session::flash('success', $info['behind'] . ' update' . ($info['behind'] !== 1 ? 's' : '') . ' available.');
        } else {
            Session::flash('success', 'Already up to date.');
        }

        $res->redirect('/admin/upgrades');
    }

    /** Read the cached update check result. Returns null if no cache or cache is stale. */
    public static function readUpdateCache(): ?array
    {
        $file = CANTICLE_ROOT . '/storage/cache/git_update_check.json';
        if (!file_exists($file)) return null;
        $data = @json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || empty($data['checked_at'])) return null;
        return $data;
    }

    private function writeUpdateCache(array $info): void
    {
        $dir = CANTICLE_ROOT . '/storage/cache';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $payload = [
            'checked_at'    => time(),
            'git_available' => $info['available'],
            'behind'        => $info['behind'] ?? 0,
            'branch'        => $info['branch'] ?? '',
            'commit'        => $info['commit'] ?? '',
            'date'          => $info['date']   ?? '',
            'remote'        => $info['remote'] ?? '',
        ];
        @file_put_contents($dir . '/git_update_check.json', json_encode($payload));
    }

    private function findGit(): ?string
    {
        foreach (['/usr/bin/git', '/usr/local/bin/git', '/opt/homebrew/bin/git'] as $path) {
            if (is_executable($path)) return $path;
        }
        $which = trim((string) @shell_exec('which git 2>/dev/null'));
        return ($which && is_executable($which)) ? $which : null;
    }

    /** Run a command safely (no shell expansion) and return combined stdout+stderr. */
    private function runCommand(array $cmd): string
    {
        $proc = @proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            CANTICLE_ROOT
        );
        if (!is_resource($proc)) return '';
        $out  = stream_get_contents($pipes[1]);
        $out .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return (string) $out;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    /** GET /admin/rules */
    public function rulesForm(Request $req, Response $res): void
    {
        $admin = $this->requireAdmin($req, $res);
        Session::start();
        $res->html($this->render('admin/rules', [
            'admin'         => $admin,
            'title'         => 'Rules',
            'rules'         => $this->getSetting('site_rules', ''),
            'flash_success' => Session::flash('success'),
        ]));
    }

    /** POST /admin/rules */
    public function rulesSave(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) $res->error('Invalid CSRF', 403);
        $this->setSetting('site_rules', $req->input('rules', ''));
        Session::flash('success', 'Rules saved.');
        $res->redirect('/admin/rules');
    }

    /** GET /admin/privacy */
    public function privacyForm(Request $req, Response $res): void
    {
        $admin = $this->requireAdmin($req, $res);
        Session::start();
        $res->html($this->render('admin/privacy', [
            'admin'         => $admin,
            'title'         => 'Privacy Policy',
            'privacy'       => $this->getSetting('site_privacy', ''),
            'flash_success' => Session::flash('success'),
        ]));
    }

    /** POST /admin/privacy */
    public function privacySave(Request $req, Response $res): void
    {
        $this->requireAdmin($req, $res);
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) $res->error('Invalid CSRF', 403);
        $this->setSetting('site_privacy', $req->input('privacy', ''));
        Session::flash('success', 'Privacy policy saved.');
        $res->redirect('/admin/privacy');
    }

    private function getSetting(string $key, mixed $default = null): mixed
    {
        $row = db()->fetch("SELECT value FROM settings WHERE key_ = ?", [$key]);
        return $row !== null ? $row['value'] : $default;
    }

    private function setSetting(string $key, string $value): void
    {
        $existing = db()->fetch("SELECT key_ FROM settings WHERE key_ = ?", [$key]);
        if ($existing) {
            db()->update('settings', ['value' => $value], 'key_ = ?', [$key]);
        } else {
            db()->insert('settings', ['key_' => $key, 'value' => $value]);
        }
    }

    private function render(string $template, array $data = []): string
    {
        extract($data);
        $file = CANTICLE_ROOT . '/templates/' . $template . '.php';
        if (!file_exists($file)) return "<h1>Template not found: $template</h1>";

        ob_start();
        require $file;
        $content = ob_get_clean();

        ob_start();
        require CANTICLE_ROOT . '/templates/admin/layout.php';
        return ob_get_clean();
    }
}
