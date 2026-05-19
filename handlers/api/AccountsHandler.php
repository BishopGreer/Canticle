<?php
namespace Canticle\Handlers\Api;

use Canticle\Core\{Request, Response, Auth, Federator, Queue};
use Canticle\Models\{User, RemoteActor, Follow, Block, Mute, Notification, Status};

class AccountsHandler
{
    /** GET /api/v1/accounts/verify_credentials */
    public function verifyCredentials(Request $req, Response $res): void
    {
        $user = Auth::requireUser($res);
        $res->json(User::toMastodon($user, true));
    }

    /** PATCH /api/v1/accounts/update_credentials */
    public function updateCredentials(Request $req, Response $res): void
    {
        $user = Auth::requireUser($res);
        $data = [];

        if (($v = $req->input('display_name')) !== null) $data['display_name'] = substr($v, 0, 128);
        if (($v = $req->input('note'))         !== null) $data['bio']          = substr($v, 0, 500);
        if (($v = $req->input('locked'))       !== null) $data['locked']       = $v ? 1 : 0;
        if (($v = $req->input('bot'))          !== null) $data['bot']          = $v ? 1 : 0;
        if (($v = $req->input('discoverable')) !== null) $data['discoverable'] = $v ? 1 : 0;

        if ($data) User::update($user['id'], $data);

        $updated = User::find($user['id']);
        $res->json(User::toMastodon($updated, true));
    }

    /** GET /api/v1/accounts/:id */
    public function show(Request $req, Response $res): void
    {
        $id   = (int) $req->param('id');
        $user = User::find($id);
        if ($user) {
            $res->json(User::toMastodon($user));
        }
        $actor = RemoteActor::find($id);
        if ($actor) {
            $res->json(RemoteActor::toMastodon($actor));
        }
        $res->error('Not found', 404);
    }

    /** GET /api/v1/accounts/:id/statuses */
    public function statuses(Request $req, Response $res): void
    {
        $id      = (int) $req->param('id');
        $limit   = min((int) $req->query('limit', 20), 40);
        $maxId   = $req->query('max_id') ? (int) $req->query('max_id') : null;
        $sinceId = $req->query('since_id') ? (int) $req->query('since_id') : null;

        $viewer  = Auth::user();
        $rows    = db()->fetchAll(
            "SELECT * FROM statuses WHERE local_user_id = ? AND deleted_at IS NULL
             " . ($maxId   ? "AND id < $maxId"   : '') . "
             " . ($sinceId ? "AND id > $sinceId" : '') . "
             ORDER BY id DESC LIMIT " . (int) $limit,
            [$id]
        );
        $res->json(Status::manyToMastodon($rows, $viewer));
    }

    /** GET /api/v1/accounts/:id/followers */
    public function followers(Request $req, Response $res): void
    {
        $id    = (int) $req->param('id');
        $limit = min((int) $req->query('limit', 20), 80);
        $rows  = db()->fetchAll(
            "SELECT u.* FROM follows f JOIN users u ON f.follower_local_id = u.id
             WHERE f.followee_local_id = ? AND f.state = 'accepted' LIMIT " . (int) $limit,
            [$id]
        );
        $res->json(array_map(fn($u) => User::toMastodon($u), $rows));
    }

    /** GET /api/v1/accounts/:id/following */
    public function following(Request $req, Response $res): void
    {
        $id    = (int) $req->param('id');
        $limit = min((int) $req->query('limit', 20), 80);
        $rows  = db()->fetchAll(
            "SELECT u.* FROM follows f JOIN users u ON f.followee_local_id = u.id
             WHERE f.follower_local_id = ? AND f.state = 'accepted' LIMIT " . (int) $limit,
            [$id]
        );
        $res->json(array_map(fn($u) => User::toMastodon($u), $rows));
    }

