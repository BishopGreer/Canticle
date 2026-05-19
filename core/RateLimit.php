<?php
namespace Canticle\Core;

class RateLimit
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function check(string $key, int $maxRequests, int $windowSeconds = 300): bool
    {
        $now  = time();
        $row  = $this->db->fetch("SELECT * FROM rate_limits WHERE key_ = ?", [$key]);

        if (!$row) {
            $this->db->query(
                "INSERT INTO rate_limits (key_, tokens, last_refill) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE key_ = key_",
                [$key, $maxRequests - 1]
            );
            return true;
        }

        $elapsed = $now - strtotime($row['last_refill']);
        $refill  = (int) floor($elapsed / $windowSeconds * $maxRequests);
        $tokens  = min($maxRequests, $row['tokens'] + $refill);

        if ($tokens <= 0) return false;

        $newRefill = $refill > 0 ? date('Y-m-d H:i:s') : $row['last_refill'];
        $this->db->query(
            "UPDATE rate_limits SET tokens = ?, last_refill = ? WHERE key_ = ?",
            [$tokens - 1, $newRefill, $key]
        );
        return true;
    }
}
