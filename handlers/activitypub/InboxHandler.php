<?php
namespace Canticle\Handlers\Activitypub;

use Canticle\Core\{Request, Response, HttpClient, HttpSignature, Queue};
use Canticle\Models\{User, RemoteActor, Follow, Instance, Notification, Status, Media};

class InboxHandler
{
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
        $actorUri  = is_array($activity['actor'] ?? null) ? ($activity['actor']['id'] ?? '') : ($activity['actor'] ?? '');
        $actor     = RemoteActor::fetchAndStore($actorUri);

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

        $path = parse_url($req->uri(), PHP_URL_PATH);
        $valid = HttpSignature::verify($req->method(), $path, $headersLower, $actor['public_key']);
        if (!$valid) {
            error_log("[Inbox] Invalid HTTP signature from {$actor['domain']}");
            $res->json(['error' => 'Invalid signature'], 401);
        }

        // Record instance
        Instance::upsert($actor['domain']);

        // Dispatch by type
        match ($activity['type']) {
            'Create'  => $this->handleCreate($activity, $actor, $localUser),
            'Follow'  => $this->handleFollow($activity, $actor),
            'Unfollow', 'Undo' => $this->handleUndo($activity, $actor),
            'Accept'  => $this->handleAccept($activity, $actor),
            'Reject'  => $this->handleReject($activity, $actor),
            'Delete'  => $this->handleDelete($activity, $actor),
            'Announce'=> $this->handleAnnounce($activity, $actor, $localUser),
            'Like'    => $this->handleLike($activity, $actor),
            'Update'  => $this->handleUpdate($activity, $actor),
            default   => null,
        };

