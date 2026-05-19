<?php
// Variables provided by AdminHandler::pruneForm():
//   $statusDays, $actorDays
//   $remoteStatusCount, $remoteActorCount, $mediaCount, $mediaBytes
//   $wouldPruneStatuses, $wouldPruneActors
//   $pruneLogs, $flash_success, $flash_error

function humanBytesP(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>

<h1>Content Retention</h1>

<?php if ($flash_success): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- ── Current storage stats ─────────────────────────────────────────── -->
<div class="stat-grid" style="grid-template-columns:repeat(4,minmax(130px,1fr))">
  <div class="stat-card">
    <div class="num"><?= number_format($remoteStatusCount) ?></div>
    <div class="lbl">Remote statuses</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= number_format($remoteActorCount) ?></div>
    <div class="lbl">Cached actor profiles</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= number_format($mediaCount) ?></div>
    <div class="lbl">Stored media files</div>
  </div>
  <div class="stat-card">
    <div class="num"><?= humanBytesP($mediaBytes) ?></div>
    <div class="lbl">Media disk usage</div>
  </div>
</div>

<!-- ── Retention settings ────────────────────────────────────────────── -->
<h2>Retention settings</h2>
<p style="color:var(--muted);font-size:.9rem">
  Content older than these limits is removed by the daily prune job
  (<code>php artisan.php prune</code>). Set to <strong>0</strong> to keep content forever.
  Local users' favourited posts, replies, and boosts are always protected.
</p>

<form method="POST" action="/admin/prune/settings">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(session_id() ? ($_SESSION['csrf_token'] ?? '') : '') ?>">
  <table class="admin-table" style="max-width:520px">
    <thead>
      <tr><th>Setting</th><th>Days</th><th>What it controls</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><label for="status-days"><strong>Remote statuses</strong></label></td>
        <td>
          <input id="status-days" type="number" name="remote_status_max_days"
                 value="<?= $statusDays ?>" min="0" max="3650" style="width:80px">
        </td>
        <td style="font-size:.85rem;color:var(--muted)">
          Posts from remote instances. Protected posts (favourited, replied to, boosted by local users) are never deleted.
        </td>
      </tr>
      <tr>
        <td><label for="actor-days"><strong>Cached actor profiles</strong></label></td>
        <td>
          <input id="actor-days" type="number" name="remote_actor_max_days"
                 value="<?= $actorDays ?>" min="0" max="3650" style="width:80px">
        </td>
        <td style="font-size:.85rem;color:var(--muted)">
          Remote user profiles with no recent posts and no local followers.
        </td>
      </tr>
    </tbody>
  </table>
  <button type="submit" class="btn-primary" style="margin-top:.75rem">Save settings</button>
</form>

<!-- ── Prune preview & manual trigger ───────────────────────────────── -->
<h2 style="margin-top:2rem">Run a prune now</h2>

<div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:1rem;margin-bottom:1rem;max-width:520px">
  <p style="margin:0 0 .5rem"><strong>What would be removed with current settings:</strong></p>
  <ul style="margin:0;padding-left:1.2rem;color:var(--muted);font-size:.9rem">
    <li>
      <?php if ($statusDays > 0): ?>
        <strong style="color:<?= $wouldPruneStatuses > 0 ? 'var(--danger)' : 'inherit' ?>">
          <?= number_format($wouldPruneStatuses) ?></strong>
        remote statuses older than <?= $statusDays ?> days
      <?php else: ?>
        Remote status pruning is <strong>disabled</strong> (0 days)
      <?php endif; ?>
    </li>
    <li>
      <?php if ($actorDays > 0): ?>
        <strong style="color:<?= $wouldPruneActors > 0 ? 'var(--accent)' : 'inherit' ?>">
          <?= number_format($wouldPruneActors) ?></strong>
        stale actor profiles older than <?= $actorDays ?> days
      <?php else: ?>
        Actor pruning is <strong>disabled</strong> (0 days)
      <?php endif; ?>
    </li>
  </ul>
</div>

<form method="POST" action="/admin/prune/run"
      onsubmit="return confirm('Run prune now? This permanently deletes old remote content.')">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
  <button type="submit" class="btn" style="background:var(--danger);color:#fff;border-color:var(--danger)">
    Run prune now
  </button>
  <span style="font-size:.85rem;color:var(--muted);margin-left:.75rem">
    This runs immediately in the web request — use the CLI for large cleanups.
  </span>
</form>

<p style="margin-top:1rem;font-size:.85rem;color:var(--muted)">
  <strong>Automate with cron</strong> — add to your server's crontab to run nightly at 3 AM:
</p>
<pre style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:.75rem;font-size:.82rem;overflow-x:auto">0 3 * * * php <?= htmlspecialchars(CANTICLE_ROOT) ?>/artisan.php prune >> <?= htmlspecialchars(CANTICLE_ROOT) ?>/storage/logs/prune.log 2>&1</pre>

<!-- ── Prune history ──────────────────────────────────────────────────── -->
<?php if ($pruneLogs): ?>
<h2 style="margin-top:2rem">Prune history</h2>
<table class="admin-table">
  <thead>
    <tr>
      <th>Date</th>
      <th>Statuses removed</th>
      <th>Media files</th>
      <th>Disk freed</th>
      <th>Actors removed</th>
      <th>Cutoff</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($pruneLogs as $log): ?>
    <tr>
      <td style="font-size:.85rem;color:var(--muted)"><?= htmlspecialchars(substr($log['ran_at'], 0, 16)) ?></td>
      <td><?= number_format($log['statuses_removed']) ?></td>
      <td><?= number_format($log['media_files_removed']) ?></td>
      <td><?= humanBytesP((int) $log['media_bytes_freed']) ?></td>
      <td><?= number_format($log['actors_removed']) ?></td>
      <td><?= (int) $log['cutoff_days'] ?> days</td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
