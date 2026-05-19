<?php
namespace Canticle\Models;

use Canticle\Core\Database;
use Canticle\Core\HttpSignature;

class User
{
    public static function find(int $id): ?array
    {
        return db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findByUsername(string $username): ?array
    {
        return db()->fetch('SELECT * FROM users WHERE username = ?', [strtolower($username)]);
    }

    public static function findByEmail(string $email): ?array
    {
        return db()->fetch('SELECT * FROM users WHERE email = ?', [strtolower($email)]);
    }

    public static function create(array $data): int
    {
        $keys = HttpSignature::generateKeypair();
        $data['username']     = strtolower($data['username']);
        $data['email']        = strtolower($data['email']);
        $data['private_key']  = $keys['private'];
        $data['public_key']   = $keys['public'];
        return (int) db()->insert('users', $data);
    }

    public static function update(int $id, array $data): void
    {
        db()->update('users', $data, 'id = ?', [$id]);
    }

    public static function incrementCount(int $id, string $column, int $by = 1): void
    {
        db()->query("UPDATE users SET `$column` = `$column` + ? WHERE id = ?", [$by, $id]);
    }

    public static function decrementCount(int $id, string $column, int $by = 1): void
    {
        db()->query("UPDATE users SET `$column` = GREATEST(0, `$column` - ?) WHERE id = ?", [$by, $id]);
    }

    public static function toMastodon(array $user, bool $withSource = false): array
    {
        $domain  = config('domain');
        $baseUrl = BASE_URL;
        // Stored values are either a full URL (starts with h) or a relative path
        // like "media/avatars/1_xxx.jpg" — prepend BASE_URL with a single slash.
        $avatar  = $user['avatar']
            ? ($user['avatar'][0] === 'h' ? $user['avatar'] : $baseUrl . '/' . $user['avatar'])
            : $baseUrl . '/assets/img/default_avatar.svg';
        $header  = $user['header']
            ? ($user['header'][0] === 'h' ? $user['header'] : $baseUrl . '/' . $user['header'])
            : $baseUrl . '/assets/img/default_header.svg';

        $out = [
            'id'              => (string) $user['id'],
            'username'        => $user['username'],
            'acct'            => $user['username'],
            'display_name'    => $user['display_name'] ?: $user['username'],
            'locked'          => (bool) $user['locked'],
            'bot'             => (bool) $user['bot'],
            'discoverable'    => (bool) $user['discoverable'],
            'created_at'      => date('c', strtotime($user['created_at'])),
            'note'            => $user['bio'] ?? '',
            'url'             => "$baseUrl/@{$user['username']}",
            'avatar'          => $avatar,
            'avatar_static'   => $avatar,
            'header'          => $header,
            'header_static'   => $header,
            'followers_count' => (int) $user['followers_count'],
            'following_count' => (int) $user['following_count'],
            'statuses_count'  => (int) $user['statuses_count'],
            'last_status_at'  => null,
            'emojis'          => [],
            'fields'          => [],
        ];

        if ($withSource) {
            $out['source'] = [
                'privacy'           => 'public',
                'sensitive'         => false,
                'language'          => config('site_lang', 'en'),
                'note'              => $user['bio'] ?? '',
                'fields'            => [],
                'follow_requests_count' => 0,
            ];
        }

        return $out;
    }

    public static function toActivityPub(array $user): array
    {
        $baseUrl = BASE_URL;
        $username = $user['username'];

        return [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
                [
                    'manuallyApprovesFollowers' => 'as:manuallyApprovesFollowers',
                    'toot'    => 'http://joinmastodon.org/ns#',
                    'Hashtag' => 'as:Hashtag',
                    'Emoji'   => 'toot:Emoji',
                ],
            ],
            'id'                       => actorUrl($username),
            'type'                     => $user['bot'] ? 'Service' : 'Person',
            'following'                => actorUrl($username) . '/following',
            'followers'                => actorUrl($username) . '/followers',
            'inbox'                    => actorUrl($username) . '/inbox',
            'outbox'                   => actorUrl($username) . '/outbox',
            'featured'                 => actorUrl($username) . '/collections/featured',
            'preferredUsername'        => $username,
            'name'                     => $user['display_name'] ?: $username,
            'summary'                  => $user['bio'] ?? '',
            'url'                      => "$baseUrl/@$username",
            'manuallyApprovesFollowers'=> (bool) $user['locked'],
            'discoverable'             => (bool) $user['discoverable'],
            'indexable'                => (bool) $user['discoverable'],
            'publicKey'                => [
                'id'           => actorUrl($username) . '#main-key',
                'owner'        => actorUrl($username),
                'publicKeyPem' => $user['public_key'],
            ],
            'icon' => $user['avatar'] ? [
                'type'      => 'Image',
                'mediaType' => 'image/jpeg',
                'url'       => $user['avatar'][0] === 'h' ? $user['avatar'] : $baseUrl . '/' . $user['avatar'],
            ] : null,
            'endpoints' => [
                'sharedInbox' => $baseUrl . '/inbox',
            ],
        ];
    }
}
