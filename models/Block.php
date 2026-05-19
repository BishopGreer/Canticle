<?php
namespace Canticle\Models;

class Block
{
    public static function block(int $blockerId, ?int $localId = null, ?int $remoteId = null): void
    {
        if ($localId) {
            db()->query(
                "INSERT IGNORE INTO blocks (blocker_id, blocked_local_id) VALUES (?, ?)",
                [$blockerId, $localId]
            );
            Follow::remove($blockerId, $localId);
        } elseif ($remoteId) {
            db()->query(
                "INSERT IGNORE INTO blocks (blocker_id, blocked_remote_id) VALUES (?, ?)",
                [$blockerId, $remoteId]
            );
        }
    }

    public static function unblock(int $blockerId, ?int $localId = null, ?int $remoteId = null): void
    {
        if ($localId) {
            db()->delete('blocks', 'blocker_id = ? AND blocked_local_id = ?', [$blockerId, $localId]);
        } elseif ($remoteId) {
            db()->delete('blocks', 'blocker_id = ? AND blocked_remote_id = ?', [$blockerId, $remoteId]);
        }
    }

    public static function isBlocking(int $blockerId, ?int $localId = null, ?int $remoteId = null): bool
    {
        if ($localId) {
            return (bool) db()->fetch(
                'SELECT id FROM blocks WHERE blocker_id = ? AND blocked_local_id = ?',
                [$blockerId, $localId]
            );
        }
        return (bool) db()->fetch(
            'SELECT id FROM blocks WHERE blocker_id = ? AND blocked_remote_id = ?',
            [$blockerId, $remoteId]
        );
    }

    public static function listForUser(int $userId): array
    {
        return db()->fetchAll(
            "SELECT b.*, u.username, u.display_name FROM blocks b
             LEFT JOIN users u ON b.blocked_local_id = u.id
             WHERE b.blocker_id = ?",
            [$userId]
        );
    }
}
