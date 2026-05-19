<?php
// $status = Mastodon-shaped status array, $user = viewer or null
$siteName   = config('site_name', 'Canticle');
$account    = $status['account'] ?? [];
$authorName = htmlspecialchars($account['display_name'] ?: ($account['username'] ?? 'Unknown'));
$content    = $status['content'] ?? '';
$createdAt  = htmlspecialchars($status['created_at'] ?? '');
?>
<!DOCTYPE html>
<html lang="<?= config('site_lang', 'en') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
  <title><?= $authorName ?> — <?= htmlspecialchars($siteName) ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="icon" href="/assets/img/logo.png">
</head>
<body>
<div class="layout">
  <nav class="sidebar">
    <a href="/" class="site-logo">🎵 <?= htmlspecialchars($siteName) ?></a>
    <a href="/" class="nav-link">🏠 Home</a>
    <a href="/public" class="nav-link">🌍 Explore</a>
    <?php if ($user): ?>
      <a href="/notifications" class="nav-link">🔔 Notifications<?php if ($unread > 0): ?> <span class="badge"><?= $unread ?></span><?php endif; ?></a>
      <a href="/@<?= htmlspecialchars($user['username']) ?>" class="nav-link">👤 Profile</a>
      <a href="/auth/sign_out" class="nav-link">🚪 Sign out</a>
    <?php else: ?>
      <a href="/auth/sign_in" class="nav-link">🔑 Sign in</a>
    <?php endif; ?>
  </nav>
  <main class="main">
    <div class="card" style="padding:1.5rem">
      <div class="status-header" style="margin-bottom:1rem">
        <?php if ($account): ?>
          <a href="/<?= htmlspecialchars('@' . ($account['username'] ?? '')) ?>">
            <img src="<?= htmlspecialchars($account['avatar'] ?? '/assets/img/default_avatar.svg') ?>" class="avatar" alt="<?= $authorName ?>">
          </a>
          <div>
            <div class="account-name"><a href="/@<?= htmlspecialchars($account['username'] ?? '') ?>"><?= $authorName ?></a></div>
            <div class="account-acct">@<?= htmlspecialchars($account['acct'] ?? '') ?></div>
          </div>
        <?php endif; ?>
        <time class="status-time" datetime="<?= $createdAt ?>"><?= $createdAt ?></time>
      </div>
      <?php if (!empty($status['spoiler_text'])): ?>
        <div style="font-weight:600;margin-bottom:.5rem">CW: <?= htmlspecialchars($status['spoiler_text']) ?></div>
      <?php endif; ?>
      <div class="status-content" style="font-size:1.05rem;line-height:1.6"><?= $content ?></div>
      <?php if (!empty($status['media_attachments'])): ?>
        <div class="status-media" style="margin-top:1rem">
          <?php foreach ($status['media_attachments'] as $m): ?>
            <?php if (($m['type'] ?? '') === 'video'): ?>
              <video src="<?= htmlspecialchars($m['url'] ?? '') ?>" controls muted style="max-width:100%;border-radius:8px"></video>
            <?php else: ?>
              <img src="<?= htmlspecialchars($m['url'] ?? '') ?>" alt="<?= htmlspecialchars($m['description'] ?? '') ?>" style="max-width:100%;border-radius:8px">
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="status-actions" style="margin-top:1rem">
        <span>💬 <?= (int) ($status['replies_count'] ?? 0) ?></span>
        <span>🔄 <?= (int) ($status['reblogs_count'] ?? 0) ?></span>
        <span>⭐ <?= (int) ($status['favourites_count'] ?? 0) ?></span>
      </div>
    </div>
  </main>
  <aside class="aside">
    <div class="aside-card">
      <h3>About <?= htmlspecialchars($siteName) ?></h3>
      <p style="font-size:.9rem;color:var(--muted)"><?= htmlspecialchars(config('site_desc', '')) ?></p>
    </div>
  </aside>
</div>
<script src="/assets/js/app.js"></script>
</body>
</html>
