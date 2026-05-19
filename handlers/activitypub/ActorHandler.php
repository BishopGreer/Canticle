<?php
namespace Canticle\Handlers\Activitypub;

use Canticle\Core\{Request, Response};
use Canticle\Models\User;

class ActorHandler
{
    public function actor(Request $req, Response $res): void
    {
        $username = $req->param('username');
        $user     = User::findByUsername($username);

        if (!$user || $user['suspended']) {
            $res->json(['error' => 'Not found'], 404);
        }

        // If the client wants HTML, redirect to profile page
        if (!$req->accepts('application/activity+json') && !$req->accepts('application/ld+json') && $req->accepts('text/html')) {
            $res->redirect(BASE_URL . '/@' . $user['username']);
        }

        header('Cache-Control: max-age=180, public');
        $res->activityJson(User::toActivityPub($user));
    }

    public function followers(Request $req, Response $res): void
    {
        $username = $req->param('username');
        $user     = User::findByUsername($username);
        if (!$user) $res->json(['error' => 'Not found'], 404);

        $page = (int) $req->query('page', 0);
        if ($page) {
            $followers = db()->fetchAll(
                "SELECT u.id, u.username FROM follows f
                 JOIN users u ON f.follower_local_id = u.id
                 WHERE f.followee_local_id = ? AND f.state = 'accepted'
                 LIMIT 12 OFFSET ?",
                [$user['id'], ($page - 1) * 12]
            );
            $items = array_map(fn($u) => actorUrl($u['username']), $followers);
            $res->activityJson([
                '@context'     => 'https://www.w3.org/ns/activitystreams',
                'id'           => actorUrl($username) . '/followers?page=' . $page,
                'type'         => 'OrderedCollectionPage',
                'totalItems'   => (int) $user['followers_count'],
                'partOf'       => actorUrl($username) . '/followers',
                'orderedItems' => $items,
            ]);
        }

        $res->activityJson([
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => actorUrl($username) . '/followers',
            'type'       => 'OrderedCollection',
            'totalItems' => (int) $user['followers_count'],
            'first'      => actorUrl($username) . '/followers?page=1',
        ]);
    }

    public function following(Request $req, Response $res): void
    {
        $username = $req->param('username');
        $user     = User::findByUsername($username);
        if (!$user) $res->json(['error' => 'Not found'], 404);

        $res->activityJson([
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => actorUrl($username) . '/following',
            'type'       => 'OrderedCollection',
            'totalItems' => (int) $user['following_count'],
            'first'      => actorUrl($username) . '/following?page=1',
        ]);
    }

    public function outbox(Request $req, Response $res): void
    {
        $username = $req->param('username');
        $user     = User::findByUsername($username);
        if (!$user) $res->json(['error' => 'Not found'], 404);

        $page = (int) $req->query('page', 0);
        if ($page) {
            $statuses = db()->fetchAll(
                "SELECT * FROM statuses WHERE local_user_id = ? AND deleted_at IS NULL
                 AND visibility IN ('public','unlisted')
                 ORDER BY id DESC LIMIT 20 OFFSET ?",
                [$user['id'], ($page - 1) * 20]
            );
            $items = array_map(fn($s) => \Canticle\Models\Status::toActivityPub($s), $statuses);
            $res->activityJson([
                '@context'     => 'https://www.w3.org/ns/activitystreams',
                'id'           => actorUrl($username) . '/outbox?page=' . $page,
                'type'         => 'OrderedCollectionPage',
                'partOf'       => actorUrl($username) . '/outbox',
                'orderedItems' => $items,
            ]);
        }

        $res->activityJson([
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => actorUrl($username) . '/outbox',
            'type'       => 'OrderedCollection',
            'totalItems' => (int) $user['statuses_count'],
            'first'      => actorUrl($username) . '/outbox?page=1',
        ]);
    }
}