    /** POST /api/v1/accounts/:id/follow */
    public function follow(Request $req, Response $res): void
    {
        $user   = Auth::requireUser($res);
        $target = User::find((int) $req->param('id'));

        if ($target) {
            // Local follow
            if (!Follow::exists($user['id'], $target['id'])) {
                $state = $target['locked'] ? 'pending' : 'accepted';
                Follow::create([
                    'follower_local_id' => $user['id'],
                    'followee_local_id' => $target['id'],
                    'state'             => $state,
                    'uri'               => actorUrl($user['username']) . '#follow-' . $target['id'] . '-' . time(),
                ]);
                if ($state === 'accepted') {
                    User::incrementCount($user['id'],   'following_count');
                    User::incrementCount($target['id'], 'followers_count');
                    Notification::create($target['id'], 'follow', $user['id']);
                } else {
                    Notification::create($target['id'], 'follow_request', $user['id']);
                }
            }
        } else {
            // Remote follow via actor ID stored in remote_actors
            $actor = RemoteActor::find((int) $req->param('id'));
            if ($actor && !Follow::exists($user['id'], null, $actor['id'])) {
                Follow::create([
                    'follower_local_id'  => $user['id'],
                    'followee_remote_id' => $actor['id'],
                    'state'              => 'pending',
                    'uri'                => actorUrl($user['username']) . '#follow-' . $actor['id'] . '-' . time(),
                ]);
                $fed = new Federator(new Queue(db()));
                $fed->sendFollow($user, $actor);
            }
        }

        $res->json(Follow::relationships($user['id'], [(int) $req->param('id')])[0] ?? []);
    }

    /** POST /api/v1/accounts/:id/unfollow */
    public function unfollow(Request $req, Response $res): void
    {
        $user     = Auth::requireUser($res);
        $targetId = (int) $req->param('id');

        Follow::remove($user['id'], $targetId);

        // Remote
        $actor = RemoteActor::find($targetId);
        if ($actor) {
            $follow = db()->fetch("SELECT * FROM follows WHERE follower_local_id = ? AND followee_remote_id = ?", [$user['id'], $targetId]);
            if ($follow) {
                db()->delete('follows', 'id = ?', [$follow['id']]);
                $fed = new Federator(new Queue(db()));
                $fed->sendUnfollow($user, $actor, $follow['uri'] ?? '');
            }
        }

        $res->json(Follow::relationships($user['id'], [$targetId])[0] ?? []);
    }

    /** POST /api/v1/remote_accounts/:id/follow — follow a remote actor by remote_actors.id (avoids local-user ID collision) */
    public function followRemote(Request $req, Response $res): void
    {
        $user  = Auth::requireUser($res);
        $actor = RemoteActor::find((int) $req->param('id'));
        if (!$actor) $res->error('Not found', 404);

        if (!Follow::exists($user['id'], null, $actor['id'])) {
            Follow::create([
                'follower_local_id'  => $user['id'],
                'followee_remote_id' => $actor['id'],
                'state'              => 'pending',
                'uri'                => actorUrl($user['username']) . '#follow-r' . $actor['id'] . '-' . time(),
            ]);
            $fed = new Federator(new Queue(db()));
            $fed->sendFollow($user, $actor);
        }

        $res->json(['following' => true, 'requested' => true]);
    }

    /** POST /api/v1/remote_accounts/:id/unfollow */
    public function unfollowRemote(Request $req, Response $res): void
    {
        $user  = Auth::requireUser($res);
        $actor = RemoteActor::find((int) $req->param('id'));
        if (!$actor) $res->error('Not found', 404);

        $follow = db()->fetch(
            "SELECT * FROM follows WHERE follower_local_id = ? AND followee_remote_id = ?",
            [$user['id'], $actor['id']]
        );
        if ($follow) {
            db()->delete('follows', 'id = ?', [$follow['id']]);
            User::decrementCount($user['id'], 'following_count');
            $fed = new Federator(new Queue(db()));
            $fed->sendUnfollow($user, $actor, $follow['uri'] ?? '');
        }

        $res->json(['following' => false, 'requested' => false]);
    }

    /** POST /api/v1/accounts/:id/block */
    public function block(Request $req, Response $res): void
    {
        $user     = Auth::requireUser($res);
        $targetId = (int) $req->param('id');
        Block::block($user['id'], $targetId);
        $res->json(Follow::relationships($user['id'], [$targetId])[0] ?? []);
    }

    /** POST /api/v1/accounts/:id/unblock */
    public function unblock(Request $req, Response $res): void
    {
        $user     = Auth::requireUser($res);
        $targetId = (int) $req->param('id');
        Block::unblock($user['id'], $targetId);
        $res->json(Follow::relationships($user['id'], [$targetId])[0] ?? []);
    }

