<?php
namespace Canticle\Handlers\Api;

use Canticle\Core\{Request, Response, Auth};
use Canticle\Models\Status;

class TimelinesHandler
{
    /** GET /api/v1/timelines/public */
    public function public(Request $req, Response $res): void
    {
        $limit   = min((int) $req->query('limit', 20), 40);
        $maxId   = $req->query('max_id')   ? (int) $req->query('max_id')   : null;
        $sinceId = $req->query('since_id') ? (int) $req->query('since_id') : null;
        $local   = (bool) $req->query('local', false);
        $remote  = (bool) $req->query('remote', false);
        $viewer  = Auth::user();

        $rows = Status::publicTimeline($limit, $sinceId, $maxId);

        // Local/remote filters (client hint only — block/silence filtering is in the SQL)
        if ($local)  $rows = array_values(array_filter($rows, fn($s) => $s['local_user_id']));
        if ($remote) $rows = array_values(array_filter($rows, fn($s) => $s['remote_actor_id']));

        $res->json(Status::manyToMastodon($rows, $viewer));
    }

    /** GET /api/v1/timelines/home */
    public function home(Request $req, Response $res): void
    {
        $user    = Auth::requireUser($res);
        $limit   = min((int) $req->query('limit', 20), 40);
        $maxId   = $req->query('max_id')   ? (int) $req->query('max_id')   : null;
        $sinceId = $req->query('since_id') ? (int) $req->query('since_id') : null;

        $rows = Status::homeTimeline($user['id'], $limit, $maxId, $sinceId);
        $res->json(Status::manyToMastodon($rows, $user));
    }

    /** GET /api/v1/timelines/tag/:hashtag */
    public function hashtag(Request $req, Response $res): void
    {
        $tag     = strtolower($req->param('hashtag'));
        $limit   = min((int) $req->query('limit', 20), 40);
        $maxId   = $req->query('max_id')   ? (int) $req->query('max_id')   : null;
        $viewer  = Auth::user();

        $tagRow = db()->fetch('SELECT id FROM hashtags WHERE name = ?', [$tag]);
        if (!$tagRow) $res->json([]);

        $rows = db()->fetchAll(
            "SELECT s.* FROM statuses s
             JOIN status_hashtags sh ON s.id = sh.status_id
             WHERE sh.hashtag_id = ? AND s.deleted_at IS NULL AND s.visibility = 'public'
             " . ($maxId ? "AND s.id < $maxId" : '') . "
             ORDER BY s.id DESC LIMIT " . (int) $limit,
            [$tagRow['id']]
        );

        $res->json(Status::manyToMastodon($rows, $viewer));
    }

    /** GET /api/v1/conversations (direct messages) */
    public function conversations(Request $req, Response $res): void
    {
        $user  = Auth::requireUser($res);
        $limit = min((int) $req->query('limit', 20), 40);

        $rows = db()->fetchAll(
            "SELECT s.* FROM statuses s
             WHERE s.visibility = 'direct'
             AND s.deleted_at IS NULL
             AND (s.local_user_id = ? OR s.id IN (
               SELECT status_id FROM mentions WHERE local_user_id = ?
             ))
             ORDER BY s.id DESC LIMIT " . (int) $limit,
            [$user['id'], $user['id']]
        );

        $out = [];
        foreach ($rows as $s) {
            $out[] = [
                'id'           => (string) $s['id'],
                'accounts'     => [],
                'last_status'  => Status::toMastodon($s, $user),
                'unread'       => false,
            ];
        }
        $res->json($out);
    }
}
