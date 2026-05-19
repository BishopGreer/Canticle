<?php
$pendingCount = count(array_filter($migrations, fn($m) => !$m['applied']));
$allApplied   = $pendingCount === 0;
$hb = function(int $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return round($bytes, 1) . ' ' . $units[$i];
};
?>
<h1>Upgrades</h1>

<!-- ── System Info ──────────────────────────────────────────── -->
<h2>System status</h2>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem">

  <div class="stat-card">
    <div class="lbl" style="margin-bottom:.3rem">Canticle</div>
    <div style="font-size:1.3rem;font-weight:700;color:var(--accent)">v<?= htmlspecialchars($sysInfo['canticle_version']) ?></div>
  </div>

  <div class="stat-card">
    <div class="lbl" style="margin-bottom:.3rem">PHP</div>
    <div style="font-size:1.1rem;font-weight:600"><?= htmlspecialchars($sysInfo['php_version']) ?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:.2rem">
      Memory: <?= htmlspecialchars($sysInfo['memory_limit']) ?> &nbsp;|&nbsp;
      Exec: <?= htmlspecialchars($sysInfo['max_exec']) ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="lbl" style="margin-bottom:.3rem">MariaDB / MySQL</div>
    <div style="font-size:1rem;font-weight:600"><?= htmlspecialchars($sysInfo['db_version']) ?></div>
  </div>

  <div class="stat-card">
    <div class="lbl" style="margin-bottom:.3rem">Disk space</div>
    <div style="font-size:1rem;font-weight:600"><?= htmlspecialchars($sysInfo['disk_free']) ?> free</div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:.2rem">of <?= htmlspecialchars($sysInfo['disk_total']) ?></div>
  </div>

</div>

<!-- Writable directories -->
<div class="admin-form" style="padding:1rem 1.25rem;margin-bottom:1.5rem">
  <div style="display:flex;flex-wrap:wrap;gap:1.5rem">
    <?php foreach ($sysInfo['dirs'] as $dir => $ok): ?>
      <div style="display:flex;align-items:center;gap:.4rem;font-size:.9rem">
        <span style="color:<?= $ok ? 'var(--success)' : 'var(--danger)' ?>;font-size:1rem"><?= $ok ? '✓' : '✗' ?></span>
        <code><?= htmlspecialchars($dir) ?>/</code>
        <span style="color:var(--muted)"><?= $ok ? 'writable' : 'not writable' ?></span>
      </div>
    <?php endforeach; ?>
    <?php foreach ($sysInfo['extensions'] as $ext => $ok): ?>
      <div style="display:flex;align-items:center;gap:.4rem;font-size:.9rem">
        <span style="color:<?= $ok ? 'var(--success)' : 'var(--danger)' ?>;font-size:1rem"><?= $ok ? '✓' : '✗' ?></span>
        <code><?= htmlspecialchars($ext) ?></code>
        <span style="color:var(--muted)"><?= $ok ? 'loaded' : 'missing' ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>


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

<?php else: ?>

  <div class="admin-form" style="max-width:680px">
    <p style="color:var(--muted);font-size:.92rem;margin-bottom:1rem">
      <code>git</code> is not available on this server, so automatic code updates are not possible from here.
      To update Canticle manually:
    </p>
    <ol style="font-size:.92rem;line-height:2;padding-left:1.3rem;color:var(--text-2)">
      <li>SSH into the server and navigate to <code><?= htmlspecialchars(CANTICLE_ROOT) ?></code></li>
      <li>Back up your database: <code>mysqldump -u user -p canticle &gt; backup.sql</code></li>
      <li>Pull the latest code: <code>git pull</code></li>
      <li>Run new migrations: <code>php artisan.php migrate</code></li>
    </ol>
    <p style="font-size:.88rem;color:var(--muted);margin-top:.75rem">
      Or use the <strong>Run pending migrations</strong> button above after uploading updated files via SFTP.
    </p>
  </div>

<?php endif; ?>


<!-- ── PHP OPcache ─────────────────────────────────────────── -->
<?php if (function_exists('opcache_get_status') && ($oc = @opcache_get_status())): ?>
<h2 style="margin-top:2.5rem">OPcache</h2>
<div class="admin-form" style="max-width:560px;padding:1rem 1.25rem">
  <?php
    $mem   = $oc['memory_usage'] ?? [];
    $used  = $mem['used_memory'] ?? 0;
    $free  = $mem['free_memory'] ?? 0;
    $total = $used + $free;
    $pct   = $total > 0 ? round($used / $total * 100) : 0;
    $scripts = $oc['opcache_statistics']['num_cached_scripts'] ?? '—';
    $hits    = $oc['opcache_statistics']['hits'] ?? '—';
  ?>
  <div style="display:flex;flex-wrap:wrap;gap:1.5rem;font-size:.9rem">
    <div>
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Status</div>
      <span class="badge-pill <?= $oc['opcache_enabled'] ? 'badge-success' : 'badge-danger' ?>">
        <?= $oc['opcache_enabled'] ? 'Enabled' : 'Disabled' ?>
      </span>
    </div>
    <div>
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Memory used</div>
      <strong><?= $pct ?>%</strong> (<?= $hb($used) ?> / <?= $hb($total) ?>)
    </div>
    <div>
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Cached scripts</div>
      <strong><?= number_format((int)$scripts) ?></strong>
    </div>
    <div>
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:.2rem">Cache hits</div>
      <strong><?= number_format((int)$hits) ?></strong>
    </div>
  </div>

  <?php if ($oc['opcache_enabled']): ?>
  <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)">
    <form method="POST" action="/admin/upgrades/opcache-flush">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
      <button type="submit" class="btn btn-sm">♻ Flush OPcache</button>
      <span style="color:var(--muted);font-size:.82rem;margin-left:.5rem">
        Clears cached PHP bytecode so updated files take effect immediately.
      </span>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
