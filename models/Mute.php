<?php
namespace Canticle\Models;

class Mute
{
    public static function mute(int $muterId, ?int $localId = null, ?int $remoteId = null, bool $notifications = true, ?int $duration = null): void
    {
        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
        if ($localId) {
            db()->query(
                "INSERT INTO mutes (muter_id, muted_local_id, notifications, expires_at) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE notifications = VALUES(notifications), expires_at = VALUES(expires_at)",
                [$muterId, $localId, $notifications ? 1 : 0, $expiresAt]
            );
        } elseif ($remoteId) {
            db()->query(
                "INSERT INTO mutes (muter_id, muted_remote_id, notifications, expires_at) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE notifications = VALUES(notifications), expires_at = VALUES(expires_at)",
                [$muterId, $remoteId, $notifications ? 1 : 0, $expiresAt]
            );
        }
    }

    public static function unmute(int $muterId, ?int $localId = null, ?int $remoteId = null): void
    {
        if ($localId) {
            db()->delete('mutes', 'muter_id = ? AND muted_local_id = ?', [$muterId, $localId]);
        } elseif ($remoteId) {
            db()->delete('mutes', 'muter_id = ? AND muted_remote_id = ?', [$muterId, $remoteId]);
        }
    }

    public static function isMuting(int $muterId, ?int $localId = null, ?int $remoteId = null): bool
    {
        if ($localId) {
            $row = db()->fetch(
                'SELECT id, expires_at FROM mutes WHERE muter_id = ? AND muted_local_id = ?',
                [$muterId, $localId]
            );
        } else {
            $row = db()->fetch(
                'SELECT id, expires_at FROM mutes WHERE muter_id = ? AND muted_remote_id = ?',
                [$muterId, $remoteId]
            );
        }
        if (!$row) return false;
        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            self::unmute($muterId, $localId, $remoteId);
            return false;
        }
        return true;
    }

    public static function listForUser(int $userId): array
    {
        return db()->fetchAll(
            "SELECT m.*, u.username, u.display_name FROM mutes m
             LEFT JOIN users u ON m.muted_local_id = u.id
             WHERE m.muter_id = ?",
            [$userId]
        );
    }
}
