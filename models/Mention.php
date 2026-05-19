<?php
namespace Canticle\Models;

class Mention
{
    public static function forStatus(int $statusId): array
    {
        $rows = db()->fetchAll(
            'SELECT * FROM mentions WHERE status_id = ?',
            [$statusId]
        );
        $out = [];
        foreach ($rows as $row) {
            if ($row['local_user_id']) {
                $user = User::find($row['local_user_id']);
                if ($user) {
                    $out[] = [
                        'id'       => (string) $user['id'],
                        'username' => $user['username'],
                        'acct'     => $user['username'],
                        'url'      => BASE_URL . '/@' . $user['username'],
                    ];
                }
            } elseif ($row['remote_actor_id']) {
                $actor = RemoteActor::find($row['remote_actor_id']);
                if ($actor) {
                    $out[] = [
                        'id'       => (string) $actor['id'],
                        'username' => $actor['username'],
                        'acct'     => $actor['username'] . '@' . $actor['domain'],
                        'url'      => $actor['uri'],
                    ];
                }
            }
        }
        return $out;
    }

    public static function extractAndLink(int $statusId, string $content, ?int $authorId = null): void
    {
        // @username or @username@domain
        preg_match_all('/@([a-zA-Z0-9_]+)(?:@([a-zA-Z0-9._-]+))?/', strip_tags($content), $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $username = strtolower($m[1]);
            $domain   = $m[2] ?? null;

            if (!$domain) {
                $user = User::findByUsername($username);
                if ($user && $user['id'] !== $authorId) {
                    try {
                        db()->insert('mentions', ['status_id' => $statusId, 'local_user_id' => $user['id']]);
                    } catch (\Throwable) {}
                    Notification::create($user['id'], 'mention', $authorId, null, $statusId);
                }
            } else {
                $actor = RemoteActor::findByWebfinger($username, $domain);
                if ($actor) {
                    try {
                        db()->insert('mentions', ['status_id' => $statusId, 'remote_actor_id' => $actor['id']]);
                    } catch (\Throwable) {}
                }
            }
        }
    }
}
