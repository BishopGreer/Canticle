<?php
// Web layout — included by other templates via $this->render()
// Variables available: $user, $title, $unread, $tab
$siteName = config('site_name', 'Canticle');
$maxChars = (int) config('max_chars', 500);
$maxPollOptions = (int) config('max_poll_options', 4);
?>
<!DOCTYPE html>
<html lang="<?= config('site_lang', 'en') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
  <title><?= htmlspecialchars($title ?? $siteName) ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="icon" href="/assets/img/logo.png">
</head>
<body>

<div class="layout">
  <!-- Sidebar -->
  <nav class="sidebar">
    <a href="/" class="site-logo">🎵 <span><?= htmlspecialchars($siteName) ?></span></a>

    <form method="GET" action="/search" style="margin:.25rem 0 .5rem">
      <div style="display:flex;gap:.35rem">
        <input type="search" name="q" placeholder="Search…"
               value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
               style="flex:1;min-width:0;padding:.4rem .65rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text);font-size:.88rem">
        <button type="submit" style="padding:.4rem .65rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text);cursor:pointer;font-size:.88rem">🔍</button>
      </div>
    </form>

    <a href="/" class="nav-link <?= ($tab ?? '') === 'home' ? 'active' : '' ?>">🏠 <span>Home</span></a>
    <a href="/public" class="nav-link <?= ($tab ?? '') === 'public' ? 'active' : '' ?>">🌍 <span>Explore</span></a>
    <a href="/search" class="nav-link <?= ($tab ?? '') === 'search' ? 'active' : '' ?>">🔍 <span>Search</span></a>

    <?php if ($user): ?>
      <button type="button" class="btn-primary" data-compose-open style="width:100%;margin:.5rem 0;font-size:.95rem;justify-content:center">✏️ <span>New Post</span></button>
      <a href="/notifications" class="nav-link <?= ($tab ?? '') === 'notifications' ? 'active' : '' ?>">
        🔔 <span>Notifications</span>
        <?php if ($unread > 0): ?><span class="badge"><?= $unread ?></span><?php endif; ?>
      </a>
      <a href="/@<?= htmlspecialchars($user['username']) ?>" class="nav-link">👤 <span>Profile</span></a>
      <a href="/settings" class="nav-link">✏️ <span>Edit profile</span></a>
      <?php if (in_array($user['role'] ?? '', ['admin','moderator'])): ?>
        <a href="/admin" class="nav-link">⚙️ <span>Admin</span></a>
      <?php endif; ?>
      <a href="/auth/sign_out" class="nav-link">🚪 <span>Sign out</span></a>
    <?php else: ?>
      <a href="/auth/sign_in" class="nav-link">🔑 <span>Sign in</span></a>
      <?php if (config('registrations') !== 'closed'): ?>
        <a href="/auth/sign_up" class="nav-link">📝 <span>Sign up</span></a>
      <?php endif; ?>
    <?php endif; ?>
  </nav>

  <!-- Main content -->
  <main class="main">
    <?= $content ?? '' ?>
  </main>

  <!-- Aside (optional right panel) -->
  <aside class="aside">
    <div class="aside-card">
      <h3>About <?= htmlspecialchars($siteName) ?></h3>
      <p style="font-size:.9rem;color:var(--muted)"><?= htmlspecialchars(config('site_desc', '')) ?></p>
      <div style="margin-top:.75rem;display:flex;gap:.75rem;flex-wrap:wrap;font-size:.875rem">
        <a href="/rules" style="color:var(--muted);text-decoration:none" onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'">Rules</a>
        <a href="/privacy" style="color:var(--muted);text-decoration:none" onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'">Privacy</a>
      </div>
    </div>
  </aside>
</div>

