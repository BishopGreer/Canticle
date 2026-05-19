<h1>Queue</h1>

<div class="stat-grid" style="grid-template-columns:repeat(2,minmax(140px,200px))">
  <div class="stat-card">
    <div class="num"><?= count($jobs) ?></div>
    <div class="lbl">Pending jobs</div>
  </div>
  <div class="stat-card">
    <div class="num" style="color:<?= count($failed) > 0 ? 'var(--danger)' : 'var(--success)' ?>"><?= count($failed) ?></div>
    <div class="lbl">Failed jobs</div>
  </div>
</div>

<?php if (!empty($failed)): ?>
<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.5rem">
  <h2 style="margin:0">Failed jobs</h2>
  <form method="POST" action="/admin/queue/clear-failed"
        onsubmit="return confirm('Delete all <?= count($failed) ?> failed job(s)? This cannot be undone.')">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
    <button type="submit" class="btn btn-sm btn-danger">🗑 Clear all failed</button>
  </form>
</div>
<table class="admin-table">
  <thead>
    <tr><th>ID</th><th>Type</th><th>Destination</th><th>Error</th><th>Failed at</th><th>Actions</th></tr>
  </thead>
  <tbody>
  <?php foreach ($failed as $job): ?>
    <?php
      $payload = json_decode($job['payload'] ?? '{}', true);
      $jobType = $payload['job'] ?? '—';
      $dest    = parse_url($payload['data']['inbox_url'] ?? '', PHP_URL_HOST) ?: '—';
    ?>
    <tr>
      <td style="color:var(--muted);font-size:.85rem"><?= $job['id'] ?></td>
      <td style="font-family:monospace;font-size:.88rem"><?= htmlspecialchars($jobType) ?></td>
      <td style="font-size:.85rem;color:var(--muted)"><?= htmlspecialchars($dest) ?></td>
      <td style="font-size:.82rem;color:var(--danger);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
          title="<?= htmlspecialchars($job['exception'] ?? '') ?>">
        <?= htmlspecialchars(substr($job['exception'] ?? '—', 0, 80)) ?>
      </td>
      <td style="color:var(--muted);font-size:.82rem"><?= htmlspecialchars(substr($job['failed_at'] ?? '', 0, 16)) ?></td>
      <td style="display:flex;gap:.35rem;flex-wrap:wrap">
        <form method="POST" action="/admin/queue/retry/<?= $job['id'] ?>">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
          <button type="submit" class="btn btn-sm">↩ Retry</button>
        </form>
        <form method="POST" action="/admin/queue/delete/<?= $job['id'] ?>"
              onsubmit="return confirm('Delete this failed job?')">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
          <button type="submit" class="btn btn-sm btn-danger">🗑</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2>Pending jobs <span style="color:var(--muted);font-weight:400;font-size:.9rem">(most recent 50)</span></h2>
<?php if (empty($jobs)): ?>
  <div style="background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-sm);padding:2rem;text-align:center;color:var(--muted)">
    Queue is empty — all caught up! ✓
  </div>
<?php else: ?>
<table class="admin-table">
  <thead>
    <tr><th>ID</th><th>Type</th><th>Destination</th><th>State</th><th>Attempts</th><th>Available</th></tr>
  </thead>
  <tbody>
  <?php foreach ($jobs as $job): ?>
    <?php
      $payload  = json_decode($job['payload'] ?? '{}', true);
      $jobType  = $payload['job'] ?? '—';
      $dest     = parse_url($payload['data']['inbox_url'] ?? '', PHP_URL_HOST) ?: '—';
      $reserved = !empty($job['reserved_at']);
      $waiting  = !$reserved && ($job['available_at'] ?? '') > date('Y-m-d H:i:s');
    ?>
    <tr>
      <td style="color:var(--muted);font-size:.85rem"><?= $job['id'] ?></td>
      <td style="font-family:monospace;font-size:.88rem"><?= htmlspecialchars($jobType) ?></td>
      <td style="font-size:.85rem;color:var(--muted)"><?= htmlspecialchars($dest) ?></td>
      <td>
        <?php if ($reserved): ?>
          <span class="badge-pill badge-warning">⚙ Processing</span>
        <?php elseif ($waiting): ?>
          <span class="badge-pill" style="background:var(--surface-2);color:var(--muted)">⏲ Retry wait</span>
        <?php else: ?>
          <span class="badge-pill badge-success">⏳ Queued</span>
        <?php endif; ?>
      </td>
      <td><?= (int)($job['attempts'] ?? 0) ?></td>
      <td style="color:var(--muted);font-size:.82rem"><?= htmlspecialchars(substr($job['available_at'] ?? '', 0, 16)) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<div style="margin-top:1.5rem;padding:1rem;background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-sm);font-size:.88rem;color:var(--muted)">
  <strong style="color:var(--text)">Worker command:</strong><br>
  <code style="font-size:.85rem">php <?= CANTICLE_ROOT ?>/artisan.php worker</code><br><br>
  Or add a cron to run every minute:<br>
  <code style="font-size:.85rem">* * * * * php <?= CANTICLE_ROOT ?>/artisan.php worker --once >> <?= CANTICLE_ROOT ?>/storage/logs/worker.log 2>&amp;1</code>
</div>
