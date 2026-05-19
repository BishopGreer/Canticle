<?php
namespace Canticle\Models;

class Notification
{
    public static function create(int $userId, string $type, ?int $fromLocalId = null, ?int $fromRemoteId = null, ?int $statusId = null): int
    {
        // Don't notify if sender is muted
        if ($fromLocalId && Mute::isMuting($userId, $fromLocalId)) return 0;

        return (int) db()->insert('notifications', [
            'user_id'        => $userId,
            'type'           => $type,
            'from_local_id'  => $fromLocalId,
            'from_remote_id' => $fromRemoteId,
            'status_id'      => $statusId,
        ]);
    }

    public static function forUser(int $userId, int $limit = 20, ?int $maxId = null, ?int $sinceId = null, array $types = []): array
    {
        $sql    = 'SELECT * FROM notifications WHERE user_id = ?';
        $params = [$userId];

        if ($types) {
            $ph = implode(',', array_fill(0, count($types), '?'));
            $sql .= " AND type IN ($ph)";
            $params = array_merge($params, $types);
        }
        if ($maxId)   { $sql .= ' AND id < ?'; $params[] = $maxId; }
        if ($sinceId) { $sql .= ' AND id > ?'; $params[] = $sinceId; }
        $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit;

        return db()->fetchAll($sql, $params);
    }

    public static function markRead(int $userId, ?int $id = null): void
    {
        if ($id) {
            db()->update('notifications', ['read_at' => date('Y-m-d H:i:s')], 'id = ? AND user_id = ?', [$id, $userId]);
        } else {
            db()->update('notifications', ['read_at' => date('Y-m-d H:i:s')], 'user_id = ? AND read_at IS NULL', [$userId]);
        }
    }

    public static function unreadCount(int $userId): int
    {
        $row = db()->fetch('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
        return (int) ($row['cnt'] ?? 0);
    }

    public static function toMastodon(array $notification): array
    {
        $account = null;
        if ($notification['from_local_id']) {
            $user = User::find($notification['from_local_id']);
            $account = $user ? User::toMastodon($user) : null;
        } elseif ($notification['from_remote_id']) {
            $actor = RemoteActor::find($notification['from_remote_id']);
            $account = $actor ? RemoteActor::toMastodon($actor) : null;
        }

        $status = null;
        if ($notification['status_id']) {
            $s = Status::find($notification['status_id']);
            $status = $s ? Status::toMastodon($s) : null;
        }

        return [
            'id'         => (string) $notification['id'],
            'type'       => $notification['type'],
            'created_at' => date('c', strtotime($notification['created_at'])),
            'account'    => $account,
            'status'     => $status,
        ];
    }
}
