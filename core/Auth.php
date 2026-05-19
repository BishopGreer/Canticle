<?php
namespace Canticle\Core;

use Canticle\Models\User;
use Canticle\Models\OAuthToken;

class Auth
{
    private static ?array $currentUser = null;

    public static function user(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser === [] ? null : self::$currentUser;
        }

        // 1. Bearer token (API clients)
        $req = new Request();
        $token = $req->bearerToken();
        if ($token) {
            $record = OAuthToken::findByToken($token);
            if ($record && !$record['revoked'] && ($record['expires_at'] === null || strtotime($record['expires_at']) > time())) {
                $user = User::find($record['user_id']);
                if ($user && !$user['suspended']) {
                    self::$currentUser = array_merge($user, ['token_scopes' => $record['scopes'], 'app_id' => $record['app_id']]);
                    return self::$currentUser;
                }
            }
        }

        // 2. Session (web UI)
        Session::start();
        $userId = Session::get('user_id');
        if ($userId) {
            $user = User::find($userId);
            if ($user && !$user['suspended']) {
                self::$currentUser = $user;
                return self::$currentUser;
            }
        }

        self::$currentUser = [];
        return null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function hasScope(string $scope): bool
    {
        $user = self::user();
        if (!$user) return false;

        // Session-authenticated users (web UI) have no token scope restrictions.
        if (!isset($user['token_scopes'])) return true;

        // Token users: check exact scope OR the broad prefix (e.g. "write" covers "write:statuses").
        $scopes = explode(' ', $user['token_scopes']);
        if (in_array($scope, $scopes)) return true;

        // Allow broad scope to cover namespaced scope (write → write:statuses)
        $prefix = explode(':', $scope)[0];
        return in_array($prefix, $scopes);
    }

    public static function requireUser(Response $response): array
    {
        $user = self::user();
        if (!$user) {
            $response->json(['error' => 'Unauthorized'], 401);
        }
        return $user;
    }

    public static function requireAdmin(Response $response): array
    {
        $user = self::requireUser($response);
        if (!in_array($user['role'], ['admin', 'moderator'])) {
            $response->json(['error' => 'Forbidden'], 403);
        }
        return $user;
    }

    public static function login(int $userId): void
    {
        Session::start();
        session_regenerate_id(true);
        Session::set('user_id', $userId);
        self::$currentUser = null;
    }

    public static function logout(): void
    {
        Session::start();
        Session::flush();
        self::$currentUser = null;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
