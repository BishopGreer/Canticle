<?php
namespace Canticle\Models;

class OAuthToken
{
    public static function findByToken(string $token): ?array
    {
        return db()->fetch('SELECT * FROM oauth_tokens WHERE access_token = ? AND revoked = 0', [$token]);
    }

    public static function create(int $userId, int $appId, string $scopes = 'read'): array
    {
        $token = bin2hex(random_bytes(32));
        db()->insert('oauth_tokens', [
            'user_id'      => $userId,
            'app_id'       => $appId,
            'access_token' => $token,
            'scopes'       => $scopes,
        ]);
        return [
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'scope'        => $scopes,
            'created_at'   => time(),
        ];
    }

    public static function createCode(int $userId, int $appId, string $scopes, string $redirectUri): string
    {
        $code = bin2hex(random_bytes(16));
        db()->insert('oauth_codes', [
            'user_id'      => $userId,
            'app_id'       => $appId,
            'code'         => $code,
            'scopes'       => $scopes,
            'redirect_uri' => $redirectUri,
            'expires_at'   => date('Y-m-d H:i:s', time() + 600),
        ]);
        return $code;
    }

    public static function exchangeCode(string $code): ?array
    {
        $row = db()->fetch(
            "SELECT * FROM oauth_codes WHERE code = ? AND used = 0 AND expires_at > NOW()",
            [$code]
        );
        if (!$row) return null;
        db()->update('oauth_codes', ['used' => 1], 'id = ?', [$row['id']]);
        return $row;
    }

    public static function revoke(string $token): void
    {
        db()->update('oauth_tokens', ['revoked' => 1], 'access_token = ?', [$token]);
    }
}
