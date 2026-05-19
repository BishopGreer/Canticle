<?php
namespace Canticle\Handlers\Api;

use Canticle\Core\{Request, Response, Auth, Session};
use Canticle\Models\{User, OAuthApp, OAuthToken};

class OAuthHandler
{
    /** POST /api/v1/apps — register an application */
    public function registerApp(Request $req, Response $res): void
    {
        $name         = $req->input('client_name', '');
        $redirectUris = $req->input('redirect_uris', 'urn:ietf:wg:oauth:2.0:oob');
        $scopes       = $req->input('scopes', 'read');
        $website      = $req->input('website', '');

        if (!$name) $res->error('client_name is required');

        $app = OAuthApp::create($name, $redirectUris, $scopes, $website);
        $res->json($app);
    }

    /** GET /oauth/authorize — show login + authorize form */
    public function authorizeForm(Request $req, Response $res): void
    {
        $clientId    = $req->query('client_id', '');
        $redirectUri = $req->query('redirect_uri', '');
        $scope       = $req->query('scope', 'read');
        $responseType= $req->query('response_type', 'code');

        $app = OAuthApp::findByClientId($clientId);
        if (!$app) $res->redirect('/');

        Session::start();
        Session::set('oauth_app_id', $app['id']);
        Session::set('oauth_redirect_uri', $redirectUri);
        Session::set('oauth_scope', $scope);

        $user = Auth::user();
        if ($user) {
            // Already logged in — auto-authorize
            $this->issueCode($user, $app, $scope, $redirectUri, $res);
        }

        // Show login page
        $html = $this->authorizeHtml($app, $scope, $req->query('state', ''));
        $res->html($html);
    }

    /** POST /oauth/authorize — handle form submission */
    public function authorize(Request $req, Response $res): void
    {
        Session::start();

        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            $res->error('Invalid CSRF token', 403);
        }

        $email    = $req->input('email', '');
        $password = $req->input('password', '');
        $appId    = Session::get('oauth_app_id');
        $redirect = Session::get('oauth_redirect_uri', '');
        $scope    = Session::get('oauth_scope', 'read');

        $user = User::findByEmail($email);
        if (!$user || !Auth::verifyPassword($password, $user['password_hash'])) {
            $res->redirect('/oauth/authorize?error=invalid_credentials');
        }

        $app = OAuthApp::find($appId);
        if (!$app) $res->redirect('/');

        Auth::login($user['id']);
        $this->issueCode($user, $app, $scope, $redirect, $res);
    }

    /** POST /oauth/token — exchange code or password for token */
    public function token(Request $req, Response $res): void
    {
        $grantType = $req->input('grant_type', '');

        if ($grantType === 'authorization_code') {
            $code        = $req->input('code', '');
            $clientId    = $req->input('client_id', '');
            $clientSecret= $req->input('client_secret', '');
            $redirectUri = $req->input('redirect_uri', '');

            $app = OAuthApp::findByClientId($clientId);
            if (!$app || $app['client_secret'] !== $clientSecret) {
                $res->json(['error' => 'invalid_client'], 401);
            }

            $codeRow = OAuthToken::exchangeCode($code);
            if (!$codeRow) $res->json(['error' => 'invalid_grant'], 400);

            $token = OAuthToken::create($codeRow['user_id'], $app['id'], $codeRow['scopes']);
            $res->json($token);
        }

        if ($grantType === 'password') {
            // RFC 6749 password grant (used by some older clients)
            $email    = $req->input('username', '');
            $password = $req->input('password', '');
            $scope    = $req->input('scope', 'read');
            $clientId = $req->input('client_id', '');

            $user = User::findByEmail($email);
            if (!$user || !Auth::verifyPassword($password, $user['password_hash'])) {
                $res->json(['error' => 'invalid_grant'], 400);
            }

            $app   = OAuthApp::findByClientId($clientId) ?? ['id' => 0];
            $token = OAuthToken::create($user['id'], $app['id'], $scope);
            $res->json($token);
        }

        if ($grantType === 'client_credentials') {
            $res->json(['access_token' => '', 'token_type' => 'Bearer', 'scope' => 'read', 'created_at' => time()]);
        }

        $res->json(['error' => 'unsupported_grant_type'], 400);
    }

    /** POST /oauth/revoke */
    public function revoke(Request $req, Response $res): void
    {
        $token = $req->input('token', '');
        if ($token) OAuthToken::revoke($token);
        $res->json([]);
    }

    private function issueCode(array $user, array $app, string $scope, string $redirectUri, Response $res): never
    {
        $code = OAuthToken::createCode($user['id'], $app['id'], $scope, $redirectUri);
        if ($redirectUri === 'urn:ietf:wg:oauth:2.0:oob') {
            $res->html("<html><body><h1>Authorization Code</h1><p>$code</p></body></html>");
        }
        $sep = str_contains($redirectUri, '?') ? '&' : '?';
        $res->redirect($redirectUri . $sep . 'code=' . urlencode($code));
    }

    private function authorizeHtml(array $app, string $scope, string $state): string
    {
        $csrf    = Session::csrfToken();
        $appName = htmlspecialchars($app['name']);
        $scope   = htmlspecialchars($scope);
        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><title>Authorize {$appName}</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <link rel="stylesheet" href="/assets/css/app.css">
        </head>
        <body class="auth-page">
        <div class="auth-box">
          <h1>Sign in to Canticle</h1>
          <p><strong>{$appName}</strong> wants to access your account with scope: <em>{$scope}</em></p>
          <form method="POST" action="/oauth/authorize">
            <input type="hidden" name="_csrf" value="{$csrf}">
            <input type="hidden" name="state" value="{$state}">
            <label>Email<input type="email" name="email" required autocomplete="email"></label>
            <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
            <button type="submit">Authorize</button>
          </form>
        </div>
        </body></html>
        HTML;
    }
}
