<?php
namespace Canticle\Handlers\Api;

use Canticle\Core\{Request, Response, Auth, Federator, Queue};
use Canticle\Models\{User, Status, Poll, Hashtag, Mention, Notification};

class StatusesHandler
{
    /** POST /api/v1/statuses */
    public function create(Request $req, Response $res): void
    {
        $user = Auth::requireUser($res);
        if (!Auth::hasScope('write:statuses')) $res->error('Insufficient scope', 403);

        $content        = trim($req->input('status', ''));
        $contentWarning = trim($req->input('spoiler_text', ''));
        $visibility     = $req->input('visibility', 'public');
        $sensitive      = (bool) $req->input('sensitive', false);
        $language       = $req->input('language', config('site_lang', 'en'));
        $replyToId      = $req->input('in_reply_to_id') ? (int) $req->input('in_reply_to_id') : null;
        $mediaIds       = (array) ($req->input('media_ids') ?? []);
        $pollOptions    = $req->input('poll') ? (array) $req->input('poll') : null;

        $maxChars = (int) config('max_chars', 500);
        if (mb_strlen(strip_tags($content)) > $maxChars && !$mediaIds) {
            $res->error("Status exceeds $maxChars characters", 422);
        }

        if (!$content && !$mediaIds) {
            $res->error('Status content is required', 422);
        }

        if (!in_array($visibility, ['public','unlisted','private','direct'])) {
            $visibility = 'public';
        }

        // Content: convert line breaks to <br>, linkify mentions + hashtags
        $html = $this->formatContent($content, $user['username']);

        $replyToUri = null;
        if ($replyToId) {
            $parent = Status::find($replyToId);
            $replyToUri = $parent['uri'] ?? null;
        }

        $statusId = Status::create([
            'local_user_id'  => $user['id'],
            'uri'            => actorUrl($user['username']) . '/statuses/' . uniqid('', true),
            'url'            => BASE_URL . '/@' . $user['username'],
            'reply_to_id'    => $replyToId,
            'reply_to_uri'   => $replyToUri,
            'content'        => $html,
            'content_warning'=> $contentWarning,
            'visibility'     => $visibility,
            'language'       => $language,
            'sensitive'      => $sensitive ? 1 : 0,
        ]);

        // Set canonical URL
        $canonicalUrl = BASE_URL . '/@' . $user['username'] . '/' . $statusId;
        $canonicalUri = actorUrl($user['username']) . '/statuses/' . $statusId;
        db()->update('statuses', ['uri' => $canonicalUri, 'url' => $canonicalUrl], 'id = ?', [$statusId]);

        // Attach media
        foreach ($mediaIds as $mId) {
            \Canticle\Models\Media::attachToStatus((int) $mId, $statusId);
        }

        // Poll
        if ($pollOptions && isset($pollOptions['options'])) {
            $maxOptions = (int) config('max_poll_options', 4);
            $options    = array_slice($pollOptions['options'], 0, $maxOptions);
            $multiple   = (bool) ($pollOptions['multiple'] ?? false);
            $expires    = isset($pollOptions['expires_in'])
                ? date('Y-m-d H:i:s', time() + (int) $pollOptions['expires_in'])
                : null;
            Poll::create($statusId, $options, $multiple, $expires);
        }

        // Extract hashtags and mentions
        Hashtag::extractAndLink($statusId, $content);
        Mention::extractAndLink($statusId, $content, $user['id']);

        // Reply notification
        if ($replyToId) {
            $parent = Status::find($replyToId);
            if ($parent && $parent['local_user_id'] && $parent['local_user_id'] !== $user['id']) {
                Notification::create($parent['local_user_id'], 'mention', $user['id'], null, $statusId);
            }
            if ($parent) {
                db()->query('UPDATE statuses SET replies_count = replies_count + 1 WHERE id = ?', [$parent['id']]);
            }
        }

        // Federate — public, unlisted, and private (followers-only) are all
        // delivered to remote followers. Direct messages are not federated here
        // (mention-based DM delivery would require per-recipient addressing).
        $status = Status::find($statusId);
        if ($status && in_array($visibility, ['public', 'unlisted', 'private'])) {
            $fed = new Federator(new Queue(db()));
            $fed->deliverStatus($status, $user);
        }

        $res->json(Status::toMastodon($status, $user), 200);
    }

