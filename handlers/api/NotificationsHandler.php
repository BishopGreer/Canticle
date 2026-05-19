<?php
namespace Canticle\Handlers\Api;

use Canticle\Core\{Request, Response, Auth};
use Canticle\Models\Notification;

class NotificationsHandler
{
    public function index(Request $req, Response $res): void
    {
        $user    = Auth::requireUser($res);
        $limit   = min((int) $req->query('limit', 20), 40);
        $maxId   = $req->query('max_id')   ? (int) $req->query('max_id')   : null;
        $sinceId = $req->query('since_id') ? (int) $req->query('since_id') : null;
        $types   = $req->query('types')    ? (array) $req->query('types')   : [];
        $exclude = $req->query('exclude_types') ? (array) $req->query('exclude_types') : [];

        $rows = Notification::forUser($user['id'], $limit, $maxId, $sinceId, $types);

        if ($exclude) {
            $rows = array_filter($rows, fn($n) => !in_array($n['type'], $exclude));
        }

        $res->json(array_values(array_map([Notification::class, 'toMastodon'], $rows)));
    }

    public function show(Request $req, Response $res): void
    {
        $user = Auth::requireUser($res);
        $n    = db()->fetch('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [(int) $req->param('id'), $user['id']]);
        if (!$n) $res->error('Not found', 404);
        $res->json(Notification::toMastodon($n));
    }

    public function dismiss(Request $req, Response $res): void
    {
        $user = Auth::requireUser($res);
        Notification::markRead($user['id'], (int) $req->param('id'));
        $res->json([]);
    }

    public function clear(Request $req, Response $res): void
    {
        $user = Auth::requireUser($res);
        db()->delete('notifications', 'user_id = ?', [$user['id']]);
        $res->json([]);
    }
}
