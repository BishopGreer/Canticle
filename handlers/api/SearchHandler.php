<?php
namespace Canticle\Handlers\Api;

use Canticle\Core\{Request, Response, Auth, HttpClient};
use Canticle\Models\{User, Status, RemoteActor};

class SearchHandler
{
    /** GET /api/v1/search, GET /api/v2/search */
    public function search(Request $req, Response $res): void
    {
        $q      = trim($req->query('q', ''));
        $type   = $req->query('type', '');   // accounts | statuses | hashtags
        $limit  = min((int) $req->query('limit', 20), 40);
        $offset = (int) $req->query('offset', 0);
        $local  = (bool) $req->query('local', false);
        $viewer = Auth::user();

        if (!$q) {
            $res->json(['accounts' => [], 'statuses' => [], 'hashtags' => []]);
        }

        $accounts = [];
        $statuses = [];
        $hashtags = [];

        if (!$type || $type === 'accounts') {
            $accounts = self::searchAccounts($q, $limit, $offset, $local);
        }

        if (!$type || $type === 'statuses') {
            $statuses = self::searchStatuses($q, $limit, $offset, $local, $viewer);
        }

        if (!$type || $type === 'hashtags') {
            $hashtags = self::searchHashtags($q, $limit, $offset);
        }

        $res->json([
            'accounts' => $accounts,
            'statuses' => $statuses,
            'hashtags' => $hashtags,
        ]);
    }

    /**
     * Search accounts. Handles:
     *   - Plain term   → LIKE on username/display_name
     *   - @user@domain → exact WebFinger lookup, fetch & store if needed
     *   - @domain.tld  → all known remote actors on that domain
     */
    public static function searchAccounts(string $q, int $limit = 20, int $offset = 0, bool $localOnly = false): array
    {
        $accounts = [];

        // ── @user@domain — WebFinger lookup ───────────────────────────────────
        if (preg_match('/^@?([^@\s]+)@([^@\s]+\.[^@\s]+)$/', $q, $m)) {
            $username = strtolower($m[1]);
            $domain   = strtolower($m[2]);

            // Local user?
            if (strtolower(config('domain')) === $domain) {
                $u = User::findByUsername($username);
                if ($u && !$u['suspended']) $accounts[] = User::toMastodon($u);
                return $accounts;
            }

            if (!$localOnly) {
                // Check DB first
                $actor = RemoteActor::findByWebfinger($username, $domain);
                if (!$actor) {
                    // Try WebFinger resolution
                    $client   = new HttpClient(10);
                    $actorUri = $client->webfinger($username, $domain);
                    if ($actorUri) {
                        $actor = RemoteActor::fetchAndStore($actorUri);
                    }
                }
                if ($actor) $accounts[] = RemoteActor::toMastodon($actor);
            }
            return $accounts;
        }

        // ── @domain.tld — all actors on that domain ────────────────────────────
        if (!$localOnly && preg_match('/^@([^@\s]+\.[^@\s]+)$/', $q, $m)) {
            $domain = strtolower($m[1]);
            $rows   = db()->fetchAll(
                "SELECT * FROM remote_actors WHERE domain = ? ORDER BY followers_count DESC LIMIT " . (int) $limit,
                [$domain]
            );
            return array_map(fn($a) => RemoteActor::toMastodon($a), $rows);
        }

        // ── Plain keyword — LIKE search ────────────────────────────────────────
        $like = '%' . $q . '%';

        // Local users — exact username match first, then display_name
        $local = db()->fetchAll(
            "SELECT * FROM users
             WHERE (username LIKE ? OR display_name LIKE ?) AND suspended = 0
             ORDER BY (username = ?) DESC, followers_count DESC
             LIMIT ? OFFSET ?",
            [$like, $like, strtolower($q), $limit, $offset]
        );
        foreach ($local as $u) {
            $accounts[] = User::toMastodon($u);
        }

        if (!$localOnly) {
            $remoteLimit = max(1, $limit - count($local));
            $remote = db()->fetchAll(
                "SELECT * FROM remote_actors
                 WHERE (username LIKE ? OR display_name LIKE ? OR domain LIKE ?)
                 ORDER BY (username = ?) DESC, followers_count DESC
                 LIMIT ? OFFSET ?",
                [$like, $like, $like, strtolower($q), $remoteLimit, $offset]
            );
            foreach ($remote as $a) {
                $accounts[] = RemoteActor::toMastodon($a);
            }
        }

        return $accounts;
    }

    /** Search posts by content (public only for unauthenticated, own posts included for logged-in). */
    public static function searchStatuses(string $q, int $limit = 20, int $offset = 0, bool $localOnly = false, ?array $viewer = null): array
    {
        $like   = '%' . $q . '%';
        $params = [$like];

        $sql = "SELECT s.* FROM statuses s
                WHERE s.content LIKE ?
                  AND s.deleted_at IS NULL
                  AND s.visibility = 'public'";

        if ($localOnly) {
            $sql .= " AND s.remote_actor_id IS NULL";
        }

        $sql .= " ORDER BY s.id DESC LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

        $rows = db()->fetchAll($sql, $params);
        return Status::manyToMastodon($rows, $viewer);
    }

    /** Search hashtags. */
    public static function searchHashtags(string $q, int $limit = 20, int $offset = 0): array
    {
        // Strip leading # if present
        $term = ltrim($q, '#');
        $rows = db()->fetchAll(
            "SELECT name FROM hashtags WHERE name LIKE ? ORDER BY name LIMIT ? OFFSET ?",
            ['%' . $term . '%', $limit, $offset]
        );
        return array_map(fn($r) => [
            'name'    => $r['name'],
            'url'     => BASE_URL . '/tags/' . urlencode($r['name']),
            'history' => [],
        ], $rows);
    }
}
