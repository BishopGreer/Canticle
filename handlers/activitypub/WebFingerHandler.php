<?php
namespace Canticle\Handlers\Activitypub;

use Canticle\Core\{Request, Response};
use Canticle\Models\User;

class WebFingerHandler
{
    public function handle(Request $req, Response $res): void
    {
        $resource = $req->query('resource', '');
        // Accepts: acct:user@domain or https://domain/users/user
        if (str_starts_with($resource, 'acct:')) {
            $acct     = substr($resource, 5);
            [$username, $domain] = explode('@', $acct, 2) + ['', ''];
        } elseif (str_starts_with($resource, 'https://')) {
            $path     = parse_url($resource, PHP_URL_PATH);
            $username = basename($path);
            $domain   = parse_url($resource, PHP_URL_HOST);
        } else {
            $res->json(['error' => 'Invalid resource'], 400);
        }

        if (strtolower($domain) !== strtolower(config('domain'))) {
            $res->json(['error' => 'Not found'], 404);
        }

        $user = User::findByUsername($username);
        if (!$user || $user['suspended']) {
            $res->json(['error' => 'Not found'], 404);
        }

        $baseUrl  = BASE_URL;
        $actorUrl = actorUrl($user['username']);

        // RFC 7033 requires application/jrd+json.  Mastodon checks the MIME type
        // and raises a WebfingerError if it receives plain application/json.
        // We bypass $res->json() here so we can set the canonical content type.
        $payload = [
            'subject' => "acct:{$user['username']}@" . config('domain'),
            'aliases' => [$actorUrl, "$baseUrl/@{$user['username']}"],
            'links'   => [
                [
                    'rel'  => 'http://webfinger.net/rel/profile-page',
                    'type' => 'text/html',
                    'href' => "$baseUrl/@{$user['username']}",
                ],
                [
                    'rel'  => 'self',
                    'type' => 'application/activity+json',
                    'href' => $actorUrl,
                ],
                [
                    'rel'      => 'http://ostatus.org/schema/1.0/subscribe',
                    'template' => "$baseUrl/authorize_interaction?uri={uri}",
                ],
            ],
        ];

        http_response_code(200);
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/jrd+json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
