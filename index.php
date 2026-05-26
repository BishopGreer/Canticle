<?php
// Canticle — front controller

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Canticle\Core\{Router, Request, Response};
use Canticle\Handlers\Activitypub\{WebFingerHandler, NodeInfoHandler, ActorHandler, InboxHandler};
use Canticle\Handlers\Api\{
    InstanceHandler, OAuthHandler, AccountsHandler, StatusesHandler,
    TimelinesHandler, NotificationsHandler, MediaHandler, PollsHandler, SearchHandler
};
use Canticle\Handlers\Web\WebHandler;
use Canticle\Handlers\Admin\AdminHandler;

$router = new Router();
$req    = new Request();
$res    = new Response();

// ── CORS for API ──────────────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key');
    header('Access-Control-Expose-Headers: Link, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset');
    if ($req->method() === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ── Media file serving ────────────────────────────────────────────────────────
if (str_starts_with($req->path(), '/media/')) {
    $file = config('storage_path') . $req->path();
    if (file_exists($file) && is_file($file)) {
        $mime = mime_content_type($file) ?: 'application/octet-stream';
        header("Content-Type: $mime");
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($file);
    } else {
        http_response_code(404);
    }
    exit;
}

// ── ActivityPub / WebFinger ───────────────────────────────────────────────────
$router->get('/.well-known/webfinger',       [WebFingerHandler::class, 'handle']);
$router->get('/.well-known/nodeinfo',        [NodeInfoHandler::class,  'wellKnown']);
$router->get('/nodeinfo/2.1',                [NodeInfoHandler::class,  'nodeinfo21']);

$router->get('/users/:username',             [ActorHandler::class, 'actor']);
$router->get('/users/:username/followers',   [ActorHandler::class, 'followers']);
$router->get('/users/:username/following',   [ActorHandler::class, 'following']);
$router->get('/users/:username/outbox',      [ActorHandler::class, 'outbox']);
$router->post('/users/:username/inbox',      [InboxHandler::class, 'userInbox']);
$router->post('/inbox',                      [InboxHandler::class, 'sharedInbox']);

// ── OAuth ─────────────────────────────────────────────────────────────────────
$router->post('/api/v1/apps',        [OAuthHandler::class, 'registerApp']);
$router->get('/oauth/authorize',     [OAuthHandler::class, 'authorizeForm']);
$router->post('/oauth/authorize',    [OAuthHandler::class, 'authorize']);
$router->post('/oauth/token',        [OAuthHandler::class, 'token']);
$router->post('/oauth/revoke',       [OAuthHandler::class, 'revoke']);

// ── Instance ──────────────────────────────────────────────────────────────────
$router->get('/api/v1/instance',             [InstanceHandler::class, 'v1']);
$router->get('/api/v2/instance',             [InstanceHandler::class, 'v2']);
$router->get('/api/v1/instance/peers',       [InstanceHandler::class, 'peers']);
$router->get('/api/v1/instance/domain_blocks', [InstanceHandler::class, 'blockedDomains']);
$router->get('/api/v1/instance/extended_description', [InstanceHandler::class, 'extendedDescription']);

// ── Accounts ──────────────────────────────────────────────────────────────────
$router->get('/api/v1/accounts/verify_credentials',    [AccountsHandler::class, 'verifyCredentials']);
$router->patch('/api/v1/accounts/update_credentials',  [AccountsHandler::class, 'updateCredentials']);
$router->get('/api/v1/accounts/relationships',         [AccountsHandler::class, 'relationships']);
$router->get('/api/v1/accounts/search',                [AccountsHandler::class, 'search']);
$router->get('/api/v1/accounts/:id',                   [AccountsHandler::class, 'show']);
$router->get('/api/v1/accounts/:id/statuses',          [AccountsHandler::class, 'statuses']);
$router->get('/api/v1/accounts/:id/followers',         [AccountsHandler::class, 'followers']);
$router->get('/api/v1/accounts/:id/following',         [AccountsHandler::class, 'following']);
$router->post('/api/v1/accounts/:id/follow',           [AccountsHandler::class, 'follow']);
$router->post('/api/v1/accounts/:id/unfollow',         [AccountsHandler::class, 'unfollow']);
$router->post('/api/v1/remote_accounts/:id/follow',    [AccountsHandler::class, 'followRemote']);
$router->post('/api/v1/remote_accounts/:id/unfollow',  [AccountsHandler::class, 'unfollowRemote']);
$router->post('/api/v1/accounts/:id/block',            [AccountsHandler::class, 'block']);
$router->post('/api/v1/accounts/:id/unblock',          [AccountsHandler::class, 'unblock']);
$router->post('/api/v1/accounts/:id/mute',             [AccountsHandler::class, 'mute']);
$router->post('/api/v1/accounts/:id/unmute',           [AccountsHandler::class, 'unmute']);
$router->get('/api/v1/blocks',                         [AccountsHandler::class, 'blocks']);
$router->get('/api/v1/mutes',                          [AccountsHandler::class, 'mutes']);
$router->get('/api/v1/follow_requests',                [AccountsHandler::class, 'followRequests']);
$router->post('/api/v1/follow_requests/:id/authorize', [AccountsHandler::class, 'authorizeFollowRequest']);
$router->post('/api/v1/follow_requests/:id/reject',    [AccountsHandler::class, 'rejectFollowRequest']);

// ── Statuses ──────────────────────────────────────────────────────────────────
$router->post('/api/v1/statuses',                    [StatusesHandler::class, 'create']);
$router->get('/api/v1/statuses/:id',                 [StatusesHandler::class, 'show']);
$router->delete('/api/v1/statuses/:id',              [StatusesHandler::class, 'delete']);
$router->get('/api/v1/statuses/:id/context',         [StatusesHandler::class, 'context']);
$router->get('/api/v1/statuses/:id/favourited_by',   [StatusesHandler::class, 'favouritedBy']);
$router->get('/api/v1/statuses/:id/reblogged_by',    [StatusesHandler::class, 'rebloggedBy']);
$router->post('/api/v1/statuses/:id/favourite',      [StatusesHandler::class, 'favourite']);
$router->post('/api/v1/statuses/:id/unfavourite',    [StatusesHandler::class, 'unfavourite']);
$router->post('/api/v1/statuses/:id/reblog',         [StatusesHandler::class, 'reblog']);
$router->post('/api/v1/statuses/:id/unreblog',       [StatusesHandler::class, 'unreblog']);

// ── Timelines ─────────────────────────────────────────────────────────────────
$router->get('/api/v1/timelines/public',         [TimelinesHandler::class, 'public']);
$router->get('/api/v1/timelines/home',           [TimelinesHandler::class, 'home']);
$router->get('/api/v1/timelines/tag/:hashtag',   [TimelinesHandler::class, 'hashtag']);
$router->get('/api/v1/conversations',            [TimelinesHandler::class, 'conversations']);

// ── Notifications ─────────────────────────────────────────────────────────────
$router->get('/api/v1/notifications',            [NotificationsHandler::class, 'index']);
$router->get('/api/v1/notifications/:id',        [NotificationsHandler::class, 'show']);
$router->post('/api/v1/notifications/:id/dismiss', [NotificationsHandler::class, 'dismiss']);
$router->post('/api/v1/notifications/clear',     [NotificationsHandler::class, 'clear']);

// ── Media ─────────────────────────────────────────────────────────────────────
$router->post('/api/v1/media',          [MediaHandler::class, 'upload']);
$router->post('/api/v2/media',          [MediaHandler::class, 'upload']);
$router->get('/api/v1/media/:id',       [MediaHandler::class, 'show']);
$router->put('/api/v1/media/:id',       [MediaHandler::class, 'update']);

// ── Polls ─────────────────────────────────────────────────────────────────────
$router->get('/api/v1/polls/:id',             [PollsHandler::class, 'show']);
$router->post('/api/v1/polls/:id/votes',      [PollsHandler::class, 'vote']);

// ── Search ────────────────────────────────────────────────────────────────────
$router->get('/api/v1/search',  [SearchHandler::class, 'search']);
$router->get('/api/v2/search',  [SearchHandler::class, 'search']);

// ── Stubbed endpoints (for app compatibility) ─────────────────────────────────
$noop = fn($req, $res) => $res->json([]);
$router->get('/api/v1/custom_emojis',     $noop);
$router->get('/api/v1/lists',             $noop);
$router->get('/api/v1/filters',           $noop);
$router->get('/api/v1/markers',           $noop);
$router->post('/api/v1/markers',          $noop);
$router->get('/api/v1/announcements',     $noop);
$router->get('/api/v1/trends/statuses',   $noop);
$router->get('/api/v1/trends/links',      $noop);
$router->get('/api/v1/trends/tags',       $noop);
$router->get('/api/v1/bookmarks',         $noop);
$router->post('/api/v1/statuses/:id/bookmark',   $noop);
$router->post('/api/v1/statuses/:id/unbookmark', $noop);
$router->post('/api/v1/statuses/:id/pin',        [StatusesHandler::class, 'pin']);
$router->post('/api/v1/statuses/:id/unpin',      [StatusesHandler::class, 'unpin']);
$router->get('/api/v1/featured_tags',     $noop);
$router->get('/api/v1/preferences',       fn($q, $r) => $r->json(['posting:default:visibility' => 'public', 'posting:default:sensitive' => false, 'posting:default:language' => config('site_lang','en'), 'reading:expand:media' => 'default', 'reading:expand:spoilers' => false]));
$router->get('/api/v1/streaming/health',  fn($q, $r) => $r->json(['status' => 'OK']));
$router->get('/api/v1/profile/avatar',    $noop);
$router->delete('/api/v1/profile/avatar', $noop);

// ── Admin ─────────────────────────────────────────────────────────────────────
$router->get('/admin',                        [AdminHandler::class, 'dashboard']);
$router->get('/admin/settings',               [AdminHandler::class, 'settingsForm']);
$router->post('/admin/settings',              [AdminHandler::class, 'settingsSave']);
$router->get('/admin/rules',                  [AdminHandler::class, 'rulesForm']);
$router->post('/admin/rules',                 [AdminHandler::class, 'rulesSave']);
$router->get('/admin/privacy',                [AdminHandler::class, 'privacyForm']);
$router->post('/admin/privacy',               [AdminHandler::class, 'privacySave']);
$router->get('/admin/users',                  [AdminHandler::class, 'users']);
$router->post('/admin/users/:id/suspend',     [AdminHandler::class, 'suspendUser']);
$router->post('/admin/users/:id/unsuspend',   [AdminHandler::class, 'unsuspendUser']);
$router->post('/admin/users/:id/promote',     [AdminHandler::class, 'promoteUser']);
$router->get('/admin/federation',             [AdminHandler::class, 'federation']);
$router->post('/admin/federation/block',      [AdminHandler::class, 'blockDomain']);
$router->post('/admin/federation/unblock',    [AdminHandler::class, 'unblockDomain']);
$router->post('/admin/federation/silence',    [AdminHandler::class, 'silenceDomain']);
$router->get('/admin/federation/export',      [AdminHandler::class, 'exportBlocks']);
$router->post('/admin/federation/import',     [AdminHandler::class, 'importBlocks']);
$router->get('/admin/statuses',               [AdminHandler::class, 'statuses']);
$router->post('/admin/statuses/:id/delete',   [AdminHandler::class, 'deleteStatus']);
$router->get('/admin/queue',                  [AdminHandler::class, 'queue']);
$router->post('/admin/queue/retry/:id',       [AdminHandler::class, 'retryJob']);
$router->post('/admin/queue/delete/:id',      [AdminHandler::class, 'deleteJob']);
$router->post('/admin/queue/clear-failed',    [AdminHandler::class, 'clearFailed']);
$router->get('/admin/relays',                 [AdminHandler::class, 'relays']);
$router->post('/admin/relays/add',            [AdminHandler::class, 'addRelay']);
$router->post('/admin/relays/:id/retry',      [AdminHandler::class, 'retryRelay']);
$router->post('/admin/relays/:id/remove',     [AdminHandler::class, 'removeRelay']);
$router->get('/admin/prune',                     [AdminHandler::class, 'pruneForm']);
$router->post('/admin/prune/settings',           [AdminHandler::class, 'pruneSettings']);
$router->post('/admin/prune/run',                [AdminHandler::class, 'pruneRun']);
$router->get('/admin/upgrades',                   [AdminHandler::class, 'upgrades']);
$router->post('/admin/upgrades/migrate',          [AdminHandler::class, 'runMigrations']);
$router->post('/admin/upgrades/pull',             [AdminHandler::class, 'runGitPull']);
$router->post('/admin/upgrades/opcache-flush',    [AdminHandler::class, 'flushOpcache']);
$router->post('/admin/upgrades/check-updates',    [AdminHandler::class, 'checkUpdates']);
$router->get('/admin/server-status',             [AdminHandler::class, 'serverStatus']);
$router->post('/admin/server-status/flush',      [AdminHandler::class, 'flushServerCache']);

// ── Web UI ────────────────────────────────────────────────────────────────────
$router->get('/',                [WebHandler::class, 'home']);
$router->get('/public',          [WebHandler::class, 'publicTimeline']);
$router->get('/search',          [WebHandler::class, 'search']);
$router->get('/rules',           [WebHandler::class, 'rules']);
$router->get('/privacy',         [WebHandler::class, 'privacy']);
$router->get('/settings',        [WebHandler::class, 'settingsForm']);
$router->post('/settings',       [WebHandler::class, 'settingsSave']);
$router->get('/notifications',   [WebHandler::class, 'notifications']);
$router->get('/auth/sign_in',    [WebHandler::class, 'signInForm']);
$router->post('/auth/sign_in',   [WebHandler::class, 'signIn']);
$router->get('/auth/sign_out',   [WebHandler::class, 'signOut']);
$router->get('/auth/sign_up',    [WebHandler::class, 'signUpForm']);
$router->post('/auth/sign_up',   [WebHandler::class, 'signUp']);
$router->get('/tags/:tag',                [WebHandler::class, 'tag']);
$router->post('/web/follow/local/:id',    [WebHandler::class, 'followLocal']);
$router->post('/web/unfollow/local/:id',  [WebHandler::class, 'unfollowLocal']);
$router->post('/web/follow/remote/:id',   [WebHandler::class, 'followRemoteActor']);
$router->post('/web/unfollow/remote/:id', [WebHandler::class, 'unfollowRemoteActor']);
$router->get('/@:username',               [WebHandler::class, 'profile']);
$router->get('/@:username/:statusId',     [WebHandler::class, 'statusPermalink']);

// ── Dispatch ──────────────────────────────────────────────────────────────────
$router->dispatch($req, $res);
