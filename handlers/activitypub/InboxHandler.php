<?php
namespace Canticle\Handlers\Activitypub;

use Canticle\Core\{Request, Response, HttpClient, HttpSignature, Queue};
use Canticle\Models\{User, RemoteActor, Follow, Instance, Notification, Status, Media};

class InboxHandler
{
    /**
     * Only store remote posts published within this many days.
     * Older content won't be surfaced in timelines anyway, and storing it
     * wastes space and clutters Explore with stale posts.
     */
    private const REALTIME_WINDOW_DAYS = 30;

    public function userInbox(Request $req, Response $res): void
    {
        $username = $req->param('username');
        $user     = User::findByUsername($username);
        if (!$user) $res->json(['error' => 'Not found'], 404);

        $this->process($req, $res, $user);
    }

    public function sharedInbox(Request $req, Response $res): void
    {
        $this->process($req, $res, null);
    }

    private function process(Request $req, Response $res, ?array $localUser): void
    {
        $body     = $req->rawBody();
        $activity = json_decode($body, true);

        if (!$activity || !isset($activity['type'])) {
            $res->json(['error' => 'Invalid activity'], 400);
        }

        // Verify HTTP signature
        $actorUri = is_array($activity['actor'] ?? null) ? ($activity['actor']['id'] ?? '') : ($activity['actor'] ?? '');
        $actor    = RemoteActor::fetchAndStore($actorUri);

        if (!$actor) {
            $res->json(['error' => 'Could not resolve actor'], 400);
        }

        // Block check
        if (Instance::isBlocked($actor['domain'])) {
            $res->noContent();
        }

        $requestHeaders = getallheaders();
        $headersLower   = [];
        foreach ($requestHeaders as $k => $v) {
            $headersLower[strtolower($k)] = $v;
        }

        $path  = parse_url($req->uri(), PHP_URL_PATH);
        $valid = HttpSignature::verify($req->method(), $path, $headersLower, $actor['public_key']);
        if (!$valid) {
            error_log("[Inbox] Invalid HTTP signature from {$actor['domain']}");
            $res->json(['error' => 'Invalid signature'], 401);
        }

        // Record instance
        Instance::upsert($actor['domain']);

        // Dispatch by type
        match ($activity['type']) {
            'Create'           => $this->handleCreate($activity, $actor, $localUser),
            'Follow'           => $this->handleFollow($activity, $actor),
            'Unfollow', 'Undo' => $this->handleUndo($activity, $actor),
            'Accept'           => $this->handleAccept($activity, $actor),
            'Reject'           => $this->handleReject($activity, $actor),
            'Delete'           => $this->handleDelete($activity, $actor),
            'Announce'         => $this->handleAnnounce($activity, $actor, $localUser),
            'Like'             => $this->handleLike($activity, $actor),
            'Update'           => $this->handleUpdate($activity, $actor),
            default            => null,
        };

        $res->noContent();
    }

    // ── Activity handlers ─────────────────────────────────────────────────────

