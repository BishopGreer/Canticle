<?php
namespace Canticle\Models;

class Poll
{
    public static function find(int $id): ?array
    {
        $poll = db()->fetch('SELECT * FROM polls WHERE id = ?', [$id]);
        if (!$poll) return null;
        $poll['options'] = db()->fetchAll(
            'SELECT * FROM poll_options WHERE poll_id = ? ORDER BY position ASC',
            [$id]
        );
        return $poll;
    }

    public static function create(int $statusId, array $options, bool $multiple, ?string $expiresAt): int
    {
        $pollId = (int) db()->insert('polls', [
            'status_id'  => $statusId,
            'multiple'   => $multiple ? 1 : 0,
            'expires_at' => $expiresAt,
        ]);

        foreach (array_values($options) as $i => $title) {
            db()->insert('poll_options', [
                'poll_id'  => $pollId,
                'position' => $i,
                'title'    => $title,
            ]);
        }

        db()->update('statuses', ['poll_id' => $pollId], 'id = ?', [$statusId]);

        return $pollId;
    }

    public static function vote(int $pollId, array $optionIds, int $userId): bool
    {
        $poll = self::find($pollId);
        if (!$poll) return false;
        if ($poll['expires_at'] && strtotime($poll['expires_at']) < time()) return false;

        foreach ($optionIds as $optionId) {
            try {
                db()->insert('poll_votes', [
                    'poll_id'        => $pollId,
                    'poll_option_id' => $optionId,
                    'local_user_id'  => $userId,
                ]);
                db()->query(
                    'UPDATE poll_options SET votes_count = votes_count + 1 WHERE id = ?',
                    [$optionId]
                );
            } catch (\Throwable) {
                // duplicate vote — ignore
            }
        }

        db()->query(
            'UPDATE polls SET votes_count = votes_count + 1, voters_count = (SELECT COUNT(DISTINCT local_user_id) FROM poll_votes WHERE poll_id = ?) WHERE id = ?',
            [$pollId, $pollId]
        );

        return true;
    }

    public static function toMastodon(array $poll, ?array $viewer = null): array
    {
        $voted    = false;
        $ownVotes = [];
        if ($viewer) {
            $votes = db()->fetchAll(
                'SELECT poll_option_id FROM poll_votes WHERE poll_id = ? AND local_user_id = ?',
                [$poll['id'], $viewer['id']]
            );
            $ownVotes = array_column($votes, 'poll_option_id');
            $voted    = !empty($ownVotes);
        }

        return [
            'id'          => (string) $poll['id'],
            'expires_at'  => $poll['expires_at'] ? date('c', strtotime($poll['expires_at'])) : null,
            'expired'     => $poll['expires_at'] ? strtotime($poll['expires_at']) < time() : false,
            'multiple'    => (bool) $poll['multiple'],
            'votes_count' => (int) $poll['votes_count'],
            'voters_count'=> (int) $poll['voters_count'],
            'voted'       => $voted,
            'own_votes'   => array_map('intval', $ownVotes),
            'options'     => array_map(fn($o) => [
                'title'       => $o['title'],
                'votes_count' => ($voted || ($poll['expires_at'] && strtotime($poll['expires_at']) < time()))
                    ? (int) $o['votes_count'] : null,
            ], $poll['options']),
            'emojis'      => [],
        ];
    }
}
