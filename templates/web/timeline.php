<?php
// $statuses = array of raw DB rows, $user = current user or null
require_once CANTICLE_ROOT . '/templates/web/_status_helpers.php';
?>

<div class="card">
<?php foreach ($statuses as $s): ?>
  <?= renderStatus($s, $user ?? null) ?>
<?php endforeach; ?>
<?php if (empty($statuses)): ?>
  <div style="padding:2rem;text-align:center;color:var(--muted)">Nothing here yet.</div>
<?php endif; ?>
</div>

