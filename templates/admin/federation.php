<?php
$blockedInstances = array_values(array_filter($instances, fn($i) => in_array($i['status'], ['blocked', 'silenced'])));
?>
<h1>Federation</h1>

<?php if (!empty($flash_success)): ?>
  <div style="margin-bottom:1rem;padding:.75rem 1rem;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;color:#155724">
    <?= htmlspecialchars($flash_success) ?>
  </div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
  <div style="margin-bottom:1rem;padding:.75rem 1rem;background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;color:#721c24">
    <?= htmlspecialchars($flash_error) ?>
  </div>
<?php endif; ?>

<div class="admin-form" style="max-width:720px">
  <h2 style="margin-top:0;margin-bottom:.75rem">Block / Defederate a domain</h2>
  <p style="color:var(--muted);font-size:.88rem;margin-bottom:1rem">Blocked instances cannot send activities to yours and are hidden from all timelines.</p>
  <form method="POST" action="/admin/federation/block">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem">
      <input name="domain" placeholder="example.social" required style="flex:1;min-width:160px">
      <button type="submit" class="btn btn-danger">Block</button>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <input name="public_reason"  placeholder="Public reason (shown in the Mastodon API)" style="flex:1;min-width:200px">
      <input name="private_reason" placeholder="Private note (admins only)" style="flex:1;min-width:200px">
    </div>
  </form>
</div>

<div class="admin-form" style="max-width:720px">
  <h2 style="margin-top:0;margin-bottom:.75rem">Silence a domain</h2>
  <p style="color:var(--muted);font-size:.88rem;margin-bottom:1rem">Silenced instances are hidden from public timelines but federation continues.</p>
  <form method="POST" action="/admin/federation/silence">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem">
      <input name="domain" placeholder="example.social" required style="flex:1;min-width:160px">
      <button type="submit" class="btn">Silence</button>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <input name="public_reason"  placeholder="Public reason (optional)" style="flex:1;min-width:200px">
      <input name="private_reason" placeholder="Private note (optional)" style="flex:1;min-width:200px">
    </div>
  </form>
</div>

<div class="admin-form" style="max-width:720px">
  <h2 style="margin-top:0;margin-bottom:.75rem">Import block list</h2>
  <p style="color:var(--muted);font-size:.88rem;margin-bottom:1rem">
    Upload a CSV exported from Mastodon or another Canticle instance.<br>
    Expected columns: <code>#domain, #severity, #public_comment, #private_comment</code>
  </p>
  <form method="POST" action="/admin/federation/import" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <input type="file" name="blocklist" accept=".csv,text/csv" required style="flex:2">
      <label style="display:flex;align-items:center;gap:.3rem;font-size:.88rem;white-space:nowrap">
        <input type="checkbox" name="overwrite" value="1" checked>
        Overwrite existing entries
      </label>
      <button type="submit" class="btn btn-primary">Import</button>
    </div>
  </form>
</div>

<!-- ── Blocked / Silenced ──────────────────────────────────────────────────── -->
<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.5rem;margin-top:2rem">
  <h2 style="margin:0">
    Blocked &amp; silenced
    <span style="color:var(--muted);font-weight:400;font-size:.9rem">(<?= count($blockedInstances) ?>)</span>
  </h2>
  <a href="/admin/federation/export" class="btn btn-sm" title="Download CSV of all blocked and silenced instances">
    ↓ Export block list
  </a>
</div>

<table class="admin-table">
  <thead>
    <tr>
      <th>Domain</th>
      <th>Status</th>
      <th>Public reason</th>
      <th>Private note</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($blockedInstances as $inst): ?>
    <tr>
      <td><?= htmlspecialchars($inst['domain']) ?></td>
      <td>
        <span class="badge-pill <?= $inst['status'] === 'blocked' ? 'badge-danger' : 'badge-warning' ?>">
          <?= htmlspecialchars($inst['status']) ?>
        </span>
      </td>
      <td style="font-size:.85rem;max-width:220px">
        <?php $pub = htmlspecialchars($inst['public_reason'] ?? $inst['block_reason'] ?? ''); ?>
        <?= $pub !== '' ? $pub : '<span style="color:var(--muted)">—</span>' ?>
      </td>
      <td style="font-size:.85rem;max-width:220px">
        <?php $priv = htmlspecialchars($inst['private_reason'] ?? ''); ?>
        <?= $priv !== '' ? $priv : '<span style="color:var(--muted)">—</span>' ?>
      </td>
      <td>
        <form method="POST" action="/admin/federation/unblock" style="display:inline">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
          <input type="hidden" name="domain" value="<?= htmlspecialchars($inst['domain']) ?>">
          <button type="submit" class="btn btn-sm">Remove</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($blockedInstances)): ?>
    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:2rem">No blocked or silenced instances.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

