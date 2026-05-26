<?php
namespace Canticle\Models;

use Canticle\Core\HttpClient;

class RemoteActor
{
    public static function find(int $id): ?array
    {
        return db()->fetch('SELECT * FROM remote_actors WHERE id = ?', [$id]);
    }

    public static function findByUri(string $uri): ?array
    {
        return db()->fetch('SELECT * FROM remote_actors WHERE uri = ?', [$uri]);
    }

    public static function findByWebfinger(string $username, string $domain): ?array
    {
        return db()->fetch('SELECT * FROM remote_actors WHERE username = ? AND domain = ?', [$username, $domain]);
    }

    public static function fetchAndStore(string $uri, bool $withCounts = false): ?array
    {
        $existing = self::findByUri($uri);
        if ($existing && $existing['fetched_at'] && strtotime($existing['fetched_at']) > time() - 3600) {
            // Refresh counts on explicit profile visit even if recently fetched
            if ($withCounts) {
                self::refreshCounts($existing);
                return self::findByUri($uri);
            }
            return $existing;
        }

        $client = new HttpClient();
        $data   = $client->fetchActor($uri);
        // If the network fetch fails but we have a cached record, use it rather
        // than returning null and causing the inbox to reject the activity with
        // a 400 — a stale public key is better than dropping an Accept entirely.
        if (!$data) return $existing ?: null;

        $actor = self::upsertFromApData($data);
        if ($actor && $withCounts) {
            self::refreshCounts($actor);
            return self::findByUri($uri);
        }
        return $actor;
    }

    /** Fetch collection totalItems for followers/following/outbox and update stored counts. */
    public static function refreshCounts(array $actor): void
    {
        $client  = new HttpClient(8);
        $updates = [];

        foreach ([
            'followers_url'  => 'followers_count',
            'following_url'  => 'following_count',
            'outbox_url'     => 'statuses_count',
        ] as $urlField => $countField) {
            $url = $actor[$urlField] ?? '';
            if (!$url) continue;
            $data = $client->fetchActor($url);
            if (is_array($data) && isset($data['totalItems'])) {
                // Clamp to INT UNSIGNED range (0–4294967295).
                // Some servers return -1 for private/unavailable collections;
                // negative values are rejected by the UNSIGNED column.
                $raw = (int) $data['totalItems'];
                $updates[$countField] = max(0, min(4294967295, $raw));
            }
        }

        if ($updates) {
            db()->update('remote_actors', $updates, 'id = ?', [$actor['id']]);
        }
    }

    /** Fetch the first page of an actor's outbox and store any new posts. */
    public static function importOutbox(array $actor): void
    {
        $outboxUrl = $actor['outbox_url'] ?? '';
        if (!$outboxUrl) return;

        $client = new HttpClient(10);

        // Fetch the outbox collection to get the first page URL
        $collection = $client->fetchActor($outboxUrl);
        if (!is_array($collection)) return;

        $firstUrl = $collection['first'] ?? null;
        if (!$firstUrl) return;

        // If first is an object with an id, use the id as URL
        if (is_array($firstUrl)) $firstUrl = $firstUrl['id'] ?? null;
        if (!$firstUrl) return;

        $page = $client->fetchActor($firstUrl);
        if (!is_array($page)) return;

        $items = $page['orderedItems'] ?? $page['items'] ?? [];
        foreach (array_slice($items, 0, 20) as $item) {
            // Items can be Create activities or bare Notes
            $obj = null;
            if (is_array($item) && ($item['type'] ?? '') === 'Create') {
                $obj = $item['object'] ?? null;
            } elseif (is_array($item) && ($item['type'] ?? '') === 'Note') {
                $obj = $item;
            }
            if (!is_array($obj) || ($obj['type'] ?? '') !== 'Note') continue;

            $uri = $obj['id'] ?? '';
            if (!$uri || \Canticle\Models\Status::findByUri($uri)) continue;

            $to  = (array) ($obj['to'] ?? []);
            $cc  = (array) ($obj['cc'] ?? []);
            $vis = 'direct';
            if (in_array('https://www.w3.org/ns/activitystreams#Public', $to))     $vis = 'public';
            elseif (in_array('https://www.w3.org/ns/activitystreams#Public', $cc)) $vis = 'unlisted';

            if (!in_array($vis, ['public', 'unlisted'])) continue;

            $published = $obj['published'] ?? null;
            $createdAt = $published ? gmdate('Y-m-d H:i:s', strtotime($published)) : gmdate('Y-m-d H:i:s');

            $statusId = \Canticle\Models\Status::create([
                'remote_actor_id' => $actor['id'],
                'uri'             => $uri,
                'url'             => $obj['url'] ?? $uri,
                'content'         => $obj['content'] ?? '',
                'content_warning' => $obj['summary'] ?? '',
                'visibility'      => $vis,
                'language'        => array_key_first($obj['contentMap'] ?? []) ?? 'en',
                'sensitive'       => (int) ($obj['sensitive'] ?? false),
                'reply_to_uri'    => $obj['inReplyTo'] ?? null,
                'created_at'      => $createdAt,
            ]);

            self::saveAttachments($statusId, $obj);
        }
    }

