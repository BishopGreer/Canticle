<?php
$pendingCount = count(array_filter($migrations, fn($m) => !$m['applied']));
$allApplied   = $pendingCount === 0;
$cache        = \Canticle\Handlers\Admin\AdminHandler::readUpdateCache();
$cacheAge     = $cache ? (time() - (int)$cache['checked_at']) : null;
$behind       = (int) ($cache['behind'] ?? 0);
?>
<h1>Upgrades</h1>

<!-- ── Update check ────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.75rem">

  <?php if (!$gitInfo['available']): ?>
    <?php $reason = $gitInfo['reason'] ?? 'unknown'; ?>
    <?php if ($reason === 'no_repo' || $reason === 'no_git_no_repo'): ?>
      <div style="padding:.75rem 1rem;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);font-size:.9rem;max-width:640px">
        <strong>Git is installed but this directory is not a git repository.</strong><br>
        <span style="color:var(--muted)">
          You have been uploading files manually. To enable automatic updates,
          SSH into the server and run these commands once:
        </span>
        <pre style="margin:.6rem 0 0;font-size:.82rem;background:rgba(0,0,0,.2);padding:.6rem .8rem;border-radius:4px;overflow-x:auto"># Back up your config and storage first, then:
cd <?= htmlspecialchars(CANTICLE_ROOT) ?>

git init
git remote add origin https://github.com/BishopGreer/canticle.git
git fetch origin
git reset --hard origin/main</pre>
        <span style="color:var(--danger);font-size:.82rem">
          ⚠ <code>git reset --hard</code> will overwrite files — back up <code>config.php</code>
          and the <code>storage/</code> folder first.
        </span>
      </div>
    <?php else: ?>
      <span style="color:var(--muted);font-size:.9rem">
        Git is not installed on this server — automatic update checks are disabled.
      </span>
    <?php endif; ?>
  <?php elseif ($cache): ?>
    <?php if ($behind > 0): ?>
      <div style="display:flex;align-items:center;gap:.6rem;padding:.65rem 1rem;background:var(--accent-light,#eff6ff);border:1px solid var(--accent);border-radius:var(--radius-sm);font-size:.92rem">
        <span>🔄</span>
        <strong><?= $behind ?> update<?= $behind !== 1 ? 's' : '' ?> available</strong>
        <?php if ($cache['commit'] ?? ''): ?>
          — <code><?= htmlspecialchars($cache['commit']) ?></code>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div style="padding:.65rem 1rem;background:var(--surface-2);border-radius:var(--radius-sm);font-size:.9rem;color:var(--muted)">
        ✓ Up to date
      </div>
    <?php endif; ?>
    <span style="font-size:.82rem;color:var(--muted)">
      Last checked:
      <?php
        if ($cacheAge < 60)          echo 'just now';
        elseif ($cacheAge < 3600)    echo round($cacheAge / 60) . ' min ago';
        else                         echo round($cacheAge / 3600, 1) . ' hr ago';
      ?>
    </span>
  <?php else: ?>
    <span style="color:var(--muted);font-size:.9rem">Not checked yet.</span>
  <?php endif; ?>

  <?php if ($gitInfo['available']): ?>
  <form method="POST" action="/admin/upgrades/check-updates" style="margin-left:auto">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
    <button type="submit" class="btn btn-sm">🔍 Check for updates</button>
  </form>
  <?php endif; ?>

</div>

<p style="color:var(--muted);font-size:.88rem;margin-bottom:1.75rem">
  For PHP version, disk space, and cache controls see <a href="/admin/server-status">Server Status</a>.
</p>

<!-- ── Database Migrations ─────────────────────────────────── -->
<h2>Database migrations</h2>

<table class="admin-table" style="margin-bottom:1rem">
  <thead>
    <tr><th>File</th><th>Status</th><th>Applied</th></tr>
  </thead>
  <tbody>
  <?php foreach ($migrations as $m): ?>
    <tr>
      <td>
        <code style="font-size:.88rem"><?= htmlspecialchars($m['name']) ?></code>
      </td>
      <td>
        <?php if ($m['applied']): ?>
          <span class="badge-pill badge-success">✓ Applied</span>
        <?php else: ?>
          <span class="badge-pill badge-warning">⏳ Pending</span>
        <?php endif; ?>
      </td>
      <td style="color:var(--muted);font-size:.85rem">
        <?= $m['applied_at'] ? htmlspecialchars(substr($m['applied_at'], 0, 16)) : '—' ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($migrations)): ?>
    <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:1.5rem">No migration files found.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<form method="POST" action="/admin/upgrades/migrate">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
  <?php if ($allApplied): ?>
    <button type="submit" class="btn" disabled style="opacity:.5;cursor:not-allowed">
      ✓ All migrations applied
    </button>
  <?php else: ?>
    <button type="submit" class="btn-primary"
            onclick="return confirm('Run <?= $pendingCount ?> pending migration(s)? Make a database backup first if this is a production instance.')">
      ▶ Run <?= $pendingCount ?> pending migration<?= $pendingCount !== 1 ? 's' : '' ?>
    </button>
  <?php endif; ?>
