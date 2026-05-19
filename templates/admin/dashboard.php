<h1>Dashboard</h1>

<div class="stat-grid">
  <div class="stat-card">
    <div class="num"><?= number_format($stats['users']) ?></div>
    <div class="lbl">Users</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= number_format($stats['statuses']) ?></div>
    <div class="lbl">Posts</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= number_format($stats['instances']) ?></div>
    <div class="lbl">Known instances</div>
  </div>
  <div class="stat-card">
    <div class="num" style="color:var(--danger)"><?= number_format($stats['blocked']) ?></div>
    <div class="lbl">Blocked instances</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= number_format($stats['queue_pending']) ?></div>
    <div class="lbl">Queue jobs</div>
  </div>
  <div class="stat-card">
    <div class="num" style="color:<?= $stats['queue_failed'] > 0 ? 'var(--danger)' : 'var(--success)' ?>">
      <?= number_format($stats['queue_failed']) ?>
    </div>
    <div class="lbl">Failed jobs</div>
  </div>
</div>

<p style="color:var(--muted);font-size:.88rem;margin-top:.5rem">
  Canticle v<?= CANTICLE_VERSION ?> &nbsp;·&nbsp; PHP <?= PHP_VERSION ?> &nbsp;·&nbsp;
  <strong><?= htmlspecialchars(config('domain')) ?></strong>
</p>

<div style="margin-top:2rem;display:flex;gap:.65rem;flex-wrap:wrap">
  <a href="/admin/users" class="btn">👥 Manage users</a>
  <a href="/admin/federation" class="btn">🌐 Federation</a>
  <a href="/admin/relays" class="btn">📡 Relays</a>
  <a href="/admin/queue" class="btn">⚡ Queue<?php if ($stats['queue_failed'] > 0): ?> <span style="color:var(--danger)">(<?= $stats['queue_failed'] ?> failed)</span><?php endif; ?></a>
  <a href="/admin/upgrades" class="btn">🔄 Upgrades</a>
</div>
