<?php
namespace Canticle\Models;

class OAuthApp
{
    public static function find(int $id): ?array
    {
        return db()->fetch('SELECT * FROM oauth_apps WHERE id = ?', [$id]);
    }

    public static function findByClientId(string $clientId): ?array
    {
        return db()->fetch('SELECT * FROM oauth_apps WHERE client_id = ?', [$clientId]);
    }

    public static function create(string $name, string $redirectUris, string $scopes = 'read', string $website = ''): array
    {
        $clientId     = bin2hex(random_bytes(16));
        $clientSecret = bin2hex(random_bytes(32));
        $id = db()->insert('oauth_apps', [
            'name'          => $name,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uris' => $redirectUris,
            'scopes'        => $scopes,
            'website'       => $website,
        ]);
        return [
            'id'            => $id,
            'name'          => $name,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUris,
            'vapid_key'     => '',
        ];
    }
}