    private function handleCreate(array $activity, array $actor, ?array $localUser): void
    {
        // ── 1. Dereference object if it arrived as a bare URI ────────────────
        $obj = $activity['object'] ?? [];
        if (is_string($obj) && $obj !== '') {
            $http = new HttpClient(10);
            $fetched = $http->fetchActor($obj);
            $obj = is_array($fetched) ? $fetched : [];
        }

        if (!is_array($obj) || !in_array($obj['type'] ?? '', ['Note', 'Article', 'Page', 'Question'])) return;

        $uri = $obj['id'] ?? '';
        if (!$uri) return;

        // ── 2. Tombstone check — Delete arrived before this Create ───────────
        if ($this->isTombstoned($uri)) {
            error_log("[Inbox] Create ignored — tombstone exists for $uri");
            return;
        }

        // ── 3. Deduplication — already stored ───────────────────────────────
        if (Status::findByUri($uri)) return;

        // ── 4. Age window — ignore posts older than REALTIME_WINDOW_DAYS ────
        $published = $obj['published'] ?? null;
        $createdAt = $published ? gmdate('Y-m-d H:i:s', strtotime($published)) : gmdate('Y-m-d H:i:s');
        if ($createdAt < gmdate('Y-m-d H:i:s', strtotime('-' . self::REALTIME_WINDOW_DAYS . ' days'))) {
            return;
        }

        // ── 5. Relevance guard — only store content relevant to local users ──
        if (!$this->isRelevantCreate($actor, $obj)) return;

        // ── 6. Map visibility ────────────────────────────────────────────────
        $to  = (array) ($obj['to'] ?? []);
        $cc  = (array) ($obj['cc'] ?? []);
        $vis = 'direct';
        if (in_array('https://www.w3.org/ns/activitystreams#Public', $to))       $vis = 'public';
        elseif (in_array('https://www.w3.org/ns/activitystreams#Public', $cc))   $vis = 'unlisted';
        elseif (in_array($actor['followers_url'] ?? '', $cc))                    $vis = 'private';

        // ── 7. Store status ──────────────────────────────────────────────────
        $statusId = Status::create([
            'remote_actor_id' => $actor['id'],
            'uri'             => $uri,
            'url'             => $obj['url'] ?? $uri,
            'content'         => $obj['content'] ?? '',
            'content_warning' => $obj['summary'] ?? '',
            'visibility'      => $vis,
            'language'        => array_key_first($obj['contentMap'] ?? []) ?? 'en',
            'sensitive'       => (int) ($obj['sensitive'] ?? false),
            'reply_to_uri'    => is_string($obj['inReplyTo'] ?? null) ? $obj['inReplyTo'] : null,
            'created_at'      => $createdAt,
        ]);

        $this->saveAttachments($statusId, $obj);

        // Link reply_to_id if we know the parent
        $inReplyTo = is_string($obj['inReplyTo'] ?? null) ? $obj['inReplyTo'] : null;
        if ($inReplyTo) {
            $parent = Status::findByUri($inReplyTo);
            if ($parent) {
                db()->update('statuses', ['reply_to_id' => $parent['id']], 'id = ?', [$statusId]);
                if ($parent['local_user_id']) {
                    Notification::create($parent['local_user_id'], 'mention', null, $actor['id'], $statusId);
                }
            }
        }

        // Also check for direct mentions of local users in the audience
        $localPrefix = rtrim(BASE_URL, '/') . '/users/';
        foreach (array_merge($to, $cc) as $audience) {
            if (is_string($audience) && str_starts_with($audience, $localPrefix)) {
                $username  = basename(parse_url($audience, PHP_URL_PATH));
                $mentioned = User::findByUsername($username);
                if ($mentioned && $mentioned['id'] !== ($parent['local_user_id'] ?? null)) {
                    Notification::create($mentioned['id'], 'mention', null, $actor['id'], $statusId);
                }
            }
        }
    }

    private function handleFollow(array $activity, array $actor): void
    {
        $objectUri = is_array($activity['object'] ?? null) ? ($activity['object']['id'] ?? '') : ($activity['object'] ?? '');
        $localUser = $this->resolveLocalUserFromUri($objectUri);
        if (!$localUser) return;

        // Don't double-insert
        if (Follow::exists(0, $localUser['id'], null) && false) return;

        $state    = $localUser['locked'] ? 'pending' : 'accepted';
        $followId = Follow::create([
            'follower_remote_id' => $actor['id'],
            'followee_local_id'  => $localUser['id'],
            'state'              => $state,
            'uri'                => $activity['id'] ?? null,
        ]);

        if (!$localUser['locked']) {
            User::incrementCount($localUser['id'], 'followers_count');
            Notification::create($localUser['id'], 'follow', null, $actor['id']);
            $this->sendAccept($localUser, $actor, $activity);
        } else {
            Notification::create($localUser['id'], 'follow_request', null, $actor['id']);
        }
    }

