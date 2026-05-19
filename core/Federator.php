<?php
namespace Canticle\Core;

use Canticle\Models\{User, Follow, Status};

/**
 * Delivers ActivityPub activities to remote followers.
 * Enqueues jobs; the worker (artisan.php) processes them.
 */
class Federator
{
    private Queue $queue;

    public function __construct(Queue $queue)
    {
        $this->queue = $queue;
    }

    public function deliverStatus(array $status, array $localUser): void
    {
        $activity = Status::toActivityPub($status);
        $this->deliverToFollowers($activity, $localUser);
    }

    public function deleteStatus(array $status, array $localUser): void
    {
        $delete = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $status['uri'] . '#delete',
            'type'     => 'Delete',
            'actor'    => actorUrl($localUser['username']),
            'to'       => ['https://www.w3.org/ns/activitystreams#Public'],
            'object'   => ['id' => $status['uri'], 'type' => 'Tombstone'],
        ];
        $this->deliverToFollowers($delete, $localUser);
    }

    public function sendFollow(array $localUser, array $remoteActor): void
    {
        $followUri = actorUrl($localUser['username']) . '#follow-' . $remoteActor['id'] . '-' . time();
        $activity  = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $followUri,
            'type'     => 'Follow',
            'actor'    => actorUrl($localUser['username']),
            'object'   => $remoteActor['uri'],
        ];

        // Store the follow activity URI so we can match the Accept
        db()->update('follows', ['uri' => $followUri], 'follower_local_id = ? AND followee_remote_id = ?', [$localUser['id'], $remoteActor['id']]);

        $inboxUrl = $remoteActor['shared_inbox_url'] ?: $remoteActor['inbox_url'];
        $this->enqueue($activity, $inboxUrl, $localUser);
    }

    public function sendUnfollow(array $localUser, array $remoteActor, string $followUri): void
    {
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => actorUrl($localUser['username']) . '#undo-follow-' . time(),
            'type'     => 'Undo',
            'actor'    => actorUrl($localUser['username']),
            'object'   => [
                'type'   => 'Follow',
                'id'     => $followUri,
                'actor'  => actorUrl($localUser['username']),
                'object' => $remoteActor['uri'],
            ],
        ];
        $inboxUrl = $remoteActor['shared_inbox_url'] ?: $remoteActor['inbox_url'];
        $this->enqueue($activity, $inboxUrl, $localUser);
    }

    public function sendLike(array $localUser, array $status): void
    {
        if (!$status['remote_actor_id']) return;
        $actor  = \Canticle\Models\RemoteActor::find($status['remote_actor_id']);
        if (!$actor) return;

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => actorUrl($localUser['username']) . '#like-' . $status['id'],
            'type'     => 'Like',
            'actor'    => actorUrl($localUser['username']),
            'object'   => $status['uri'],
        ];
        $this->enqueue($activity, $actor['inbox_url'], $localUser);
    }

    private function deliverToFollowers(array $activity, array $localUser): void
    {
        // Collect unique inboxes
        $inboxes = [];

        // Remote followers — use shared inbox when available
        $remoteFollowers = Follow::remoteFollowers($localUser['id']);
        foreach ($remoteFollowers as $f) {
            $inbox = $f['shared_inbox_url'] ?: $f['inbox_url'];
            if ($inbox) $inboxes[$inbox] = true;
        }

        foreach (array_keys($inboxes) as $inbox) {
            $this->enqueue($activity, $inbox, $localUser);
        }
    }

    private function enqueue(array $activity, string $inboxUrl, array $localUser): void
    {
        $this->queue->push('DeliverActivity', [
            'activity'    => $activity,
            'inbox_url'   => $inboxUrl,
            'actor_url'   => actorUrl($localUser['username']) . '#main-key',
            'private_key' => $localUser['private_key'],
        ]);
    }
}
