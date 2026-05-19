<?php
namespace Canticle\Core;

class Queue
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function push(string $job, array $payload, string $queue = 'default', int $delaySeconds = 0): void
    {
        $available = gmdate('Y-m-d H:i:s', time() + $delaySeconds);
        $this->db->insert('queue_jobs', [
            'queue'        => $queue,
            'payload'      => json_encode(['job' => $job, 'data' => $payload]),
            'attempts'     => 0,
            'available_at' => $available,
        ]);
    }

    public function pop(string $queue = 'default'): ?array
    {
        // Find the next available job — UTC_TIMESTAMP() avoids MySQL timezone
        // mismatches; gmdate() on the PHP side keeps everything in UTC.
        $job = $this->db->fetch(
            'SELECT * FROM queue_jobs
              WHERE queue = ? AND reserved_at IS NULL AND available_at <= UTC_TIMESTAMP()
              ORDER BY id ASC LIMIT 1',
            [$queue]
        );
        if (!$job) return null;

        $claimed = $this->db->update(
            'queue_jobs',
            ['reserved_at' => gmdate('Y-m-d H:i:s')],
            'id = ? AND reserved_at IS NULL',
            [$job['id']]
        );

        return $claimed > 0 ? $job : null;
    }

    public function complete(int $jobId): void
    {
        $this->db->delete('queue_jobs', 'id = ?', [$jobId]);
    }

    public function fail(int $jobId, string $reason): void
    {
        $job = $this->db->fetch('SELECT * FROM queue_jobs WHERE id = ?', [$jobId]);
        if ($job) {
            $attempts = $job['attempts'] + 1;
            if ($attempts >= 5) {
                $this->db->insert('queue_failed', [
                    'queue'     => $job['queue'],
                    'payload'   => $job['payload'],
                    'exception' => $reason,
                ]);
                $this->db->delete('queue_jobs', 'id = ?', [$jobId]);
            } else {
                $this->db->update('queue_jobs', [
                    'attempts'    => $attempts,
                    'reserved_at' => null,
                    'available_at' => gmdate('Y-m-d H:i:s', time() + (60 * $attempts)),
                ], 'id = ?', [$jobId]);
            }
        }
    }
}