    /** POST /api/v1/accounts/:id/mute */
    public function mute(Request $req, Response $res): void
    {
        $user          = Auth::requireUser($res);
        $targetId      = (int) $req->param('id');
        $notifications = (bool) $req->input('notifications', true);
        $duration      = $req->input('duration') ? (int) $req->input('duration') : null;
        Mute::mute($user['id'], $targetId, null, $notifications, $duration);
        $res->json(Follow::relationships($user['id'], [$targetId])[0] ?? []);
    }

    /** POST /api/v1/accounts/:id/unmute */
    public function unmute(Request $req, Response $res): void
    {
        $user     = Auth::requireUser($res);
        $targetId = (int) $req->param('id');
        Mute::unmute($user['id'], $targetId);
        $res->json(Follow::relationships($user['id'], [$targetId])[0] ?? []);
    }

    /** GET /api/v1/accounts/relationships */
    public function relationships(Request $req, Response $res): void
    {
        $user = Auth::requireUser($res);
        $ids  = (array) ($req->query('id') ?? []);
        if (!$ids) $res->json([]);
        $res->json(Follow::relationships($user['id'], array_map('intval', $ids)));
    }

    /** GET /api/v1/accounts/search */
    public function search(Request $req, Response $res): void
    {
        $q     = trim($req->query('q', ''));
        $limit = min((int) $req->query('limit', 10), 40);
        if (!$q) $res->json([]);

        $like  = '%' . $q . '%';
        $local = db()->fetchAll(
            "SELECT * FROM users WHERE (username LIKE ? OR display_name LIKE ?) AND suspended = 0 LIMIT " . (int) $limit,
            [$like, $like]
        );
        $res->json(array_map(fn($u) => User::toMastodon($u), $local));
    }

    /** GET /api/v1/blocks */
    public function blocks(Request $req, Response $res): void
    {
        $user  = Auth::requireUser($res);
        $items = Block::listForUser($user['id']);
        $out   = [];
        foreach ($items as $b) {
            $u = $b['blocked_local_id'] ? User::find($b['blocked_local_id']) : null;
            if ($u) $out[] = User::toMastodon($u);
        }
        $res->json($out);
    }

    /** GET /api/v1/mutes */
    public function mutes(Request $req, Response $res): void
    {
        $user  = Auth::requireUser($res);
        $items = Mute::listForUser($user['id']);
        $out   = [];
        foreach ($items as $m) {
            $u = $m['muted_local_id'] ? User::find($m['muted_local_id']) : null;
            if ($u) $out[] = User::toMastodon($u);
        }
        $res->json($out);
    }

    /** GET /api/v1/follow_requests */
    public function followRequests(Request $req, Response $res): void
    {
        $user = Auth::requireUser($res);
        $rows = db()->fetchAll(
            "SELECT u.* FROM follows f JOIN users u ON f.follower_local_id = u.id
             WHERE f.followee_local_id = ? AND f.state = 'pending'",
            [$user['id']]
        );
        $res->json(array_map(fn($u) => User::toMastodon($u), $rows));
    }

    /** POST /api/v1/follow_requests/:id/authorize */
    public function authorizeFollowRequest(Request $req, Response $res): void
    {
        $user = Auth::requireUser($res);
        $requesterId = (int) $req->param('id');
        $follow = db()->fetch(
            "SELECT * FROM follows WHERE follower_local_id = ? AND followee_local_id = ? AND state = 'pending'",
            [$requesterId, $user['id']]
        );
        if ($follow) {
            Follow::accept($follow['id']);
            User::incrementCount($user['id'],       'followers_count');
            User::incrementCount($requesterId, 'following_count');
            Notification::create($user['id'], 'follow', $requesterId);
        }
        $res->json(Follow::relationships($user['id'], [$requesterId])[0] ?? []);
    }

    /** POST /api/v1/follow_requests/:id/reject */
    public function rejectFollowRequest(Request $req, Response $res): void
    {
        $user        = Auth::requireUser($res);
        $requesterId = (int) $req->param('id');
        db()->update('follows', ['state' => 'rejected'],
            'follower_local_id = ? AND followee_local_id = ?',
            [$requesterId, $user['id']]
        );
        $res->json(Follow::relationships($user['id'], [$requesterId])[0] ?? []);
    }
}
