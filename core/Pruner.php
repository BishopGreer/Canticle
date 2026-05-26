<?php
namespace Canticle\Core;

/**
 * Prunes old remote content to control database and disk usage.
 *
 * Safety rules — a remote status is NEVER deleted if:
 *   - A local user has favourited it
 *   - A local user has replied to it
 *   - It is boosted in a local user's status (reblog_of_id chain)
 *
 * A remote actor is NEVER deleted if:
 *   - Any local user follows them
 *   - They still have recent statuses within the retention window
 */
class Pruner
{
    private Database $db;
    private string   $storagePath;

    public function __construct(Database $db, string $storagePath)
    {
        $this->db          = $db;
        $this->storagePath = rtrim($storagePath, '/');
    }

    /**
     * Run a full prune cycle. Returns a summary array.
     *
     * @param  int  $statusMaxDays  Delete remote statuses older than this many days (0 = skip)
     * @param  int  $actorMaxDays   Delete cached remote actors older than this many days with no local ties (0 = skip)
     */
    public function run(int $statusMaxDays = 90, int $actorMaxDays = 180): array
    {
        $summary = [
            'statuses_removed'    => 0,
            'media_files_removed' => 0,
            'media_bytes_freed'   => 0,
            'actors_removed'      => 0,
            'cutoff_days'         => $statusMaxDays,
            'notes'               => [],
        ];

        if ($statusMaxDays > 0) {
            [$statuses, $mediaFiles, $mediaBytes] = $this->pruneStatuses($statusMaxDays);
            $summary['statuses_removed']    = $statuses;
            $summary['media_files_removed'] = $mediaFiles;
            $summary['media_bytes_freed']   = $mediaBytes;
            $summary['notes'][] = "Statuses: removed $statuses older than $statusMaxDays days.";
            $summary['notes'][] = "Media: deleted $mediaFiles files, freed " . $this->humanBytes($mediaBytes) . ".";
        } else {
            $summary['notes'][] = 'Status pruning skipped (remote_status_max_days = 0).';
        }

        if ($actorMaxDays > 0) {
            $actors = $this->pruneActors($actorMaxDays);
            $summary['actors_removed'] = $actors;
            $summary['notes'][] = "Remote actors: removed $actors stale cached profiles.";
        } else {
            $summary['notes'][] = 'Actor pruning skipped (remote_actor_max_days = 0).';
        }

        // Tombstones older than 7 days are safe to remove — the race window is long past
        $tombstones = $this->pruneTombstones();
        if ($tombstones > 0) {
            $summary['notes'][] = "Tombstones: removed $tombstones expired Delete records.";
        }

        // Log this run (table may not exist if migration 003 hasn't been applied yet)
        try {
            $this->db->insert('prune_log', [
                'statuses_removed'    => $summary['statuses_removed'],
                'media_files_removed' => $summary['media_files_removed'],
                'media_bytes_freed'   => $summary['media_bytes_freed'],
                'actors_removed'      => $summary['actors_removed'],
                'cutoff_days'         => $statusMaxDays,
                'notes'               => implode("\n", $summary['notes']),
            ]);
        } catch (\Throwable $e) {
            $summary['notes'][] = 'Note: prune_log table not found — run php artisan.php migrate to create it.';
        }

        return $summary;
    }

    // ── Status pruning ────────────────────────────────────────────────────────

    /**
     * Delete remote statuses older than $days days, respecting safety rules.
     * Also deletes any locally-stored media files attached to those statuses.
     *
     * @return array [statuses_deleted, media_files_deleted, bytes_freed]
     */
    private function pruneStatuses(int $days): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Find remote status IDs that are safe to delete
        $rows = $this->db->fetchAll(
            "SELECT s.id FROM statuses s
             WHERE s.remote_actor_id IS NOT NULL
               AND s.deleted_at IS NULL
               AND s.created_at < ?
               AND s.id NOT IN (
                   -- favourited by any local user
                   SELECT status_id FROM favourites WHERE local_user_id IS NOT NULL
               )
               AND s.id NOT IN (
                   -- replied to by any local user
                   SELECT reply_to_id FROM statuses
                   WHERE local_user_id IS NOT NULL AND reply_to_id IS NOT NULL
               )
               AND s.id NOT IN (
                   -- boosted by any local user
                   SELECT reblog_of_id FROM statuses
                   WHERE local_user_id IS NOT NULL AND reblog_of_id IS NOT NULL
               )",
            [$cutoff]
        );

        if (!$rows) {
            return [0, 0, 0];
        }

        $ids = array_column($rows, 'id');

        // Gather and delete locally-stored media files for these statuses
        [$mediaFiles, $mediaBytes] = $this->deleteMediaForStatuses($ids);