    private function handleUndo(array $activity, array $actor): void
    {
        $obj  = $activity['object'] ?? [];
        $type = is_array($obj) ? ($obj['type'] ?? '') : '';

        if ($type === 'Follow') {
            $objectUri = is_array($obj['object'] ?? null) ? ($obj['object']['id'] ?? '') : ($obj['object'] ?? '');
            $localUser = $this->resolveLocalUserFromUri($objectUri);
            if (!$localUser) return;

            $existing = db()->fetch(
                "SELECT * FROM follows WHERE follower_remote_id = ? AND followee_local_id = ?",
                [$actor['id'], $localUser['id']]
            );
            if ($existing && $existing['state'] === 'accepted') {
                User::decrementCount($localUser['id'], 'followers_count');
            }
            db()->delete('follows', 'follower_remote_id = ? AND followee_local_id = ?', [$actor['id'], $localUser['id']]);
        }

        if ($type === 'Like') {
            $statusUri = is_array($obj['object'] ?? null) ? ($obj['object']['id'] ?? '') : ($obj['object'] ?? '');
            $status    = Status::findByUri($statusUri);
            if ($status) {
                db()->delete('favourites', 'status_id = ? AND remote_actor_id = ?', [$status['id'], $actor['id']]);
                db()->query('UPDATE statuses SET favourites_count = GREATEST(0, favourites_count - 1) WHERE id = ?', [$status['id']]);
            }
        }

        if ($type === 'Announce') {
            $announceId = is_array($obj) ? ($obj['id'] ?? '') : (string) $obj;
            if ($announceId) {
                $boostRow = db()->fetch(
                    'SELECT * FROM statuses WHERE uri = ? AND remote_actor_id = ? AND deleted_at IS NULL',
                    [$announceId, $actor['id']]
                );
                if ($boostRow) {
                    db()->update('statuses', ['deleted_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$boostRow['id']]);
                    db()->query(
                        'UPDATE statuses SET reblogs_count = GREATEST(0, reblogs_count - 1) WHERE id = ?',
                        [$boostRow['reblog_of_id']]
                    );
                }
            }
        }
    }

    private function handleAccept(array $activity, array $actor): void
    {
        $obj = $activity['object'] ?? [];
        $uri = is_array($obj) ? ($obj['id'] ?? '') : (string) $obj;

        // Primary: match by Follow activity URI
        $follow = $uri ? db()->fetch("SELECT * FROM follows WHERE uri = ?", [$uri]) : null;

        // Fallback: match by follower + followee actor pair
        if (!$follow && is_array($obj) && ($obj['type'] ?? '') === 'Follow') {
            $followerActorUri = is_array($obj['actor'] ?? null)
                ? ($obj['actor']['id'] ?? (string) $obj['actor'])
                : (string) ($obj['actor'] ?? '');
            $followeeActorUri = is_array($obj['object'] ?? null)
                ? ($obj['object']['id'] ?? (string) $obj['object'])
                : (string) ($obj['object'] ?? '');

            $localUser = null;
            $prefix    = BASE_URL . '/users/';
            if (str_starts_with($followerActorUri, $prefix)) {
                $username  = urldecode(substr($followerActorUri, strlen($prefix)));
                $localUser = User::findByUsername($username);
            }

            $remoteActor = $followeeActorUri ? RemoteActor::findByUri($followeeActorUri) : null;

            if ($localUser && $remoteActor) {
                $follow = db()->fetch(
                    "SELECT * FROM follows
                     WHERE follower_local_id = ? AND followee_remote_id = ? AND state = 'pending'",
                    [$localUser['id'], $remoteActor['id']]
                );
            }
        }

        if ($follow && $follow['state'] !== 'accepted') {
            Follow::accept($follow['id']);
            if ($follow['follower_local_id']) {
                User::incrementCount($follow['follower_local_id'], 'following_count');
                db()->query(
                    'UPDATE remote_actors SET followers_count = followers_count + 1 WHERE id = ?',
                    [$actor['id']]
                );
            }
        }

        // Relay acceptance — mark relay as active
        $actorId = is_array($activity['actor'] ?? null)
            ? ($activity['actor']['id'] ?? '')
            : ($activity['actor'] ?? '');
        if ($actorId) {
            $rows = db()->update('relays', ['status' => 'active'], 'actor_url = ?', [$actorId]);
            if ($rows === 0) {
                $actorDomain = parse_url($actorId, PHP_URL_HOST);
                if ($actorDomain) {
                    db()->update('relays', ['status' => 'active'], 'url LIKE ?', ['%' . $actorDomain . '%']);
                }
            }
        }
    }

    private function handleReject(array $activity, array $actor): void
    {
        $obj = $activity['object'] ?? [];
        $uri = is_array($obj) ? ($obj['id'] ?? '') : $obj;
        db()->update('follows', ['state' => 'rejected'], 'uri = ?', [$uri]);

        $actorId = $activity['actor'] ?? '';
        if ($actorId) {
            db()->query("UPDATE relays SET status = 'rejected' WHERE actor_url = ?", [$actorId]);
        }
    }

    private function handleDelete(array $activity, array $actor): void
    {
        $obj = $activity['object'] ?? [];
        $uri = is_array($obj) ? ($obj['id'] ?? $obj['url'] ?? '') : $obj;
        if (!$uri) return;

        $status = Status::findByUri($uri);
        if ($status) {
            // Only delete if the actor owns this status
            if ($status['remote_actor_id'] === $actor['id']) {
                Status::delete($status['id']);
            }
        } else {
            // Post not stored locally — record a tombstone so a late-arriving
            // Create or Announce for this URI is ignored (Delete arrived first).
            try {
                db()->insert('tombstones', ['uri' => $uri]);
            } catch (\Throwable) {
                // Ignore duplicate URI inserts
            }
        }
    }

    private function handleAnnounce(array $activity, array $actor, ?array $localUser): void
    {
        $objectUri = is_string($activity['object'] ?? null)
            ? $activity['object']
            : ($activity['object']['id'] ?? '');
        if (!$objectUri) return;

        // ── 1. Tombstone check ───────────────────────────────────────────────
        if ($this->isTombstoned($objectUri)) return;

        // ── 2. Relevance guard ───────────────────────────────────────────────
        if (!$this->isRelevantAnnounce($actor, $objectUri)) return;

        // ── 3. Dedup boost row ───────────────────────────────────────────────
        $boostUri = $activity['id'] ?? null;
        if ($boostUri && db()->fetch(
            'SELECT id FROM statuses WHERE uri = ? AND deleted_at IS NULL', [$boostUri]
        )) return;

        // ── 4. Find or fetch the original post ──────────────────────────────
        $original = Status::findByUri($objectUri);
        if (!$original) {
            $http = new HttpClient(10);
            $obj  = $http->fetchActor($objectUri);
            if (!is_array($obj) || !in_array($obj['type'] ?? '', ['Note', 'Article', 'Page', 'Question'])) return;

            // Age check on the original post
            $published = $obj['published'] ?? null;
            $createdAt = $published ? gmdate('Y-m-d H:i:s', strtotime($published)) : gmdate('Y-m-d H:i:s');
            if ($createdAt < gmdate('Y-m-d H:i:s', strtotime('-' . self::REALTIME_WINDOW_DAYS . ' days'))) {
                return;
            }

            // Resolve original author
            $authorUri = is_array($obj['attributedTo'] ?? null)
                ? ($obj['attributedTo']['id'] ?? '')
                : ($obj['attributedTo'] ?? '');
            if (!$authorUri) return;

            $remoteAuthor = RemoteActor::fetchAndStore($authorUri);
            if (!$remoteAuthor) return;

            // Map visibility
            $to  = (array) ($obj['to'] ?? []);
            $cc  = (array) ($obj['cc'] ?? []);
            $vis = 'direct';
            if (in_array('https://www.w3.org/ns/activitystreams#Public', $to))       $vis = 'public';
            elseif (in_array('https://www.w3.org/ns/activitystreams#Public', $cc))   $vis = 'unlisted';
            elseif (in_array($remoteAuthor['followers_url'] ?? '', $cc))              $vis = 'private';

            $statusId = Status::create([
                'remote_actor_id' => $remoteAuthor['id'],
                'uri'             => $obj['id'],
                'url'             => $obj['url'] ?? $obj['id'],
                'content'         => $obj['content'] ?? '',
                'content_warning' => $obj['summary'] ?? '',
                'visibility'      => $vis,
                'language'        => array_key_first($obj['contentMap'] ?? []) ?? 'en',
                'sensitive'       => (int) ($obj['sensitive'] ?? false),
                'reply_to_uri'    => is_string($obj['inReplyTo'] ?? null) ? $obj['inReplyTo'] : null,
                'created_at'      => $createdAt,
            ]);

            $this->saveAttachments($statusId, $obj);
            $original = Status::find($statusId);
            if (!$original) return;
        }

        // ── 5. Store boost row so it appears in followers' home timelines ────
        if ($boostUri) {
            $published = $activity['published'] ?? null;
            $boostedAt = $published ? gmdate('Y-m-d H:i:s', strtotime($published)) : gmdate('Y-m-d H:i:s');

            db()->insert('statuses', [
                'remote_actor_id' => $actor['id'],
                'uri'             => $boostUri,
                'url'             => $boostUri,
                'reblog_of_id'    => $original['id'],
                'content'         => '',
                'visibility'      => 'public',
                'language'        => $original['language'] ?? 'en',
                'created_at'      => $boostedAt,
            ]);
        }

        // Increment boost count and notify local author
        db()->query(
            'UPDATE statuses SET reblogs_count = reblogs_count + 1 WHERE id = ?',
            [$original['id']]
        );
        if ($original['local_user_id']) {
            Notification::create($original['local_user_id'], 'reblog', null, $actor['id'], $original['id']);
        }
    }

    private function handleLike(array $activity, array $actor): void
    {
        $objectUri = is_array($activity['object'] ?? null) ? ($activity['object']['id'] ?? '') : ($activity['object'] ?? '');
        $status    = Status::findByUri($objectUri);
        if (!$status) return;

        try {
            db()->insert('favourites', ['status_id' => $status['id'], 'remote_actor_id' => $actor['id']]);
            db()->query('UPDATE statuses SET favourites_count = favourites_count + 1 WHERE id = ?', [$status['id']]);
            if ($status['local_user_id']) {
                Notification::create($status['local_user_id'], 'favourite', null, $actor['id'], $status['id']);
            }
        } catch (\Throwable) {}
    }

    private function handleUpdate(array $activity, array $actor): void
    {
        $obj = $activity['object'] ?? [];
        if (!is_array($obj)) return;
        if (($obj['type'] ?? '') === 'Note') {
            $status = Status::findByUri($obj['id'] ?? '');
            if ($status && $status['remote_actor_id'] === $actor['id']) {
                db()->update('statuses', [
                    'content'         => $obj['content'] ?? $status['content'],
                    'content_warning' => $obj['summary'] ?? $status['content_warning'],
                    'edited_at'       => gmdate('Y-m-d H:i:s'),
                ], 'id = ?', [$status['id']]);
            }
        }
    }

    // ── Relevance guards ──────────────────────────────────────────────────────

    /**
     * Should we store an incoming Create?
     * Yes if: actor is followed locally, activity mentions a local user,
     * or it is a reply to a local user's post.
     */
    private function isRelevantCreate(array $actor, array $obj): bool
    {
        // Actor is followed by at least one local user
        if (db()->fetch(
            "SELECT id FROM follows WHERE followee_remote_id = ? AND state = 'accepted' LIMIT 1",
            [$actor['id']]
        )) return true;

        // Object addresses a local user in to/cc
        $localPrefix = rtrim(BASE_URL, '/') . '/users/';
        foreach (array_merge((array)($obj['to'] ?? []), (array)($obj['cc'] ?? [])) as $uri) {
            if (is_string($uri) && str_starts_with($uri, $localPrefix)) return true;
        }

        // Reply to a post by a local user
        $inReplyTo = is_string($obj['inReplyTo'] ?? null) ? $obj['inReplyTo'] : null;
        if ($inReplyTo) {
            $parent = Status::findByUri($inReplyTo);
            if ($parent && $parent['local_user_id']) return true;
        }

        return false;
    }

    /**
     * Should we process an incoming Announce (boost)?
     * Yes if: actor is a subscribed relay, actor is followed locally,
     * or the announced post belongs to a local user.
     */
    private function isRelevantAnnounce(array $actor, string $objectUri): bool
    {
        // Actor is a subscribed relay — always process relay traffic
        if (db()->fetch(
            "SELECT id FROM relays WHERE actor_url = ? AND status = 'active' LIMIT 1",
            [$actor['uri'] ?? '']
        )) return true;

        // Actor followed by at least one local user
        if (db()->fetch(
            "SELECT id FROM follows WHERE followee_remote_id = ? AND state = 'accepted' LIMIT 1",
            [$actor['id']]
        )) return true;

        // Announced post is from our own instance (someone boosting our content)
        if (str_starts_with($objectUri, rtrim(BASE_URL, '/') . '/')) return true;

        // Announced post is already locally stored and belongs to a local user
        $original = Status::findByUri($objectUri);
        if ($original && $original['local_user_id']) return true;

        return false;
    }

    // ── Tombstone helpers ─────────────────────────────────────────────────────

    /**
     * Return true if we have recorded a Delete for this URI before the
     * Create/Announce arrived (race condition guard).
     */
    private function isTombstoned(string $uri): bool
    {
        try {
            return (bool) db()->fetch('SELECT id FROM tombstones WHERE uri = ?', [$uri]);
        } catch (\Throwable) {
            return false; // table not yet created — migration pending
        }
    }

    // ── Media attachment helpers ──────────────────────────────────────────────

    /**
     * Persist media attachments from an ActivityPub Note object.
     */
    private function saveAttachments(int $statusId, array $obj): void
    {
        $attachments = $obj['attachment'] ?? [];
        if (!$attachments) return;

        if (isset($attachments['type'])) $attachments = [$attachments];

        foreach ($attachments as $att) {
            if (!is_array($att)) continue;

            $remoteUrl = '';
            if (is_array($att['url'] ?? null)) {
                foreach ($att['url'] as $link) {
                    if (is_array($link) && isset($link['href'])) {
                        $remoteUrl = $link['href'];
                        if (!isset($att['mediaType']) && isset($link['mediaType'])) {
                            $att['mediaType'] = $link['mediaType'];
                        }
                        break;
                    }
                }
            } else {
                $remoteUrl = (string) ($att['url'] ?? '');
            }
            if (!$remoteUrl) continue;

            $mimeType = (string) ($att['mediaType'] ?? '');
            $type = match(true) {
                str_starts_with($mimeType, 'image/') => 'image',
                str_starts_with($mimeType, 'video/') => 'video',
                str_starts_with($mimeType, 'audio/') => 'audio',
                default                              => 'unknown',
            };

            Media::create([
                'status_id'   => $statusId,
                'type'        => $type,
                'remote_url'  => $remoteUrl,
                'mime_type'   => $mimeType,
                'description' => (string) ($att['name'] ?? $att['summary'] ?? ''),
                'width'       => isset($att['width'])  ? (int) $att['width']  : null,
                'height'      => isset($att['height']) ? (int) $att['height'] : null,
                'blurhash'    => (string) ($att['blurhash'] ?? ''),
                'file_path'   => '',
                'file_name'   => basename((string) parse_url($remoteUrl, PHP_URL_PATH)),
                'file_size'   => 0,
            ]);
        }
    }

    // ── Routing helpers ───────────────────────────────────────────────────────

    private function resolveLocalUserFromUri(string $uri): ?array
    {
        if (str_starts_with($uri, BASE_URL . '/users/')) {
            $username = basename(parse_url($uri, PHP_URL_PATH));
            return User::findByUsername($username);
        }
        return null;
    }

    private function sendAccept(array $localUser, array $actor, array $followActivity): void
    {
        $accept = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => actorUrl($localUser['username']) . '#accept-' . time(),
            'type'     => 'Accept',
            'actor'    => actorUrl($localUser['username']),
            'object'   => $followActivity,
        ];

        $queue = new Queue(db());
        $queue->push('DeliverActivity', [
            'activity'    => $accept,
            'inbox_url'   => $actor['inbox_url'],
            'actor_url'   => actorUrl($localUser['username']) . '#main-key',
            'private_key' => $localUser['private_key'],
        ]);
    }
}
