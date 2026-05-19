<?php
namespace Canticle\Handlers\Api;

use Canticle\Core\{Request, Response, Auth};
use Canticle\Models\{Poll, Status, Notification};

class PollsHandler
{
    /** GET /api/v1/polls/:id */
    public function show(Request $req, Response $res): void
    {
        $poll = Poll::find((int) $req->param('id'));
        if (!$poll) $res->error('Not found', 404);
        $viewer = Auth::user();
        $res->json(Poll::toMastodon($poll, $viewer));
    }

    /** POST /api/v1/polls/:id/votes */
    public function vote(Request $req, Response $res): void
    {
        $user    = Auth::requireUser($res);
        $pollId  = (int) $req->param('id');
        $choices = (array) ($req->input('choices') ?? []);

        if (empty($choices)) $res->error('No choices provided', 422);

        $poll = Poll::find($pollId);
        if (!$poll) $res->error('Not found', 404);
        if ($poll['expires_at'] && strtotime($poll['expires_at']) < time()) {
            $res->error('Poll has expired', 422);
        }

        // Resolve option IDs from positions
        $optionIds = [];
        foreach ($choices as $pos) {
            $opt = $poll['options'][(int) $pos] ?? null;
            if ($opt) $optionIds[] = $opt['id'];
        }

        if (!$poll['multiple'] && count($optionIds) > 1) {
            $optionIds = [$optionIds[0]];
        }

        Poll::vote($pollId, $optionIds, $user['id']);

        // Notify status author
        if ($poll['status_id']) {
            $status = Status::find($poll['status_id']);
            if ($status && $status['local_user_id'] && $status['local_user_id'] !== $user['id']) {
                Notification::create($status['local_user_id'], 'poll', $user['id'], null, $status['id']);
            }
        }

        $res->json(Poll::toMastodon(Poll::find($pollId), $user));
    }
}