<?php if ($user ?? null): ?>
<!-- ── Compose modal ─────────────────────────────────────────────────────── -->
<!-- Visibility controlled by the .open CSS class, not inline display style, -->
<!-- so it works across all browsers including those without inset: support.  -->
<div id="compose-modal" class="compose-modal" role="dialog" aria-modal="true" aria-label="New post">
  <div class="compose-modal-inner">

    <div class="compose-modal-header">
      <div>
        <strong style="font-size:.95rem">New post</strong>
        <div id="compose-reply-label" style="display:none;font-size:.82rem;color:var(--muted);margin-top:.15rem"></div>
      </div>
      <button type="button" class="compose-modal-close" data-compose-close aria-label="Close">&times;</button>
    </div>

    <div class="compose-modal-body" data-compose data-max-chars="<?= $maxChars ?>">
      <form id="compose-form">
        <input type="hidden" name="in_reply_to_id" id="compose-reply-to-id" value="">
        <div style="display:flex;gap:.75rem">
          <?php
          $sidebarAvatar = $user['avatar']
              ? (str_starts_with($user['avatar'], 'http') ? $user['avatar'] : BASE_URL . '/' . $user['avatar'])
              : BASE_URL . '/assets/img/default_avatar.svg';
          ?>
          <img src="<?= htmlspecialchars($sidebarAvatar) ?>" class="avatar" alt="" style="flex-shrink:0">
          <div style="flex:1;min-width:0">
            <textarea name="status" id="compose-textarea" placeholder="What's on your mind?"
                      style="width:500px;max-width:100%;height:50px;min-height:50px;padding:.75rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text);font-size:1rem;resize:vertical;font-family:inherit;line-height:1.5"
                      required></textarea>
            <div class="media-preview" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem"></div>
            <div class="poll-builder" style="display:none;margin-top:.75rem;border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem">
              <div class="poll-options-container" style="display:flex;flex-direction:column;gap:.4rem">
                <div class="poll-opt"><input type="text" name="poll[options][]" placeholder="Option 1" maxlength="50" style="width:100%;padding:.4rem .6rem;border:1px solid var(--border);border-radius:4px;background:var(--bg);color:var(--text)" disabled></div>
                <div class="poll-opt"><input type="text" name="poll[options][]" placeholder="Option 2" maxlength="50" style="width:100%;padding:.4rem .6rem;border:1px solid var(--border);border-radius:4px;background:var(--bg);color:var(--text)" disabled></div>
              </div>
              <button type="button" data-add-poll-option data-max="<?= $maxPollOptions ?>" style="margin-top:.5rem;font-size:.85rem">+ Add option</button>
              <label style="margin-top:.5rem;display:flex;gap:.5rem;align-items:center;font-size:.85rem">
                <input type="checkbox" name="poll[multiple]" value="1"> Allow multiple choices
              </label>
              <label style="margin-top:.5rem;font-size:.85rem;display:flex;align-items:center;gap:.5rem">
                Expires in:
                <select name="poll[expires_in]" style="border:1px solid var(--border);border-radius:4px;padding:.2rem .4rem;background:var(--bg);color:var(--text)">
                  <option value="300">5 minutes</option>
                  <option value="3600">1 hour</option>
                  <option value="86400" selected>1 day</option>
                  <option value="604800">7 days</option>
                </select>
              </label>
            </div>
            <div class="compose-actions" style="display:flex;align-items:center;gap:.6rem;margin-top:.75rem;flex-wrap:wrap">
              <label title="Attach image" style="cursor:pointer;font-size:1.1rem">
                📎 <input type="file" accept="image/*,video/*" data-media-upload style="display:none" multiple>
              </label>
              <button type="button" data-toggle-poll title="Add poll">📊</button>
              <select name="visibility" style="border:1px solid var(--border);border-radius:4px;padding:.3rem .5rem;font-size:.85rem;background:var(--bg);color:var(--text)">
                <option value="public">🌍 Public</option>
                <option value="unlisted">🔓 Unlisted</option>
                <option value="private">🔒 Followers</option>
              </select>
              <span class="compose-count" style="margin-left:auto;font-size:.85rem;color:var(--muted)"><?= $maxChars ?></span>
              <button type="submit" class="btn-primary" style="padding:.45rem 1.1rem">Post</button>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
