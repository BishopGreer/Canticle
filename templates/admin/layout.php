<?php
// Admin layout — $content is injected by AdminHandler::render()
// $title, $admin are available from the calling handler.
$currentPath  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$updateCache  = \Canticle\Handlers\Admin\AdminHandler::readUpdateCache();
$updateBehind = (int) ($updateCache['behind'] ?? 0);
$updateAge    = $updateCache ? (time() - (int)$updateCache['checked_at']) : null;

function adminNavActive(string $path, string $current): string {
    return $current === $path || str_starts_with($current, $path . '/') ? ' active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title ?? 'Admin') ?> — Canticle Admin</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="admin-layout">

  <nav class="admin-sidebar">
    <a href="/admin" class="admin-logo">🎵 Admin</a>

    <a href="/admin"<?= adminNavActive('/admin', $currentPath) === ' active' && $currentPath === '/admin' ? ' class="active"' : '' ?>>
      📊 Dashboard
    </a>
    <a href="/admin/settings"<?= str_contains($currentPath, '/admin/settings') ? ' class="active"' : '' ?>>
      ⚙️ Settings
    </a>
    <a href="/admin/rules"<?= str_contains($currentPath, '/admin/rules') ? ' class="active"' : '' ?>>
      📋 Rules
    </a>
    <a href="/admin/privacy"<?= str_contains($currentPath, '/admin/privacy') ? ' class="active"' : '' ?>>
      🔏 Privacy
    </a>
    <a href="/admin/users"<?= str_contains($currentPath, '/admin/users') ? ' class="active"' : '' ?>>
      👥 Users
    </a>
    <a href="/admin/statuses"<?= str_contains($currentPath, '/admin/statuses') ? ' class="active"' : '' ?>>
      📝 Statuses
    </a>
    <a href="/admin/federation"<?= str_contains($currentPath, '/admin/federation') ? ' class="active"' : '' ?>>
      🌐 Federation
    </a>
    <a href="/admin/relays"<?= str_contains($currentPath, '/admin/relays') ? ' class="active"' : '' ?>>
      📡 Relays
    </a>
    <a href="/admin/queue"<?= str_contains($currentPath, '/admin/queue') ? ' class="active"' : '' ?>>
      ⚡ Queue
    </a>
    <a href="/admin/prune"<?= str_contains($currentPath, '/admin/prune') ? ' class="active"' : '' ?>>
      🧹 Retention
    </a>
    <a href="/admin/upgrades"<?= str_contains($currentPath, '/admin/upgrades') ? ' class="active"' : '' ?>>
      🔄 Upgrades<?php if ($updateBehind > 0): ?>
        <span style="margin-left:auto;background:var(--accent);color:#fff;font-size:.7rem;font-weight:700;padding:.1rem .45rem;border-radius:999px;line-height:1.4"><?= $updateBehind ?></span>
      <?php endif; ?>
    </a>
    <a href="/admin/server-status"<?= str_contains($currentPath, '/admin/server-status') ? ' class="active"' : '' ?>>
      🖥 Server Status
    </a>

    <hr>
    <a href="/" class="back-link">← Back to site</a>
  </nav>

  <main class="admin-main">

    <?php if ($updateBehind > 0 && !str_contains($currentPath, '/admin/upgrades')): ?>
    <div style="display:flex;align-items:center;gap:.85rem;background:var(--accent-light,#eff6ff);border:1px solid var(--accent);border-radius:var(--radius-sm);padding:.75rem 1rem;margin-bottom:1.25rem;font-size:.92rem">
      <span style="font-size:1.2rem">🔄</span>
      <span>
        <strong><?= $updateBehind ?> update<?= $updateBehind !== 1 ? 's' : '' ?> available</strong>
        <?php if ($updateCache['commit'] ?? ''): ?>
          — latest: <code><?= htmlspecialchars($updateCache['commit']) ?></code>
        <?php endif; ?>
      </span>
      <a href="/admin/upgrades" class="btn btn-sm" style="margin-left:auto;white-space:nowrap">View &amp; Update →</a>
    </div>
    <?php endif; ?>

    <?php if (!empty($flash_success)): ?>
      <div class="flash-success" style="margin-bottom:1.25rem"><?= htmlspecialchars($flash_success) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash_error)): ?>
      <div class="flash-error" style="margin-bottom:1.25rem"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash_log)): ?>
      <div class="upgrade-log" style="margin-bottom:1.5rem">
        <strong style="display:block;margin-bottom:.4rem;font-size:.88rem;color:var(--muted)">Output</strong>
        <pre style="margin:0;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;font-size:.82rem;line-height:1.6;overflow-x:auto;white-space:pre-wrap;color:var(--text)"><?= htmlspecialchars($flash_log) ?></pre>
      </div>
    <?php endif; ?>

    <?= $content ?? '' ?>
  </main>

</div>
</body>
</html>
