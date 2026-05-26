<?php
namespace Canticle\Handlers\Web;

use Canticle\Core\{Request, Response, Auth, Session, Federator, Queue};
use Canticle\Models\{User, Status, Notification, Follow, RemoteActor};

class WebHandler
{
    /** GET / — home / public timeline */
    public function home(Request $req, Response $res): void
    {
        $user = Auth::user();
        Session::start();

        if ($user) {
            $statuses = Status::homeTimeline($user['id'], 20);
            $unread   = Notification::unreadCount($user['id']);
            $res->html($this->render('web/timeline', [
                'user'     => $user,
                'statuses' => $statuses,
                'unread'   => $unread,
                'title'    => 'Home',
                'tab'      => 'home',
            ]));
        }

        // Logged-out: public timeline
        $statuses = Status::publicTimeline(20);
        $res->html($this->render('web/timeline', [
            'user'     => null,
            'statuses' => $statuses,
            'unread'   => 0,
            'title'    => config('site_name'),
            'tab'      => 'public',
        ]));
    }

    /** GET /public */
    public function publicTimeline(Request $req, Response $res): void
    {
        $user     = Auth::user();
        $statuses = Status::publicTimeline(40);
        $res->html($this->render('web/timeline', [
            'user'     => $user,
            'statuses' => $statuses,
            'unread'   => $user ? Notification::unreadCount($user['id']) : 0,
            'title'    => 'Public Timeline',
            'tab'      => 'public',
        ]));
    }

    /** GET /@:username  or  /@username@domain */
    public function profile(Request $req, Response $res): void
    {
        $param  = $req->param('username');
        $viewer = Auth::user();

        // ── Remote profile: username@domain ───────────────────────────────────
        if (str_contains($param, '@')) {
            [$remoteUser, $remoteDomain] = explode('@', $param, 2);

            // Try local cache first
            $actor = \Canticle\Models\RemoteActor::findByWebfinger($remoteUser, $remoteDomain);

            // Not cached — resolve via WebFinger then fetch actor doc
            if (!$actor) {
                $http      = new \Canticle\Core\HttpClient(10);
                $actorUri  = $http->webfinger($remoteUser, $remoteDomain);
                if ($actorUri) {
                    $actor = \Canticle\Models\RemoteActor::fetchAndStore($actorUri, true);
                }
            } else {
                // Refresh counts and import outbox on each profile visit (cached for 1 hr)
                \Canticle\Models\RemoteActor::fetchAndStore($actor['uri'], true);
                $actor = \Canticle\Models\RemoteActor::findByWebfinger($remoteUser, $remoteDomain);
            }

            if (!$actor) $res->error('User not found', 404);

            // Import recent posts from their outbox (new ones only)
            \Canticle\Models\RemoteActor::importOutbox($actor);

            $statuses = db()->fetchAll(
                "SELECT * FROM statuses WHERE remote_actor_id = ? AND deleted_at IS NULL
                 AND reblog_of_id IS NULL ORDER BY id DESC LIMIT 20",
                [$actor['id']]
            );

            Session::start();
            $res->html($this->render('web/remote_profile', [
                'actor'    => $actor,
                'user'     => $viewer,
                'statuses' => $statuses,
                'csrf'     => Session::csrfToken(),
                'unread'   => $viewer ? Notification::unreadCount($viewer['id']) : 0,
                'title'    => ($actor['display_name'] ?: $actor['username']) . '@' . $actor['domain'] . ' — ' . config('site_name'),
            ]));
        }

        // ── Local profile ─────────────────────────────────────────────────────
        $user = User::findByUsername($param);
        if (!$user) $res->error('Not found', 404);

        // AP content negotiation
        if ($req->accepts('application/activity+json') || $req->accepts('application/ld+json')) {
            $res->activityJson(User::toActivityPub($user));
        }

        Session::start();

        $pinnedStatuses = Status::pinnedForUser($user['id']);

        $statuses = db()->fetchAll(
            "SELECT * FROM statuses WHERE local_user_id = ? AND deleted_at IS NULL AND reblog_of_id IS NULL
             AND (pinned_at IS NULL OR pinned_at IS NOT NULL)
             ORDER BY created_at DESC, id DESC LIMIT 20",
            [$user['id']]
        );

        // Determine follow state for the viewer
        $followState = null;
        if ($viewer && $viewer['id'] !== $user['id']) {
            $f = db()->fetch(
                "SELECT state FROM follows WHERE follower_local_id = ? AND followee_local_id = ?",
                [$viewer['id'], $user['id']]
            );
            $followState = $f['state'] ?? null;
        }

        $res->html($this->render('web/profile', [
            'profile'        => $user,
            'user'           => $viewer,
            'statuses'       => $statuses,
            'pinnedStatuses' => $pinnedStatuses,
            'followState'    => $followState,
            'csrf'           => Session::csrfToken(),
            'unread'         => $viewer ? Notification::unreadCount($viewer['id']) : 0,
            'title'          => ($user['display_name'] ?: $user['username']) . ' — ' . config('site_name'),
        ]));
    }