        // Hard-delete the status rows in batches of 500
        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $this->db->query(
                "DELETE FROM statuses WHERE id IN ($placeholders)",
                $chunk
            );
            $deleted += count($chunk);
        }

        // Clean up dangling related rows
        if ($ids) {
            $this->cleanOrphanedRows($ids);
        }

        return [$deleted, $mediaFiles, $mediaBytes];
    }

    /**
     * Find and delete locally-stored media files attached to the given status IDs.
     * Media records whose file_path is empty (i.e. remote-only) are just deleted from DB.
     *
     * @return array [files_deleted, bytes_freed]
     */
    private function deleteMediaForStatuses(array $statusIds): array
    {
        if (!$statusIds) return [0, 0];

        $placeholders = implode(',', array_fill(0, count($statusIds), '?'));
        $mediaRows    = $this->db->fetchAll(
            "SELECT id, file_path, file_size FROM media WHERE status_id IN ($placeholders)",
            $statusIds
        );

        $filesDeleted = 0;
        $bytesFreed   = 0;

        foreach ($mediaRows as $m) {
            if ($m['file_path']) {
                $full = $this->storagePath . '/' . ltrim($m['file_path'], '/');
                if (is_file($full)) {
                    $bytesFreed += (int) $m['file_size'];
                    @unlink($full);
                    $filesDeleted++;
                }
            }
        }

        if ($mediaRows) {
            $mids = array_column($mediaRows, 'id');
            $mp   = implode(',', array_fill(0, count($mids), '?'));
            $this->db->query("DELETE FROM media WHERE id IN ($mp)", $mids);
        }

        return [$filesDeleted, $bytesFreed];
    }

    /**
     * Remove rows in related tables that now have no matching status.
     */
    private function cleanOrphanedRows(array $statusIds): void
    {
        $p = implode(',', array_fill(0, count($statusIds), '?'));
        foreach (['favourites', 'notifications', 'status_hashtags', 'mentions', 'status_edits'] as $table) {
            try {
                $this->db->query("DELETE FROM `$table` WHERE status_id IN ($p)", $statusIds);
            } catch (\Throwable) {
                // Ignore if table doesn't have status_id or doesn't exist
            }
        }
    }

    // ── Remote actor pruning ──────────────────────────────────────────────────

    /**
     * Delete cached remote actor profiles that:
     *   - Have not posted anything within the retention window
     *   - Are not followed by any local user
     *   - Have not followed any local user
     *
     * Their avatar/header images are remote URLs and not stored locally, so no
     * files are deleted here.
     */
    private function pruneActors(int $days): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $rows = $this->db->fetchAll(
            "SELECT ra.id FROM remote_actors ra
             WHERE ra.fetched_at < ?
               AND ra.id NOT IN (
                   -- has recent statuses we're keeping
                   SELECT DISTINCT remote_actor_id FROM statuses
                   WHERE remote_actor_id IS NOT NULL AND deleted_at IS NULL AND created_at >= ?
               )
               AND ra.id NOT IN (
                   -- local user follows this actor
                   SELECT followee_remote_id FROM follows
                   WHERE followee_remote_id IS NOT NULL
               )
               AND ra.id NOT IN (
                   -- this actor follows a local user
                   SELECT follower_remote_id FROM follows
                   WHERE follower_remote_id IS NOT NULL
               )",
            [$cutoff, $cutoff]
        );

        if (!$rows) return 0;

        $ids = array_column($rows, 'id');

        // First remove their statuses (shouldn't be any after status prune, but be safe)
        $sp = implode(',', array_fill(0, count($ids), '?'));
        $statusRows = $this->db->fetchAll(
            "SELECT id FROM statuses WHERE remote_actor_id IN ($sp)",
            $ids
        );
        if ($statusRows) {
            $sids = array_column($statusRows, 'id');
            $this->deleteMediaForStatuses($sids);
            $this->cleanOrphanedRows($sids);
            $sp2 = implode(',', array_fill(0, count($sids), '?'));
            $this->db->query("DELETE FROM statuses WHERE id IN ($sp2)", $sids);
        }

        // Delete the actor rows
        $this->db->query("DELETE FROM remote_actors WHERE id IN ($sp)", $ids);

        return count($ids);
    }

    // ── Tombstone pruning ─────────────────────────────────────────────────────

    /**
     * Delete tombstone records older than 7 days.
     * Tombstones only need to cover the race window between a Delete and a
     * late-arriving Create/Announce for the same URI — 7 days is generous.
     */
    private function pruneTombstones(): int
    {
        try {
            $cutoff = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
            $result = $this->db->fetch(
                "SELECT COUNT(*) AS c FROM tombstones WHERE created_at < ?",
                [$cutoff]
            );
            $count = (int) ($result['c'] ?? 0);
            if ($count > 0) {
                $this->db->query("DELETE FROM tombstones WHERE created_at < ?", [$cutoff]);
            }
            return $count;
        } catch (\Throwable) {
            return 0; // table not yet created — migration pending
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
