<?php
namespace Canticle\Models;

class Hashtag
{
    public static function forStatus(int $statusId): array
    {
        return db()->fetchAll(
            'SELECT h.* FROM hashtags h JOIN status_hashtags sh ON h.id = sh.hashtag_id WHERE sh.status_id = ?',
            [$statusId]
        );
    }

    public static function extractAndLink(int $statusId, string $content): void
    {
        preg_match_all('/#([a-zA-Z0-9_]+)/', strip_tags($content), $matches);
        foreach (array_unique($matches[1]) as $tag) {
            $tag = strtolower($tag);
            $row = db()->fetch('SELECT id FROM hashtags WHERE name = ?', [$tag]);
            if ($row) {
                $tagId = $row['id'];
            } else {
                $tagId = db()->insert('hashtags', ['name' => $tag]);
            }
            try {
                db()->insert('status_hashtags', ['status_id' => $statusId, 'hashtag_id' => $tagId]);
            } catch (\Throwable) {}
        }
    }
}