    /** GET /@:username/:statusId */
    public function statusPermalink(Request $req, Response $res): void
    {
        $statusId  = (int) $req->param('statusId');
        $rawStatus = Status::find($statusId);
        if (!$rawStatus) $res->error('Not found', 404);

        $viewer = Auth::user();

        // ActivityPub content negotiation — serve JSON to federation crawlers
        if ($req->accepts('application/activity+json') || $req->accepts('application/ld+json')) {
            $res->activityJson(Status::toActivityPub($rawStatus)['object'] ?? []);
        }

        // Walk up the reply chain to build ancestors (oldest first, max 20 deep)
        $ancestors = [];
        $current   = $rawStatus;
        $depth     = 0;
        while ($current['reply_to_id'] && $depth < 20) {
            $parent = Status::find($current['reply_to_id']);
            if (!$parent) break;
            array_unshift($ancestors, Status::toMastodon($parent, $viewer));
            $current = $parent;
            $depth++;
        }

        // Direct replies to this post
        $childRows   = db()->fetchAll(
            'SELECT * FROM statuses WHERE reply_to_id = ? AND deleted_at IS NULL ORDER BY id ASC LIMIT 100',
            [$rawStatus['id']]
        );
        $descendants = array_map(fn($r) => Status::toMastodon($r, $viewer), $childRows);

        $res->html($this->render('web/status', [
            'status'      => Status::toMastodon($rawStatus, $viewer),
            'ancestors'   => $ancestors,
            'descendants' => $descendants,
            'user'        => $viewer,
            'tab'         => '',
            'unread'      => $viewer ? Notification::unreadCount($viewer['id']) : 0,
            'title'       => 'Post — ' . config('site_name'),
        ]));
    }

    /** GET /notifications */
    public function notifications(Request $req, Response $res): void
    {
        $user  = Auth::user();
        if (!$user) $res->redirect('/auth/sign_in');

        $rows = Notification::forUser($user['id'], 30);
        Notification::markRead($user['id']);

        $res->html($this->render('web/notifications', [
            'user'          => $user,
            'notifications' => array_map([Notification::class, 'toMastodon'], $rows),
            'unread'        => 0,
            'title'         => 'Notifications',
        ]));
    }

    /** GET /auth/sign_in */
    public function signInForm(Request $req, Response $res): void
    {
        if (Auth::check()) $res->redirect('/');
        Session::start();
        $res->html($this->render('web/sign_in', [
            'error' => Session::flash('error'),
            'title' => 'Sign in — ' . config('site_name'),
        ]));
    }

    /** POST /auth/sign_in */
    public function signIn(Request $req, Response $res): void
    {
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid CSRF token.');
            $res->redirect('/auth/sign_in');
        }

        $email    = trim($req->input('email', ''));
        $password = $req->input('password', '');
        $user     = User::findByEmail($email);

        if (!$user || !\Canticle\Core\Auth::verifyPassword($password, $user['password_hash'])) {
            Session::flash('error', 'Invalid email or password.');
            $res->redirect('/auth/sign_in');
        }

        if ($user['suspended']) {
            Session::flash('error', 'Your account has been suspended.');
            $res->redirect('/auth/sign_in');
        }

