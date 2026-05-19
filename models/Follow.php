<?php
namespace Canticle\Models;

class Follow
{
    public static function create(array $data): int
    {
        return (int) db()->insert('follows', $data);
    }

    public static function exists(int $followerLocalId, ?int $followeeLocalId = null, ?int $followeeRemoteId = null): ?array
    {
        if ($followeeLocalId) {
            return db()->fetch(
                "SELECT * FROM follows WHERE follower_local_id = ? AND followee_local_id = ?",
                [$followerLocalId, $followeeLocalId]
            );
        }
        return db()->fetch(
            "SELECT * FROM follows WHERE follower_local_id = ? AND followee_remote_id = ?",
            [$followerLocalId, $followeeRemoteId]
        );
    }

    public static function accept(int $id): void
    {
        db()->update('follows', ['state' => 'accepted'], 'id = ?', [$id]);
    }

    public static function remove(int $followerLocalId, ?int $followeeLocalId = null, ?int $followeeRemoteId = null): void
    {
        if ($followeeLocalId) {
            $follow = db()->fetch(
                "SELECT * FROM follows WHERE follower_local_id = ? AND followee_local_id = ?",
                [$followerLocalId, $followeeLocalId]
            );
        } else {
            $follow = db()->fetch(
                "SELECT * FROM follows WHERE follower_local_id = ? AND followee_remote_id = ?",
                [$followerLocalId, $followeeRemoteId]
            );
        }

        if ($follow) {
            db()->delete('follows', 'id = ?', [$follow['id']]);
            $follower = User::find($followerLocalId);
            if ($follower) User::decrementCount($followerLocalId, 'following_count');
            if ($followeeLocalId) User::decrementCount($followeeLocalId, 'followers_count');
        }
    }

    public static function localFollowers(int $localUserId): array
    {
        return db()->fetchAll(
            "SELECT u.* FROM follows f JOIN users u ON f.follower_local_id = u.id
             WHERE f.followee_local_id = ? AND f.state = 'accepted'",
            [$localUserId]
        );
    }

    public static function remoteFollowers(int $localUserId): array
    {
        return db()->fetchAll(
            "SELECT ra.* FROM follows f JOIN remote_actors ra ON f.follower_remote_id = ra.id
             WHERE f.followee_local_id = ? AND f.state = 'accepted'",
            [$localUserId]
        );
    }

    public static function relationships(int $userId, array $targetIds): array
    {
        $out = [];
        foreach ($targetIds as $targetId) {
            $following = (bool) db()->fetch(
                "SELECT id FROM follows WHERE follower_local_id = ? AND followee_local_id = ? AND state = 'accepted'",
                [$userId, $targetId]
            );
            $followed_by = (bool) db()->fetch(
                "SELECT id FROM follows WHERE follower_local_id = ? AND followee_local_id = ? AND state = 'accepted'",
                [$targetId, $userId]
            );
            $blocking = (bool) db()->fetch(
                "SELECT id FROM blocks WHERE blocker_id = ? AND blocked_local_id = ?",
                [$userId, $targetId]
            );
            $muting = (bool) db()->fetch(
                "SELECT id FROM mutes WHERE muter_id = ? AND muted_local_id = ?",
                [$userId, $targetId]
            );
            $out[] = [
                'id'                   => (string) $targetId,
                'following'            => $following,
                'showing_reblogs'      => $following,
                'notifying'            => false,
                'followed_by'          => $followed_by,
                'blocking'             => $blocking,
                'blocked_by'           => false,
                'muting'               => $muting,
                'muting_notifications' => $muting,
                'requested'            => false,
                'domain_blocking'      => false,
                'endorsed'             => false,
                'note'                 => '',
            ];
        }
        return $out;
    }
}
