<?php
$icons = [
    'follow'          => '👤',
    'follow_request'  => '👤',
    'mention'         => '💬',
    'reblog'          => '🔄',
    'favourite'       => '⭐',
    'poll'            => '📊',
    'status'          => '📝',
    'update'          => '✏️',
    'admin.sign_up'   => '🆕',
];
?>
<div class="card">
<?php foreach ($notifications as $n): ?>
  <div class="notification">
    <span class="notification-icon"><?= $icons[$n['type']] ?? '🔔' ?></span>
    <div class="notification-body">
      <?php $acct = $n['account'] ?? null; ?>
      <?php if ($acct): ?>
        <a href="<?= BASE_URL ?>/@<?= htmlspecialchars($acct['acct'] ?? $acct['username']) ?>">
          <strong><?= htmlspecialchars($acct['display_name'] ?: $acct['username']) ?></strong>
        </a>
      <?php endif; ?>
      <?php match ($n['type']) {
          'follow'         => print ' followed you.',
          'follow_request' => print ' requested to follow you.',
          'mention'        => print ' mentioned you.',
          'reblog'         => print ' boosted your post.',
          'favourite'      => print ' liked your post.',
          'poll'           => print ' — a poll you participated in has ended.',
          default          => print '.',
      }; ?>
      <?php if (isset($n['status']) && $n['status']): ?>
        <div style="margin-top:.35rem;color:var(--muted);font-size:.9rem">
          <?= strip_tags($n['status']['content'] ?? '') ?>
        </div>
      <?php endif; ?>
      <time style="color:var(--muted);font-size:.8rem" datetime="<?= htmlspecialchars($n['created_at']) ?>"><?= htmlspecialchars($n['created_at']) ?></time>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($notifications)): ?>
  <div style="padding:2rem;text-align:center;color:var(--muted)">No notifications.</div>
<?php endif; ?>
</div>