    /**
     * Persist media attachments from an ActivityPub Note object.
     * Mirrors InboxHandler::saveAttachments — stores the remote URL so
     * images and videos appear when viewing the post.
     */
    private static function saveAttachments(int $statusId, array $obj): void
    {
        $attachments = $obj['attachment'] ?? [];
        if (!$attachments) return;
        if (isset($attachments['type'])) $attachments = [$attachments];

        foreach ($attachments as $att) {
            if (!is_array($att)) continue;

            $remoteUrl = '';
            if (is_array($att['url'] ?? null)) {
                foreach ($att['url'] as $link) {
                    if (is_array($link) && isset($link['href'])) {
                        $remoteUrl = $link['href'];
                        if (!isset($att['mediaType']) && isset($link['mediaType'])) {
                            $att['mediaType'] = $link['mediaType'];
                        }
                        break;
                    }
                }
            } else {
                $remoteUrl = (string) ($att['url'] ?? '');
            }
            if (!$remoteUrl) continue;

            $mimeType = (string) ($att['mediaType'] ?? '');
            $type = match(true) {
                str_starts_with($mimeType, 'image/') => 'image',
                str_starts_with($mimeType, 'video/') => 'video',
                str_starts_with($mimeType, 'audio/') => 'audio',
                default                              => 'unknown',
            };

            \Canticle\Models\Media::create([
                'status_id'   => $statusId,
                'type'        => $type,
                'remote_url'  => $remoteUrl,
                'mime_type'   => $mimeType,
                'description' => (string) ($att['name'] ?? $att['summary'] ?? ''),
                'width'       => isset($att['width'])  ? (int) $att['width']  : null,
                'height'      => isset($att['height']) ? (int) $att['height'] : null,
                'blurhash'    => (string) ($att['blurhash'] ?? ''),
                'file_path'   => '',
                'file_name'   => basename((string) parse_url($remoteUrl, PHP_URL_PATH)),
                'file_size'   => 0,
            ]);
        }
    }

    public static function upsertFromApData(array $data): ?array
    {
        $uri      = $data['id'] ?? '';
        $username = $data['preferredUsername'] ?? '';
        $parsed   = parse_url($uri);
        $domain   = $parsed['host'] ?? '';

        if (!$uri || !$username || !$domain) return null;

        // Extract public key
        $pubKey   = $data['publicKey']['publicKeyPem'] ?? '';
        $keyId    = $data['publicKey']['id'] ?? "$uri#main-key";

        $endpoints   = $data['endpoints'] ?? [];
        $sharedInbox = $endpoints['sharedInbox'] ?? '';

        $row = [
            'uri'             => $uri,
            'username'        => $username,
            'domain'          => $domain,
            'display_name'    => $data['name'] ?? $username,
            'bio'             => strip_tags($data['summary'] ?? ''),
            'avatar'          => is_array($data['icon'] ?? null)  ? ($data['icon']['url'] ?? '')  : '',
            'header'          => is_array($data['image'] ?? null) ? ($data['image']['url'] ?? '') : '',
            'locked'          => (int) ($data['manuallyApprovesFollowers'] ?? false),
            'bot'             => in_array($data['type'] ?? '', ['Service', 'Application']) ? 1 : 0,
            'public_key'      => $pubKey,
            'public_key_id'   => $keyId,
            'inbox_url'       => $data['inbox'] ?? '',
            'outbox_url'      => $data['outbox'] ?? '',
            'shared_inbox_url'=> $sharedInbox,
            'followers_url'   => $data['followers'] ?? '',
            'following_url'   => $data['following'] ?? '',
            'raw_json'        => json_encode($data),
            'fetched_at'      => date('Y-m-d H:i:s'),
        ];

        $existing = self::findByUri($uri);
        if ($existing) {
            db()->update('remote_actors', $row, 'uri = ?', [$uri]);
            return self::findByUri($uri);
        }

        $id = db()->insert('remote_actors', $row);
        return self::find((int) $id);
    }

    public static function toMastodon(array $actor): array
    {
        return [
            'id'              => (string) $actor['id'],
            'username'        => $actor['username'],
            'acct'            => $actor['username'] . '@' . $actor['domain'],
            'display_name'    => $actor['display_name'] ?: $actor['username'],
            'locked'          => (bool) $actor['locked'],
            'bot'             => (bool) $actor['bot'],
            'discoverable'    => true,
            'created_at'      => date('c', strtotime($actor['created_at'])),
            'note'            => $actor['bio'] ?? '',
            'url'             => $actor['uri'],
            'avatar'          => $actor['avatar'] ?: BASE_URL . '/assets/img/default_avatar.svg',
            'avatar_static'   => $actor['avatar'] ?: BASE_URL . '/assets/img/default_avatar.svg',
            'header'          => $actor['header'] ?: BASE_URL . '/assets/img/default_header.svg',
            'header_static'   => $actor['header'] ?: BASE_URL . '/assets/img/default_header.svg',
            'followers_count' => (int) $actor['followers_count'],
            'following_count' => (int) $actor['following_count'],
            'statuses_count'  => (int) $actor['statuses_count'],
            'last_status_at'  => null,
            'emojis'          => [],
            'fields'          => [],
        ];
    }
}