</form>


<!-- ── Software Update ─────────────────────────────────────── -->
<h2 style="margin-top:2.5rem">Software update</h2>

<?php if ($gitInfo['available']): ?>

  <div class="admin-form" style="max-width:700px">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem">
      <div>
        <div style="font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem">Branch</div>
        <code style="font-size:.95rem"><?= htmlspecialchars($gitInfo['branch']) ?></code>
      </div>
      <div>
        <div style="font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem">Last commit</div>
        <code style="font-size:.85rem"><?= htmlspecialchars($gitInfo['commit']) ?></code>
        <div style="font-size:.8rem;color:var(--muted);margin-top:.1rem"><?= htmlspecialchars($gitInfo['date']) ?></div>
      </div>
      <?php if ($gitInfo['remote']): ?>
      <div style="grid-column:span 2">
        <div style="font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem">Remote</div>
        <code style="font-size:.8rem;word-break:break-all"><?= htmlspecialchars($gitInfo['remote']) ?></code>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($gitInfo['dirty']): ?>
      <div class="flash-error" style="margin-bottom:1rem">
        ⚠ Working tree has uncommitted local changes. A pull may fail or cause conflicts.
      </div>
    <?php endif; ?>

    <?php if ($gitInfo['behind'] > 0): ?>
      <div class="flash-success" style="margin-bottom:1rem">
        🔄 <?= $gitInfo['behind'] ?> new commit<?= $gitInfo['behind'] !== 1 ? 's' : '' ?> available on remote.
      </div>
    <?php elseif ($gitInfo['behind'] === 0): ?>
      <div style="margin-bottom:1rem;padding:.75rem 1rem;background:var(--surface-2);border-radius:var(--radius-sm);font-size:.9rem;color:var(--muted)">
        ✓ Already up to date with remote.
      </div>
    <?php endif; ?>

    <form method="POST" action="/admin/upgrades/pull"
          onsubmit="return confirm('Pull latest code from remote? Migrations will run automatically afterwards.\n\nBack up your database before upgrading.')">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
      <button type="submit" class="btn-primary"
              <?= ($gitInfo['behind'] === 0 && !$gitInfo['dirty']) ? 'style="opacity:.6"' : '' ?>>
        ⬇ Pull latest &amp; migrate
      </button>
      <span style="color:var(--muted);font-size:.85rem;margin-left:.75rem">
        Runs <code>git pull --ff-only</code> then applies any new migrations.
      </span>
    </form>
  </div>

<?php elseif (($gitInfo['reason'] ?? '') === 'no_repo'): ?>

  <div class="admin-form" style="max-width:680px">
    <p style="color:var(--muted);font-size:.92rem;margin-bottom:.75rem">
      Once you have initialised the git repository (see above), the pull button will appear here automatically.
      Until then, update manually:
    </p>
    <ol style="font-size:.92rem;line-height:2;padding-left:1.3rem;color:var(--text-2)">
      <li>Back up your database: <code>mysqldump -u user -p canticle &gt; backup.sql</code></li>
      <li>Download the latest release from GitHub and upload via SFTP</li>
      <li>Use the <strong>Run pending migrations</strong> button above</li>
    </ol>
  </div>

<?php else: ?>

  <div class="admin-form" style="max-width:680px">
    <p style="color:var(--muted);font-size:.92rem;margin-bottom:1rem">
      <code>git</code> is not installed on this server. To update Canticle manually:
    </p>
    <ol style="font-size:.92rem;line-height:2;padding-left:1.3rem;color:var(--text-2)">
      <li>Back up your database: <code>mysqldump -u user -p canticle &gt; backup.sql</code></li>
      <li>Download the latest release from GitHub and upload via SFTP</li>
      <li>Use the <strong>Run pending migrations</strong> button above</li>
    </ol>
  </div>

<?php endif; ?>