        $res->noContent();
    }

    private function handleCreate(array $activity, array $actor, ?array $localUser): void
    {
        $obj = $activity['object'] ?? [];
        if (!is_array($obj) || ($obj['type'] ?? '') !== 'Note') return;

        $uri = $obj['id'] ?? '';
        if (!$uri || Status::findByUri($uri)) return;

        // Map visibility
        $to  = (array) ($obj['to'] ?? []);
        $cc  = (array) ($obj['cc'] ?? []);
        $vis = 'direct';
        if (in_array('https://www.w3.org/ns/activitystreams#Public', $to)) $vis = 'public';
        elseif (in_array('https://www.w3.org/ns/activitystreams#Public', $cc)) $vis = 'unlisted';
        elseif (in_array($actor['followers_url'], $cc)) $vis = 'private';

        $published = $obj['published'] ?? null;
        $createdAt = $published ? gmdate('Y-m-d H:i:s', strtotime($published)) : gmdate('Y-m-d H:i:s');

        $statusId = Status::create([
            'remote_actor_id' => $actor['id'],
            'uri'             => $uri,
            'url'             => $obj['url'] ?? $uri,
            'content'         => $obj['content'] ?? '',
            'content_warning' => $obj['summary'] ?? '',
            'visibility'      => $vis,
            'language'        => array_key_first($obj['contentMap'] ?? []) ?? 'en',
            'sensitive'       => (int) ($obj['sensitive'] ?? false),
            'reply_to_uri'    => $obj['inReplyTo'] ?? null,
            'created_at'      => $createdAt,
        ]);

        $this->saveAttachments($statusId, $obj);

        // Link reply_to_id if we know the parent
        if ($obj['inReplyTo'] ?? null) {
            $parent = Status::findByUri($obj['inReplyTo']);
            if ($parent) {
                db()->update('statuses', ['reply_to_id' => $parent['id']], 'id = ?', [$statusId]);
                if ($parent['local_user_id']) {
                    Notification::create($parent['local_user_id'], 'mention', null, $actor['id'], $statusId);
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

        $state = $localUser['locked'] ? 'pending' : 'accepted';
        $followId = Follow::create([
            'follower_remote_id' => $actor['id'],
            'followee_local_id'  => $localUser['id'],
            'state'              => $state,
            'uri'                => $activity['id'] ?? null,
        ]);

        if (!$localUser['locked']) {
            User::incrementCount($localUser['id'], 'followers_count');
            Notification::create($localUser['id'], 'follow', null, $actor['id']);

            // Send Accept
            $this->sendAccept($localUser, $actor, $activity);
        } else {
            Notification::create($localUser['id'], 'follow_request', null, $actor['id']);
        }
    }

    private function handleUndo(array $activity, array $actor): void
    {
        $obj = $activity['object'] ?? [];
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
            $status = Status::findByUri($statusUri);
            if ($status) {
                db()->delete('favourites', 'status_id = ? AND remote_actor_id = ?', [$status['id'], $actor['id']]);
                db()->query('UPDATE statuses SET favourites_count = GREATEST(0, favourites_count - 1) WHERE id = ?', [$status['id']]);
            }
        }
    }

    private function handleAccept(array $activity, array $actor): void
    {
        $obj = $activity['object'] ?? [];
        $uri = is_array($obj) ? ($obj['id'] ?? '') : (string) $obj;

        // ── Primary: match the pending follow by the Follow activity URI ──────
        $follow = $uri ? db()->fetch("SELECT * FROM follows WHERE uri = ?", [$uri]) : null;

        // ── Fallback: match by follower + followee actor pair ─────────────────
        // Mastodon and some other servers embed the full Follow activity as the
        // object of the Accept. If the URI lookup fails (e.g. URI encoding
        // differences, timing issues), extract the actor pair and look for a
        // pending follow that way.
        if (!$follow && is_array($obj) && ($obj['type'] ?? '') === 'Follow') {
            $followerActorUri = is_array($obj['actor'] ?? null)
                ? ($obj['actor']['id'] ?? (string) $obj['actor'])
                : (string) ($obj['actor'] ?? '');
            $followeeActorUri = is_array($obj['object'] ?? null)
                ? ($obj['object']['id'] ?? (string) $obj['object'])
                : (string) ($obj['object'] ?? '');

            // Resolve our local user from their actor URI
            $localUser = null;
            $prefix    = BASE_URL . '/users/';
            if (str_starts_with($followerActorUri, $prefix)) {
                $username  = urldecode(substr($followerActorUri, strlen($prefix)));
                $localUser = User::findByUsername($username);
            }

            // Resolve the remote actor being followed
            $remoteActor = $followeeActorUri ? RemoteActor::findByUri($followeeActorUri) : null;

            if ($localUser && $remoteActor) {
                $follow = db()->fetch(
                    "SELECT * FROM follows
                     WHERE follower_local_id = ? AND followee_remote_id = ? AND state = 'pending'",
                    [$localUser['id'], $remoteActor['id']]
                );
            }
        }

        // ── Accept the follow ─────────────────────────────────────────────────
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

        // Relay acceptance — mark relay as active.
        // Match on actor_url (exact) or by domain as fallback.
        $actorId = is_array($activity['actor'] ?? null)
            ? ($activity['actor']['id'] ?? '')
            : ($activity['actor'] ?? '');
        if ($actorId) {
            $rows = db()->update('relays', ['status' => 'active'], 'actor_url = ?', [$actorId]);
            if ($rows === 0) {
                // Fallback: match by domain in case stored actor_url differs slightly
                $actorDomain = parse_url($actorId, PHP_URL_HOST);
                if ($actorDomain) {
                    db()->update('relays', ['status' => 'active'], 'url LIKE ?', ['%' . $actorDomain . '%']);
                }
            }
        }
    }

    /**
     * Persist media attachments from an ActivityPub Note object.
     * Handles Document/Image/Video/Audio types; stores the remote URL so
     * the Mastodon API and web UI can display them without downloading files.
     */
    private function saveAttachments(int $statusId, array $obj): void
    {
        $attachments = $obj['attachment'] ?? [];
        if (!$attachments) return;

        // Some servers send a single object instead of an array
        if (isset($attachments['type'])) $attachments = [$attachments];

        foreach ($attachments as $att) {
            if (!is_array($att)) continue;

            // The media URL — required; skip anything without one
            $remoteUrl = '';
            if (is_array($att['url'] ?? null)) {
                // Some servers send url as an array of links; pick the first with a mediaType
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

    private function handleReject(array $activity, array $actor): void
    {
        $obj = $activity['object'] ?? [];
        $uri = is_array($obj) ? ($obj['id'] ?? '') : $obj;
        db()->update('follows', ['state' => 'rejected'], 'uri = ?', [$uri]);

        // Mark relay as rejected if applicable
        $actorId = $activity['actor'] ?? '';
        if ($actorId) {
            db()->query(
                "UPDATE relays SET status = 'rejected' WHERE actor_url = ?",
                [$actorId]
            );
        }
    }

    private function handleDelete(array $activity, array $actor): void
    {
        $obj = $activity['object'] ?? [];
        $uri = is_array($obj) ? ($obj['id'] ?? $obj['url'] ?? '') : $obj;
        if ($uri) {
            $status = Status::findByUri($uri);
            if ($status && $status['remote_actor_id'] === $actor['id']) {
                Status::delete($status['id']);
            }
        }
    }

    private function handleAnnounce(array $activity, array $actor, ?array $localUser): void
    {
        $objectUri = is_array($activity['object'] ?? null) ? ($activity['object']['id'] ?? '') : ($activity['object'] ?? '');
        if (!$objectUri) return;

        // Find or fetch the original post
        $original = Status::findByUri($objectUri);
        if (!$original) {
            // The post doesn't exist locally yet — fetch it from the remote server
            $http = new HttpClient(10);
            $obj  = $http->fetchActor($objectUri); // works for any JSON-LD doc
            if (!is_array($obj) || ($obj['type'] ?? '') !== 'Note') return;

            // Resolve the original author
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

            $published = $obj['published'] ?? null;
            $createdAt = $published ? gmdate('Y-m-d H:i:s', strtotime($published)) : gmdate('Y-m-d H:i:s');

            $statusId = Status::create([
                'remote_actor_id' => $remoteAuthor['id'],
                'uri'             => $obj['id'],
                'url'             => $obj['url'] ?? $obj['id'],
                'content'         => $obj['content'] ?? '',
                'content_warning' => $obj['summary'] ?? '',
                'visibility'      => $vis,
                'language'        => array_key_first($obj['contentMap'] ?? []) ?? 'en',
                'sensitive'       => (int) ($obj['sensitive'] ?? false),
                'reply_to_uri'    => $obj['inReplyTo'] ?? null,
                'created_at'      => $createdAt,
            ]);

            $this->saveAttachments($statusId, $obj);

            $original = Status::find($statusId);
            if (!$original) return;
        }

        // Increment boost count
        db()->query(
            'UPDATE statuses SET reblogs_count = reblogs_count + 1 WHERE id = ?',
            [$original['id']]
        );

        // Notify local author if the original post is ours
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
                    'content'        => $obj['content'] ?? $status['content'],
                    'content_warning'=> $obj['summary'] ?? $status['content_warning'],
                    'edited_at'      => date('Y-m-d H:i:s'),
                ], 'id = ?', [$status['id']]);
            }
        }
    }

    private function resolveLocalUserFromUri(string $uri): ?array
    {
        // https://domain/users/username
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