    /** GET /api/v1/statuses/:id */
    public function show(Request $req, Response $res): void
    {
        $status = Status::find((int) $req->param('id'));
        if (!$status) $res->error('Not found', 404);
        $viewer = Auth::user();
        $res->json(Status::toMastodon($status, $viewer));
    }

    /** DELETE /api/v1/statuses/:id */
    public function delete(Request $req, Response $res): void
    {
        $user   = Auth::requireUser($res);
        $status = Status::find((int) $req->param('id'));
        if (!$status) $res->error('Not found', 404);
        if ($status['local_user_id'] !== $user['id'] && $user['role'] === 'user') {
            $res->error('Forbidden', 403);
        }

        $serialized = Status::toMastodon($status, $user);
        Status::delete($status['id']);

        // Federate delete
        $fed = new Federator(new Queue(db()));
        $fed->deleteStatus($status, $user);

        $res->json($serialized);
    }

    /** POST /api/v1/statuses/:id/favourite */
    public function favourite(Request $req, Response $res): void
    {
        $user   = Auth::requireUser($res);
        $status = Status::find((int) $req->param('id'));
        if (!$status) $res->error('Not found', 404);

        try {
            db()->insert('favourites', ['status_id' => $status['id'], 'local_user_id' => $user['id']]);
            db()->query('UPDATE statuses SET favourites_count = favourites_count + 1 WHERE id = ?', [$status['id']]);
            if ($status['local_user_id'] && $status['local_user_id'] !== $user['id']) {
                Notification::create($status['local_user_id'], 'favourite', $user['id'], null, $status['id']);
            }
            // Like remote status
            if ($status['remote_actor_id']) {
                $fed = new Federator(new Queue(db()));
                $fed->sendLike($user, $status);
            }
        } catch (\Throwable) {}

        $res->json(Status::toMastodon(Status::find($status['id']), $user));
    }

    /** POST /api/v1/statuses/:id/unfavourite */
    public function unfavourite(Request $req, Response $res): void
    {
        $user   = Auth::requireUser($res);
        $status = Status::find((int) $req->param('id'));
        if (!$status) $res->error('Not found', 404);

        db()->delete('favourites', 'status_id = ? AND local_user_id = ?', [$status['id'], $user['id']]);
        db()->query('UPDATE statuses SET favourites_count = GREATEST(0, favourites_count - 1) WHERE id = ?', [$status['id']]);

        $res->json(Status::toMastodon(Status::find($status['id']), $user));
    }

    /** POST /api/v1/statuses/:id/reblog */
    public function reblog(Request $req, Response $res): void
    {
        $user   = Auth::requireUser($res);
        $status = Status::find((int) $req->param('id'));
        if (!$status) $res->error('Not found', 404);

        $existing = db()->fetch(
            'SELECT id FROM statuses WHERE local_user_id = ? AND reblog_of_id = ? AND deleted_at IS NULL',
            [$user['id'], $status['id']]
        );
        if ($existing) {
            $res->json(Status::toMastodon(Status::find($existing['id']), $user));
            return;
        }

        $boostUri = actorUrl($user['username']) . '/statuses/' . uniqid('', true);
        $boostId  = Status::create([
            'local_user_id' => $user['id'],
            'uri'           => $boostUri,
            'url'           => BASE_URL . '/@' . $user['username'],
            'reblog_of_id'  => $status['id'],
            'content'       => '',
            'visibility'    => 'public',
            'language'      => $status['language'],
        ]);
        db()->query('UPDATE statuses SET reblogs_count = reblogs_count + 1 WHERE id = ?', [$status['id']]);

        if ($status['local_user_id'] && $status['local_user_id'] !== $user['id']) {
            Notification::create($status['local_user_id'], 'reblog', $user['id'], null, $status['id']);
        }

        // Federate the Announce activity to followers (and original post's server)
        $boostStatus = Status::find($boostId);
        $fed = new Federator(new Queue(db()));
        $fed->sendAnnounce($user, $boostStatus, $status);

        $res->json(Status::toMastodon($boostStatus, $user));
    }

    /** POST /api/v1/statuses/:id/pin */
    public function pin(Request $req, Response $res): void
    {
        $user   = Auth::requireUser($res);
        $status = Status::find((int) $req->param('id'));
        if (!$status) $res->error('Not found', 404);
        if ($status['local_user_id'] !== $user['id']) $res->error('Forbidden', 403);

        Status::pin($status['id']);
        $res->json(Status::toMastodon(Status::find($status['id']), $user));
    }

