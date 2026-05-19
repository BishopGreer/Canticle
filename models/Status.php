<?php
namespace Canticle\Models;

class Status
{
    public static function find(int $id): ?array
    {
        return db()->fetch('SELECT * FROM statuses WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public static function findByUri(string $uri): ?array
    {
        return db()->fetch('SELECT * FROM statuses WHERE uri = ? AND deleted_at IS NULL', [$uri]);
    }

    public static function create(array $data): int
    {
        $id  = (int) db()->insert('statuses', $data);
        if (isset($data['local_user_id'])) {
            User::incrementCount($data['local_user_id'], 'statuses_count');
        }
        return $id;
    }

    public static function pin(int $id): void
    {
        db()->update('statuses', ['pinned_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    public static function unpin(int $id): void
    {
        db()->update('statuses', ['pinned_at' => null], 'id = ?', [$id]);
    }

    /** Return pinned posts for a local user, oldest pin first. */
    public static function pinnedForUser(int $userId): array
    {
        return db()->fetchAll(
            "SELECT * FROM statuses
             WHERE local_user_id = ? AND pinned_at IS NOT NULL AND deleted_at IS NULL
             ORDER BY pinned_at ASC",
            [$userId]
        );
    }

    public static function delete(int $id): void
    {
        $status = self::find($id);
        if (!$status) return;
        db()->update('statuses', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        if ($status['local_user_id']) {
            User::decrementCount($status['local_user_id'], 'statuses_count');
        }
    }

    public static function publicTimeline(int $limit = 20, ?int $sinceId = null, ?int $maxId = null): array
    {
        $sql = "SELECT s.*, u.username, u.display_name, u.avatar, u.public_key
                FROM statuses s
                LEFT JOIN users u ON s.local_user_id = u.id
                LEFT JOIN remote_actors ra ON s.remote_actor_id = ra.id
                WHERE s.deleted_at IS NULL
                  AND s.visibility = 'public'
                  AND s.reblog_of_id IS NULL
                  AND (
                    s.remote_actor_id IS NULL
                    OR ra.domain NOT IN (
                      SELECT domain FROM instances WHERE status IN ('blocked','silenced')
                    )
                  )";
        $params = [];
        if ($maxId)   { $sql .= ' AND s.id < ?';  $params[] = $maxId; }
        if ($sinceId) { $sql .= ' AND s.id > ?';  $params[] = $sinceId; }
        $sql .= ' ORDER BY s.created_at DESC, s.id DESC LIMIT ' . (int) $limit;
        return db()->fetchAll($sql, $params);
    }

    public static function homeTimeline(int $userId, int $limit = 20, ?int $maxId = null, ?int $sinceId = null): array
    {
        // NOTE: NULL NOT IN (subquery) evaluates to NULL in SQL, not TRUE.
        // Every nullable column must be guarded with "IS NULL OR NOT IN …" so
        // that local posts (remote_actor_id IS NULL) and remote posts
        // (local_user_id IS NULL) are not silently dropped by the filter.
        $sql = "SELECT s.*
                FROM statuses s
                WHERE s.deleted_at IS NULL
                  AND s.visibility IN ('public','unlisted','private')
                  AND (
                    s.local_user_id = ?
                    OR s.local_user_id IN (
                      SELECT followee_local_id FROM follows
                      WHERE follower_local_id = ? AND state = 'accepted' AND followee_local_id IS NOT NULL
                    )
                    OR s.remote_actor_id IN (
                      SELECT followee_remote_id FROM follows
                      WHERE follower_local_id = ? AND state = 'accepted' AND followee_remote_id IS NOT NULL
                    )
                  )
                  AND (
                    s.local_user_id IS NULL
                    OR s.local_user_id NOT IN (
                      SELECT blocked_local_id FROM blocks WHERE blocker_id = ? AND blocked_local_id IS NOT NULL
                    )
                  )
                  AND (
                    s.remote_actor_id IS NULL
                    OR s.remote_actor_id NOT IN (
                      SELECT blocked_remote_id FROM blocks WHERE blocker_id = ? AND blocked_remote_id IS NOT NULL
                    )
                  )
                  AND (
                    s.local_user_id IS NULL
                    OR s.local_user_id NOT IN (
                      SELECT muted_local_id FROM mutes WHERE muter_id = ? AND muted_local_id IS NOT NULL
                    )
                  )
                  AND (
                    s.remote_actor_id IS NULL
                    OR s.remote_actor_id NOT IN (
                      SELECT muted_remote_id FROM mutes WHERE muter_id = ? AND muted_remote_id IS NOT NULL
                    )
                  )
                  AND (
                    s.remote_actor_id IS NULL
                    OR s.remote_actor_id NOT IN (
                      SELECT ra2.id FROM remote_actors ra2
                      JOIN instances i ON i.domain = ra2.domain
                      WHERE i.status = 'blocked'
                    )
                  )";
        $params = [$userId, $userId, $userId, $userId, $userId, $userId, $userId];
        if ($maxId)   { $sql .= ' AND s.id < ?'; $params[] = $maxId; }
        if ($sinceId) { $sql .= ' AND s.id > ?'; $params[] = $sinceId; }
        $sql .= ' ORDER BY s.created_at DESC, s.id DESC LIMIT ' . (int) $limit;
        return db()->fetchAll($sql, $params);
    }

    /**
     * Convert many statuses to Mastodon format in one efficient batch,
     * using a fixed number of queries regardless of how many statuses there are.
     * Use this instead of calling toMastodon() in a loop.
     */
    public static function manyToMastodon(array $statuses, ?array $viewerUser = null): array
    {
        if (!$statuses) return [];

        $ids = array_column($statuses, 'id');
        $ph  = fn(array $arr) => implode(',', array_fill(0, count($arr), '?'));

        // ── Batch load media ───────────────────────────────────────────────────
        $mediaByStatus = [];
        foreach (db()->fetchAll("SELECT * FROM media WHERE status_id IN ({$ph($ids)})", $ids) as $m) {
            $mediaByStatus[$m['status_id']][] = $m;
        }

        // ── Batch load hashtags ────────────────────────────────────────────────
        $tagsByStatus = [];
        foreach (db()->fetchAll(
            "SELECT sh.status_id, h.name FROM status_hashtags sh
             JOIN hashtags h ON sh.hashtag_id = h.id
             WHERE sh.status_id IN ({$ph($ids)})",
            $ids
        ) as $t) {
            $tagsByStatus[$t['status_id']][] = $t;
        }

        // ── Batch load local users ─────────────────────────────────────────────
        $localUsers  = [];
        $localIds    = array_values(array_unique(array_filter(array_column($statuses, 'local_user_id'))));
        if ($localIds) {
            foreach (db()->fetchAll("SELECT * FROM users WHERE id IN ({$ph($localIds)})", $localIds) as $u) {
                $localUsers[$u['id']] = $u;
            }
        }

        // ── Batch load remote actors ───────────────────────────────────────────
        $remoteActors = [];
        $remoteIds    = array_values(array_unique(array_filter(array_column($statuses, 'remote_actor_id'))));
        if ($remoteIds) {
            foreach (db()->fetchAll("SELECT * FROM remote_actors WHERE id IN ({$ph($remoteIds)})", $remoteIds) as $a) {
                $remoteActors[$a['id']] = $a;
            }
        }

        // ── Batch load viewer favourites ───────────────────────────────────────
        $favouritedIds = [];
        if ($viewerUser) {
            $params = array_merge([$viewerUser['id']], $ids);
            foreach (db()->fetchAll(
                "SELECT status_id FROM favourites WHERE local_user_id = ? AND status_id IN ({$ph($ids)})",
                $params
            ) as $f) {
                $favouritedIds[$f['status_id']] = true;
            }
        }

        // ── Batch load reblog targets ──────────────────────────────────────────
        $reblogs       = [];
        $reblogMediaBy = [];
        $reblogTagsBy  = [];
        $reblogIds     = array_values(array_unique(array_filter(array_column($statuses, 'reblog_of_id'))));
        if ($reblogIds) {
            foreach (db()->fetchAll("SELECT * FROM statuses WHERE id IN ({$ph($reblogIds)})", $reblogIds) as $rs) {
                $reblogs[$rs['id']] = $rs;
            }
            // Pull extra authors for reblog targets
            $extraLocalIds  = array_values(array_unique(array_filter(array_column($reblogs, 'local_user_id'))));
            $extraRemoteIds = array_values(array_unique(array_filter(array_column($reblogs, 'remote_actor_id'))));
            $missingLocal   = array_diff($extraLocalIds,  array_keys($localUsers));
            $missingRemote  = array_diff($extraRemoteIds, array_keys($remoteActors));
            if ($missingLocal) {
                foreach (db()->fetchAll("SELECT * FROM users WHERE id IN ({$ph($missingLocal)})", $missingLocal) as $u) {
                    $localUsers[$u['id']] = $u;
                }
            }
            if ($missingRemote) {
                foreach (db()->fetchAll("SELECT * FROM remote_actors WHERE id IN ({$ph($missingRemote)})", $missingRemote) as $a) {
                    $remoteActors[$a['id']] = $a;
                }
            }
            // Media + tags for reblog targets
            foreach (db()->fetchAll("SELECT * FROM media WHERE status_id IN ({$ph($reblogIds)})", $reblogIds) as $m) {
                $reblogMediaBy[$m['status_id']][] = $m;
            }
            foreach (db()->fetchAll(
                "SELECT sh.status_id, h.name FROM status_hashtags sh
                 JOIN hashtags h ON sh.hashtag_id = h.id
                 WHERE sh.status_id IN ({$ph($reblogIds)})",
                $reblogIds
            ) as $t) {
                $reblogTagsBy[$t['status_id']][] = $t;
            }
        }

        // ── Build Mastodon payloads ────────────────────────────────────────────
        $preloaded = [
            'media'      => $mediaByStatus,
            'tags'       => $tagsByStatus,
            'users'      => $localUsers,
            'actors'     => $remoteActors,
            'favourites' => $favouritedIds,
            'reblogs'    => $reblogs,
            'reblogMedia'=> $reblogMediaBy,
            'reblogTags' => $reblogTagsBy,
        ];

        return array_map(fn($s) => self::toMastodon($s, $viewerUser, $preloaded), $statuses);
    }

    /**
     * Convert a single status to Mastodon API format.
     * Pass $preloaded (from manyToMastodon) to avoid extra DB queries.
     */
    public static function toMastodon(array $status, ?array $viewerUser = null, array $preloaded = []): array
    {
        $baseUrl = BASE_URL;
        $sid     = $status['id'];

        // Media
        $media = isset($preloaded['media'][$sid])
            ? $preloaded['media'][$sid]
            : Media::forStatus($sid);

        // Poll
        $poll = $status['poll_id']
            ? Poll::find($status['poll_id'])
            : null;

        // Mentions
        $mentions = Mention::forStatus($sid);

        // Hashtags
        $rawTags = isset($preloaded['tags'][$sid])
            ? $preloaded['tags'][$sid]
            : Hashtag::forStatus($sid);
        $tags = array_map(fn($t) => ['name' => $t['name'], 'url' => "$baseUrl/tags/{$t['name']}"], $rawTags);

        // Author
        if ($status['local_user_id']) {
            $author  = $preloaded['users'][$status['local_user_id']]  ?? User::find($status['local_user_id']);
            $account = $author ? User::toMastodon($author) : null;
        } else {
            $author  = $preloaded['actors'][$status['remote_actor_id']] ?? RemoteActor::find($status['remote_actor_id']);
            $account = $author ? RemoteActor::toMastodon($author) : null;
        }

        // Reblog
        $reblog = null;
        if ($status['reblog_of_id']) {
            if (isset($preloaded['reblogs'][$status['reblog_of_id']])) {
                $origStatus = $preloaded['reblogs'][$status['reblog_of_id']];
                $origPreloaded = [
                    'media'  => $preloaded['reblogMedia'] ?? [],
                    'tags'   => $preloaded['reblogTags']  ?? [],
                    'users'  => $preloaded['users']  ?? [],
                    'actors' => $preloaded['actors'] ?? [],
                    'favourites' => $preloaded['favourites'] ?? [],
                ];
                $reblog = self::toMastodon($origStatus, $viewerUser, $origPreloaded);
            } else {
                $orig   = self::find($status['reblog_of_id']);
                $reblog = $orig ? self::toMastodon($orig, $viewerUser) : null;
            }
        }

        // Favourited by viewer
        $favourited = false;
        if ($viewerUser) {
            $favourited = isset($preloaded['favourites'][$sid])
                ? (bool) $preloaded['favourites'][$sid]
                : (bool) db()->fetch(
                    'SELECT id FROM favourites WHERE status_id = ? AND local_user_id = ?',
                    [$sid, $viewerUser['id']]
                );
        }

        return [
            'id'                     => (string) $sid,
            'created_at'             => date('c', strtotime($status['created_at'])),
            'in_reply_to_id'         => $status['reply_to_id'] ? (string) $status['reply_to_id'] : null,
            'in_reply_to_account_id' => null,
            'sensitive'              => (bool) $status['sensitive'],
            'spoiler_text'           => $status['content_warning'] ?? '',
            'visibility'             => $status['visibility'],
            'language'               => $status['language'],
            'uri'                    => $status['uri'],
            'url'                    => $status['url'] ?: $status['uri'],
            'replies_count'          => (int) $status['replies_count'],
            'reblogs_count'          => (int) $status['reblogs_count'],
            'favourites_count'       => (int) $status['favourites_count'],
            'edited_at'              => $status['edited_at'] ? date('c', strtotime($status['edited_at'])) : null,
            'content'                => $status['content'],
            'reblog'                 => $reblog,
            'application'            => ['name' => 'Canticle', 'website' => $baseUrl],
            'account'                => $account,
            'media_attachments'      => array_map([Media::class, 'toMastodon'], $media),
            'mentions'               => $mentions,
            'tags'                   => $tags,
            'emojis'                 => [],
            'card'                   => null,
            'poll'                   => $poll ? Poll::toMastodon($poll, $viewerUser) : null,
            'favourited'             => $favourited,
            'reblogged'              => false,
            'bookmarked'             => false,
            'muted'                  => false,
            'pinned'                 => !empty($status['pinned_at']),
            'text'                   => strip_tags($status['content']),
            'filtered'               => [],
        ];
    }

    public static function toActivityPub(array $status): array
    {
        if (!$status['local_user_id']) return [];
        $user   = User::find($status['local_user_id']);
        $media  = Media::forStatus($status['id']);

        $obj = [
            'id'           => $status['uri'],
            'type'         => 'Note',
            'summary'      => $status['content_warning'] ?: null,
            'inReplyTo'    => $status['reply_to_uri'] ?: null,
            'published'    => date('c', strtotime($status['created_at'])),
            'url'          => $status['url'] ?: $status['uri'],
            'attributedTo' => actorUrl($user['username']),
            'to'           => $status['visibility'] === 'public'
                ? ['https://www.w3.org/ns/activitystreams#Public']
                : [actorUrl($user['username']) . '/followers'],
            'cc'           => $status['visibility'] === 'public'
                ? [actorUrl($user['username']) . '/followers']
                : [],
            'sensitive'    => (bool) $status['sensitive'],
            'content'      => $status['content'],
            'contentMap'   => [$status['language'] => $status['content']],
            'attachment'   => array_map(fn($m) => [
                'type'      => 'Document',
                'mediaType' => $m['mime_type'],
                'url'       => BASE_URL . '/media/' . $m['file_path'],
                'name'      => $m['description'],
                'width'     => $m['width'],
                'height'    => $m['height'],
            ], $media),
            'tag'          => [],
        ];

        return [
            '@context'  => 'https://www.w3.org/ns/activitystreams',
            'id'        => $status['uri'] . '/activity',
            'type'      => 'Create',
            'actor'     => actorUrl($user['username']),
            'published' => $obj['published'],
            'to'        => $obj['to'],
            'cc'        => $obj['cc'],
            'object'    => $obj,
        ];
    }
}
