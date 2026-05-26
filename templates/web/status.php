<?php
// Thread / status permalink view.
// Rendered inside the main layout (sidebar + compose modal included automatically).
// Variables: $status (Mastodon array), $ancestors (array), $descendants (array), $user, $tab
require_once CANTICLE_ROOT . '/templates/web/_status_helpers.php';
?>

<div class="card">

  <?php /* ── Ancestors (replies this post is part of) ───────────────── */ ?>
  <?php foreach ($ancestors as $s): ?>
    <div class="thread-ancestor">
      <?= renderStatusMastodon($s, $user ?? null) ?>
    </div>
  <?php endforeach; ?>

  <?php /* ── Focal post ─────────────────────────────────────────────── */ ?>
  <div class="thread-focal">
    <?= renderStatusMastodon($status, $user ?? null) ?>
  </div>

  <?php /* ── Replies ────────────────────────────────────────────────── */ ?>
  <?php if (!empty($descendants)): ?>
    <div class="thread-replies-label">
      <?= count($descendants) ?> <?= count($descendants) === 1 ? 'reply' : 'replies' ?>
    </div>
    <?php foreach ($descendants as $s): ?>
      <div class="thread-reply">
        <?= renderStatusMastodon($s, $user ?? null) ?>
      </div>
    <?php endforeach; ?>
  <?php elseif ($user ?? null): ?>
    <div class="thread-no-replies">
      No replies yet. Be the first — click 💬 above to reply.
    </div>
  <?php endif; ?>

</div>