    /** POST /api/v1/statuses/:id/unpin */
    public function unpin(Request $req, Response $res): void
    {
        $user   = Auth::requireUser($res);
        $status = Status::find((int) $req->param('id'));
        if (!$status) $res->error('Not found', 404);
        if ($status['local_user_id'] !== $user['id']) $res->error('Forbidden', 403);

        Status::unpin($status['id']);
        $res->json(Status::toMastodon(Status::find($status['id']), $user));
    }

    /** POST /api/v1/statuses/:id/unreblog */
    public function unreblog(Request $req, Response $res): void
    {
        $user   = Auth::requireUser($res);
        $status = Status::find((int) $req->param('id'));
        if (!$status) $res->error('Not found', 404);

        $boost = db()->fetch(
            'SELECT * FROM statuses WHERE local_user_id = ? AND reblog_of_id = ? AND deleted_at IS NULL',
            [$user['id'], $status['id']]
        );
        if ($boost) {
            // Federate the Undo Announce before deleting the boost row
            $fed = new Federator(new Queue(db()));
            $fed->sendUndoAnnounce($user, $boost, $status);

            Status::delete($boost['id']);
            db()->query('UPDATE statuses SET reblogs_count = GREATEST(0, reblogs_count - 1) WHERE id = ?', [$status['id']]);
        }

        $res->json(Status::toMastodon($status, $user));
    }

    /** GET /api/v1/statuses/:id/context */
    public function context(Request $req, Response $res): void
    {
        $status = Status::find((int) $req->param('id'));
        if (!$status) $res->error('Not found', 404);
        $viewer = Auth::user();

        // Ancestors
        $ancestors = [];
        $current   = $status;
        while ($current['reply_to_id']) {
            $parent = Status::find($current['reply_to_id']);
            if (!$parent) break;
            array_unshift($ancestors, Status::toMastodon($parent, $viewer));
            $current = $parent;
        }

        // Descendants
        $descendants = [];
        $children    = db()->fetchAll(
            'SELECT * FROM statuses WHERE reply_to_id = ? AND deleted_at IS NULL ORDER BY id ASC',
            [$status['id']]
        );
        foreach ($children as $child) {
            $descendants[] = Status::toMastodon($child, $viewer);
        }

        $res->json(['ancestors' => $ancestors, 'descendants' => $descendants]);
    }

    /** GET /api/v1/statuses/:id/favourited_by */
    public function favouritedBy(Request $req, Response $res): void
    {
        $status = Status::find((int) $req->param('id'));
        if (!$status) $res->error('Not found', 404);

        $rows = db()->fetchAll(
            'SELECT u.* FROM favourites f JOIN users u ON f.local_user_id = u.id WHERE f.status_id = ? LIMIT 40',
            [$status['id']]
        );
        $res->json(array_map(fn($u) => User::toMastodon($u), $rows));
    }

    /** GET /api/v1/statuses/:id/reblogged_by */
    public function rebloggedBy(Request $req, Response $res): void
    {
        $status = Status::find((int) $req->param('id'));
        if (!$status) $res->error('Not found', 404);

        $rows = db()->fetchAll(
            'SELECT u.* FROM statuses s JOIN users u ON s.local_user_id = u.id
             WHERE s.reblog_of_id = ? AND s.deleted_at IS NULL LIMIT 40',
            [$status['id']]
        );
        $res->json(array_map(fn($u) => User::toMastodon($u), $rows));
    }

    private function formatContent(string $text, string $authorUsername): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Convert newlines to <br>
        $escaped = nl2br($escaped);
        // Linkify URLs
        $escaped = preg_replace(
            '/(https?:\/\/[^\s<>"\']+)/',
            '<a href="$1" rel="nofollow noopener noreferrer" target="_blank">$1</a>',
            $escaped
        );
        // Linkify hashtags
        $escaped = preg_replace(
            '/#([a-zA-Z0-9_]+)/',
            '<a href="' . BASE_URL . '/tags/$1" class="mention hashtag" rel="tag">#<span>$1</span></a>',
            $escaped
        );
        // Linkify @mentions (local only here; remote resolved separately)
        $escaped = preg_replace(
            '/@([a-zA-Z0-9_]+)(?:@([a-zA-Z0-9._-]+))?/',
            '<a href="' . BASE_URL . '/@$1" class="mention">@<span>$1</span></a>',
            $escaped
        );
        return '<p>' . $escaped . '</p>';
    }
}