        Auth::login($user['id']);
        $res->redirect('/');
    }

    /** GET /auth/sign_out */
    public function signOut(Request $req, Response $res): void
    {
        Auth::logout();
        $res->redirect('/');
    }

    /** GET /auth/sign_up */
    public function signUpForm(Request $req, Response $res): void
    {
        $reg = config('registrations', 'closed');
        if ($reg === 'closed') {
            $res->html($this->render('web/closed', ['title' => 'Registrations Closed']));
        }
        Session::start();
        $res->html($this->render('web/sign_up', [
            'error'    => Session::flash('error'),
            'approval' => $reg === 'approval',
            'title'    => 'Create account — ' . config('site_name'),
        ]));
    }

    /** POST /auth/sign_up */
    public function signUp(Request $req, Response $res): void
    {
        Session::start();
        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid token.');
            $res->redirect('/auth/sign_up');
        }

        $reg = config('registrations', 'closed');
        if ($reg === 'closed') $res->redirect('/');

        $username = strtolower(trim($req->input('username', '')));
        $email    = strtolower(trim($req->input('email', '')));
        $password = $req->input('password', '');
        $confirm  = $req->input('password_confirmation', '');

        if (!preg_match('/^[a-z0-9_]{1,64}$/', $username)) {
            Session::flash('error', 'Username may only contain letters, numbers, and underscores.');
            $res->redirect('/auth/sign_up');
        }
        if (User::findByUsername($username) || User::findByEmail($email)) {
            Session::flash('error', 'Username or email already taken.');
            $res->redirect('/auth/sign_up');
        }
        if ($password !== $confirm || strlen($password) < 8) {
            Session::flash('error', 'Passwords must match and be at least 8 characters.');
            $res->redirect('/auth/sign_up');
        }

        $userId = User::create([
            'username'      => $username,
            'email'         => $email,
            'password_hash' => Auth::hashPassword($password),
            'display_name'  => $username,
            'email_verified'=> 1,
        ]);

        Auth::login($userId);
        $res->redirect('/');
    }

    /** GET /settings */
    public function settingsForm(Request $req, Response $res): void
    {
        $user = Auth::user();
        if (!$user) $res->redirect('/auth/sign_in');
        Session::start();
        $res->html($this->render('web/settings', [
            'user'          => $user,
            'unread'        => Notification::unreadCount($user['id']),
            'title'         => 'Edit profile — ' . config('site_name'),
            'flash_success' => Session::flash('success'),
            'flash_error'   => Session::flash('error'),
        ]));
    }

    /** POST /settings */
    public function settingsSave(Request $req, Response $res): void
    {
        $user = Auth::user();
        if (!$user) $res->redirect('/auth/sign_in');
        Session::start();

        if (!Session::verifyCsrf($req->input('_csrf', ''))) {
            Session::flash('error', 'Invalid CSRF token.');
            $res->redirect('/settings');
        }

        $updates = [];

        // Text fields
        $displayName = trim($req->input('display_name', ''));
        if ($displayName !== '') $updates['display_name'] = substr($displayName, 0, 100);

        $bio = trim($req->input('bio', ''));
        $updates['bio'] = substr($bio, 0, 500);

        $updates['locked']      = $req->input('locked')      ? 1 : 0;
        $updates['bot']         = $req->input('bot')         ? 1 : 0;
        $updates['discoverable']= $req->input('discoverable') ? 1 : 0;

        // Avatar upload
        if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $path = $this->saveProfileImage($_FILES['avatar'], 'avatar', $user['id']);
            if ($path) {
                $updates['avatar'] = $path;
            } else {
                Session::flash('error', 'Avatar upload failed — use JPEG, PNG, GIF, or WebP under 2 MB.');
                $res->redirect('/settings');
            }
        }

        // Header upload
        if (!empty($_FILES['header']['tmp_name']) && $_FILES['header']['error'] === UPLOAD_ERR_OK) {
            $path = $this->saveProfileImage($_FILES['header'], 'header', $user['id']);
            if ($path) {
                $updates['header'] = $path;
            } else {
                Session::flash('error', 'Header upload failed — use JPEG, PNG, GIF, or WebP under 4 MB.');
                $res->redirect('/settings');
            }
        }

        // Password change
        $newPass = $req->input('password', '');
        $confirm = $req->input('password_confirmation', '');
        if ($newPass !== '') {
            if (strlen($newPass) < 8) {
                Session::flash('error', 'New password must be at least 8 characters.');
                $res->redirect('/settings');
            }
            if ($newPass !== $confirm) {
                Session::flash('error', 'Passwords do not match.');
                $res->redirect('/settings');
            }
            $updates['password_hash'] = \Canticle\Core\Auth::hashPassword($newPass);
        }

        if ($updates) {
            User::update($user['id'], $updates);
        }

        Session::flash('success', 'Profile updated.');
        $res->redirect('/settings');
    }

    /** Save an uploaded profile image (avatar or header) and return the relative storage path. */
    private function saveProfileImage(array $file, string $type, int $userId): ?string
    {
        $maxBytes = $type === 'header' ? 4 * 1024 * 1024 : 2 * 1024 * 1024;
        if ($file['size'] > $maxBytes) return null;

        $mime = mime_content_type($file['tmp_name']);
        $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (!isset($exts[$mime])) return null;

        $dir = config('storage_path') . '/media/' . $type . 's';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $filename = $userId . '_' . time() . '.' . $exts[$mime];
        $dest     = $dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) return null;

        return 'media/' . $type . 's/' . $filename;
    }

    // ── Web follow/unfollow — form POST, session-authenticated ────────────────

    /** POST /web/follow/local/:id */
    public function followLocal(Request $req, Response $res): void
    {
        Session::start();
        $user = Auth::user();
        if (!$user) $res->redirect('/auth/sign_in');
        if (!Session::verifyCsrf($req->input('_csrf', ''))) $res->error('Invalid CSRF token', 403);

        $targetId = (int) $req->param('id');
        $target   = User::find($targetId);
        if (!$target) $res->error('Not found', 404);

        if ($target['id'] !== $user['id'] && !Follow::exists($user['id'], $target['id'])) {
            $state = $target['locked'] ? 'pending' : 'accepted';
            Follow::create([
                'follower_local_id' => $user['id'],
                'followee_local_id' => $target['id'],
                'state'             => $state,
                'uri'               => actorUrl($user['username']) . '#follow-' . $target['id'] . '-' . time(),
            ]);
            if ($state === 'accepted') {
                User::incrementCount($user['id'],   'following_count');
                User::incrementCount($target['id'], 'followers_count');
                \Canticle\Models\Notification::create($target['id'], 'follow', $user['id']);
            } else {
                \Canticle\Models\Notification::create($target['id'], 'follow_request', $user['id']);
            }
        }

        $back = $_SERVER['HTTP_REFERER'] ?? ('/@' . $target['username']);
        $res->redirect($back);
    }

    /** POST /web/unfollow/local/:id */
    public function unfollowLocal(Request $req, Response $res): void
    {
        Session::start();
        $user = Auth::user();
        if (!$user) $res->redirect('/auth/sign_in');
        if (!Session::verifyCsrf($req->input('_csrf', ''))) $res->error('Invalid CSRF token', 403);

        $targetId = (int) $req->param('id');
        $target   = User::find($targetId);

        Follow::remove($user['id'], $targetId);

        $back = $_SERVER['HTTP_REFERER'] ?? ('/@' . ($target['username'] ?? $targetId));
        $res->redirect($back);
    }

    /** POST /web/follow/remote/:id */
    public function followRemoteActor(Request $req, Response $res): void
    {
        Session::start();
        $user = Auth::user();
        if (!$user) $res->redirect('/auth/sign_in');
        if (!Session::verifyCsrf($req->input('_csrf', ''))) $res->error('Invalid CSRF token', 403);

        $actorId = (int) $req->param('id');
        $actor   = RemoteActor::find($actorId);
        if (!$actor) $res->error('Not found', 404);

        if (!Follow::exists($user['id'], null, $actor['id'])) {
            Follow::create([
                'follower_local_id'  => $user['id'],
                'followee_remote_id' => $actor['id'],
                'state'              => 'pending',
                'uri'                => actorUrl($user['username']) . '#follow-r' . $actor['id'] . '-' . time(),
            ]);
            $fed = new Federator(new Queue(db()));
            $fed->sendFollow($user, $actor);
        }

        $back = $_SERVER['HTTP_REFERER'] ?? ('/@' . $actor['username'] . '@' . $actor['domain']);
        $res->redirect($back);
    }

    /** POST /web/unfollow/remote/:id */
    public function unfollowRemoteActor(Request $req, Response $res): void
    {
        Session::start();
        $user = Auth::user();
        if (!$user) $res->redirect('/auth/sign_in');
        if (!Session::verifyCsrf($req->input('_csrf', ''))) $res->error('Invalid CSRF token', 403);

        $actorId = (int) $req->param('id');
        $actor   = RemoteActor::find($actorId);
        if (!$actor) $res->error('Not found', 404);

        $follow = db()->fetch(
            "SELECT * FROM follows WHERE follower_local_id = ? AND followee_remote_id = ?",
            [$user['id'], $actor['id']]
        );
        if ($follow) {
            db()->delete('follows', 'id = ?', [$follow['id']]);
            User::decrementCount($user['id'], 'following_count');
            $fed = new Federator(new Queue(db()));
            $fed->sendUnfollow($user, $actor, $follow['uri'] ?? '');
        }

        $back = $_SERVER['HTTP_REFERER'] ?? ('/@' . $actor['username'] . '@' . $actor['domain']);
        $res->redirect($back);
    }

    /** GET /tags/:tag */
    public function tag(Request $req, Response $res): void
    {
        $tag    = strtolower($req->param('tag'));
        $viewer = Auth::user();
        $tagRow = db()->fetch('SELECT id FROM hashtags WHERE name = ?', [$tag]);
        $statuses = [];
        if ($tagRow) {
            $rows = db()->fetchAll(
                "SELECT s.* FROM statuses s JOIN status_hashtags sh ON s.id = sh.status_id
                 WHERE sh.hashtag_id = ? AND s.deleted_at IS NULL AND s.visibility = 'public'
                 ORDER BY s.id DESC LIMIT 40",
                [$tagRow['id']]
            );
            $statuses = Status::manyToMastodon($rows, $viewer);
        }
        $res->html($this->render('web/timeline', [
            'user'     => $viewer,
            'statuses' => $statuses,
            'unread'   => $viewer ? Notification::unreadCount($viewer['id']) : 0,
            'title'    => "#$tag — " . config('site_name'),
            'tab'      => "tag:$tag",
        ]));
    }

    public function search(Request $req, Response $res): void
    {
        $user  = Auth::user();
        $q     = trim($req->query('q', ''));
        $type  = $req->query('type', 'all');   // all | people | posts | tags
        $local = (bool) $req->query('local', false);
        $limit = 20;

        $accounts = [];
        $statuses = [];
        $hashtags = [];

        if ($q !== '') {
            if ($type === 'all' || $type === 'people') {
                $accounts = \Canticle\Handlers\Api\SearchHandler::searchAccounts($q, $limit, 0, $local);
            }
            if ($type === 'all' || $type === 'posts') {
                $statuses = \Canticle\Handlers\Api\SearchHandler::searchStatuses($q, $limit, 0, $local, $user);
            }
            if ($type === 'all' || $type === 'tags') {
                $hashtags = \Canticle\Handlers\Api\SearchHandler::searchHashtags($q, $limit);
            }
        }

        $res->html($this->render('web/search', [
            'user'      => $user,
            'title'     => $q ? "Search: $q" : 'Search',
            'tab'       => 'search',
            'unread'    => 0,
            'q'         => $q,
            'type'      => $type,
            'local'     => $local,
            'accounts'  => $accounts,
            'statuses'  => $statuses,
            'hashtags'  => $hashtags,
        ]));
    }

    public function rules(Request $req, Response $res): void
    {
        $user = Auth::user();
        $res->html($this->render('web/rules', [
            'user'   => $user,
            'title'  => 'Rules',
            'tab'    => '',
            'unread' => 0,
            'rules'  => db()->fetch("SELECT value FROM settings WHERE key_ = 'site_rules'")['value'] ?? '',
        ]));
    }

    public function privacy(Request $req, Response $res): void
    {
        $user = Auth::user();
        $res->html($this->render('web/privacy', [
            'user'    => $user,
            'title'   => 'Privacy Policy',
            'tab'     => '',
            'unread'  => 0,
            'privacy' => db()->fetch("SELECT value FROM settings WHERE key_ = 'site_privacy'")['value'] ?? '',
        ]));
    }

    private function render(string $template, array $data = []): string
    {
        extract($data);

        $file = CANTICLE_ROOT . '/templates/' . $template . '.php';
        if (!file_exists($file)) {
            return "<h1>Template not found: $template</h1>";
        }

        // Auth pages are complete HTML documents — no layout wrapper needed
        $fullPage = in_array($template, ['web/sign_in', 'web/sign_up', 'web/closed']);
        if ($fullPage) {
            ob_start();
            require $file;
            return ob_get_clean();
        }

        // Content templates: capture their output, then inject into the layout
        ob_start();
        require $file;
        $content = ob_get_clean();

        ob_start();
        require CANTICLE_ROOT . '/templates/web/layout.php';
        return ob_get_clean();
    }
}
