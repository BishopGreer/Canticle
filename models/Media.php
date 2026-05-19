<?php
namespace Canticle\Models;

class Media
{
    public static function find(int $id): ?array
    {
        return db()->fetch('SELECT * FROM media WHERE id = ?', [$id]);
    }

    public static function forStatus(int $statusId): array
    {
        return db()->fetchAll('SELECT * FROM media WHERE status_id = ? ORDER BY id ASC', [$statusId]);
    }

    public static function create(array $data): int
    {
        return (int) db()->insert('media', $data);
    }

    public static function attachToStatus(int $mediaId, int $statusId): void
    {
        db()->update('media', ['status_id' => $statusId], 'id = ?', [$mediaId]);
    }

    public static function toMastodon(array $media): array
    {
        $baseUrl = BASE_URL;
        $url = $media['remote_url'] ?: ($baseUrl . '/media/' . $media['file_path']);

        return [
            'id'          => (string) $media['id'],
            'type'        => $media['type'],
            'url'         => $url,
            'preview_url' => $url,
            'remote_url'  => $media['remote_url'] ?: null,
            'text_url'    => $url,
            'meta'        => [
                'original' => [
                    'width'  => $media['width'],
                    'height' => $media['height'],
                    'size'   => $media['width'] && $media['height'] ? "{$media['width']}x{$media['height']}" : null,
                    'aspect' => $media['width'] && $media['height'] ? round($media['width'] / $media['height'], 2) : null,
                ],
            ],
            'description' => $media['description'] ?: null,
            'blurhash'    => $media['blurhash'] ?: null,
        ];
    }
}
