<?php
namespace Canticle\Models;

class Instance
{
    public static function findByDomain(string $domain): ?array
    {
        return db()->fetch('SELECT * FROM instances WHERE domain = ?', [$domain]);
    }

    public static function upsert(string $domain, array $data = []): void
    {
        $existing = self::findByDomain($domain);
        if ($existing) {
            db()->update('instances', array_merge($data, ['last_seen' => date('Y-m-d H:i:s')]), 'domain = ?', [$domain]);
        } else {
            db()->insert('instances', array_merge(['domain' => $domain, 'last_seen' => date('Y-m-d H:i:s')], $data));
        }
    }

    public static function isBlocked(string $domain): bool
    {
        $row = self::findByDomain($domain);
        return $row && $row['status'] === 'blocked';
    }

    public static function isSilenced(string $domain): bool
    {
        $row = self::findByDomain($domain);
        return $row && $row['status'] === 'silenced';
    }

    public static function block(string $domain, string $publicReason = '', string $privateReason = ''): void
    {
        self::upsert($domain, [
            'status'         => 'blocked',
            'block_reason'   => $publicReason,   // kept for backward compat
            'public_reason'  => $publicReason  ?: null,
            'private_reason' => $privateReason ?: null,
        ]);
    }

    public static function silence(string $domain, string $publicReason = '', string $privateReason = ''): void
    {
        self::upsert($domain, [
            'status'         => 'silenced',
            'public_reason'  => $publicReason  ?: null,
            'private_reason' => $privateReason ?: null,
        ]);
    }

    public static function unblock(string $domain): void
    {
        self::upsert($domain, [
            'status'         => 'allowed',
            'block_reason'   => null,
            'public_reason'  => null,
            'private_reason' => null,
        ]);
    }

    public static function all(): array
    {
        return db()->fetchAll('SELECT * FROM instances ORDER BY domain ASC');
    }

    public static function blocked(): array
    {
        return db()->fetchAll("SELECT * FROM instances WHERE status IN ('blocked','silenced') ORDER BY domain ASC");
    }

    /**
     * Export all blocked/silenced instances as a Mastodon-compatible CSV string.
     * Format: #domain,#severity,#public_comment,#private_comment
     * Severity: "suspend" (blocked) or "silence" (silenced) — matches Mastodon's format.
     */
    public static function exportCsv(): string
    {
        $rows = self::blocked();
        $out  = fopen('php://temp', 'r+');

        fputcsv($out, ['#domain', '#severity', '#public_comment', '#private_comment']);

        foreach ($rows as $row) {
            $severity = $row['status'] === 'blocked' ? 'suspend' : 'silence';
            fputcsv($out, [
                $row['domain'],
                $severity,
                $row['public_reason']  ?? '',
                $row['private_reason'] ?? '',
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /**
     * Import a Mastodon-compatible CSV block list.
     * Accepts both "#domain" header style and plain "domain" header style.
     * Columns: domain, severity (suspend/silence/block), public_comment, private_comment.
     *
     * @param  string  $csvContent  Raw CSV string
     * @param  bool    $overwrite   If true, update existing entries; if false, skip existing
     * @return array{imported:int, skipped:int, errors:string[]}
     */
    public static function importCsv(string $csvContent, bool $overwrite = true): array
    {
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csvContent);
        rewind($handle);

        // Read header row
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['CSV file appears to be empty.']];
        }

        // Normalise headers: strip leading '#', lowercase
        $header = array_map(fn($h) => strtolower(ltrim(trim($h), '#')), $header);

        // Map column names flexibly
        $colMap = [];
        $aliases = [
            'domain'          => ['domain'],
            'severity'        => ['severity', 'action', 'status'],
            'public_comment'  => ['public_comment', 'public_reason', 'comment'],
            'private_comment' => ['private_comment', 'private_reason', 'note'],
        ];
        foreach ($aliases as $key => $candidates) {
            foreach ($candidates as $candidate) {
                $idx = array_search($candidate, $header, true);
                if ($idx !== false) {
                    $colMap[$key] = $idx;
                    break;
                }
            }
        }

        if (!isset($colMap['domain'])) {
            fclose($handle);
            return ['imported' => 0, 'skipped' => 0, 'errors' => ["No 'domain' column found in CSV header."]];
        }

        $lineNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            $domain = strtolower(trim($row[$colMap['domain']] ?? ''));
            if ($domain === '' || str_starts_with($domain, '#')) continue;  // blank / comment

            // Basic domain sanity check
            if (!preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/i', $domain)) {
                $errors[] = "Line $lineNum: invalid domain '$domain' — skipped.";
                $skipped++;
                continue;
            }

            $rawSeverity   = strtolower(trim($row[$colMap['severity']        ?? -1] ?? 'suspend'));
            $publicComment = trim($row[$colMap['public_comment']  ?? -1] ?? '');
            $privateComment= trim($row[$colMap['private_comment'] ?? -1] ?? '');

            // Normalise severity
            $status = match(true) {
                in_array($rawSeverity, ['suspend','block','blocked']) => 'blocked',
                in_array($rawSeverity, ['silence','silenced','noop']) => 'silenced',
                default                                               => 'blocked',
            };

            $existing = self::findByDomain($domain);
            if ($existing && !$overwrite) {
                $skipped++;
                continue;
            }

            if ($status === 'blocked') {
                self::block($domain, $publicComment, $privateComment);
            } else {
                self::silence($domain, $publicComment, $privateComment);
            }
            $imported++;
        }

        fclose($handle);
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }
}
