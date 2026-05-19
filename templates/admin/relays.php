<h1>Relays</h1>

<div class="admin-form" style="max-width:680px">
  <h2 style="margin-top:0;margin-bottom:.6rem">Subscribe to a relay</h2>
  <p style="color:var(--muted);font-size:.88rem;margin-bottom:1rem">
    ActivityPub relays forward public posts from across the fediverse to your instance, making timelines
    more lively even on a small server. Enter the base URL of the relay (e.g. <code>https://relay.fedi.buzz</code>).
    Canticle will auto-detect the relay actor and send a signed Follow request.
    The relay shows as <strong>Pending</strong> until its server sends back an Accept.
  </p>
  <form method="POST" action="/admin/relays/add" class="inline-form">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
    <input type="url" name="url" placeholder="https://relay.example.com" required style="flex:1">
    <button type="submit" class="btn-primary">Subscribe</button>
  </form>
</div>

<h2>Active relays <span style="color:var(--muted);font-weight:400;font-size:.9rem">(<?= count($relays) ?>)</span></h2>

<?php if (empty($relays)): ?>
  <div style="background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-sm);padding:2rem;text-align:center;color:var(--muted)">
    No relays configured yet. Add one above to start receiving federated content.
  </div>
<?php else: ?>
<table class="admin-table">
  <thead>
    <tr><th>Relay URL</th><th>Actor</th><th>Status</th><th>Added</th><th>Actions</th></tr>
  </thead>
  <tbody>
  <?php foreach ($relays as $relay): ?>
    <tr>
      <td>
        <a href="<?= htmlspecialchars($relay['url']) ?>" target="_blank" rel="noopener noreferrer" style="font-family:monospace;font-size:.88rem">
          <?= htmlspecialchars($relay['url']) ?>
        </a>
      </td>
      <td style="color:var(--muted);font-size:.82rem;font-family:monospace"><?= htmlspecialchars($relay['actor_url'] ?? '—') ?></td>
      <td>
        <?php
          $cls  = match($relay['status']) {
            'active'   => 'badge-success',
            'rejected' => 'badge-danger',
            default    => 'badge-warning',
          };
          $lbl  = match($relay['status']) {
            'active'   => '✓ Active',
            'rejected' => '✗ Rejected',
            default    => '⏳ Pending',
          };
        ?>
        <span class="badge-pill <?= $cls ?>"><?= $lbl ?></span>
      </td>
      <td style="color:var(--muted);font-size:.85rem"><?= htmlspecialchars(substr($relay['created_at'] ?? '', 0, 10)) ?></td>
      <td style="display:flex;gap:.4rem;flex-wrap:wrap">
        <form method="POST" action="/admin/relays/<?= $relay['id'] ?>/retry">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
          <button type="submit" class="btn btn-sm">↩ <?= $relay['status'] === 'active' ? 'Resubscribe' : 'Resend Follow' ?></button>
        </form>
        <form method="POST" action="/admin/relays/<?= $relay['id'] ?>/remove"
              onsubmit="return confirm('Remove this relay? An Undo Follow will be sent.')">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
          <button type="submit" class="btn btn-sm" style="color:var(--danger)">Remove</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2 style="margin-top:2rem">Known instances
  <span style="color:var(--muted);font-weight:400;font-size:.9rem">(<?= count($instances) ?>)</span>
</h2>

<?php if (empty($instances)): ?>
  <div style="background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-sm);padding:2rem;text-align:center;color:var(--muted)">
    No instances discovered yet. They appear here as relay content arrives.
  </div>
<?php else: ?>
<table class="admin-table">
  <thead>
    <tr><th>Domain</th><th>Software</th><th>Accounts</th><th>Status</th><th>Last seen</th><th>Actions</th></tr>
  </thead>
  <tbody>
  <?php foreach ($instances as $inst): ?>
    <?php
      $statusCls = match($inst['status']) {
        'blocked'  => 'badge-danger',
        'silenced' => 'badge-warning',
        default    => 'badge-success',
      };
      $statusLbl = match($inst['status']) {
        'blocked'  => '✗ Blocked',
        'silenced' => '⚠ Silenced',
        default    => '✓ Allowed',
      };
      $software = trim(($inst['software'] ?? '') . ' ' . ($inst['version'] ?? ''));
    ?>
    <tr>
      <td style="font-family:monospace;font-size:.88rem">
        <a href="https://<?= htmlspecialchars($inst['domain']) ?>" target="_blank" rel="noopener noreferrer">
          <?= htmlspecialchars($inst['domain']) ?>
        </a>
      </td>
      <td style="font-size:.85rem;color:var(--muted)"><?= htmlspecialchars($software ?: '—') ?></td>
      <td style="font-size:.85rem;text-align:center"><?= (int) $inst['actor_count'] ?></td>
      <td><span class="badge-pill <?= $statusCls ?>"><?= $statusLbl ?></span></td>
      <td style="font-size:.82rem;color:var(--muted)"><?= htmlspecialchars(substr($inst['last_seen'] ?? '', 0, 10)) ?></td>
      <td style="display:flex;gap:.4rem;flex-wrap:wrap">
        <?php if ($inst['status'] === 'blocked'): ?>
          <form method="POST" action="/admin/federation/unblock">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
            <input type="hidden" name="domain" value="<?= htmlspecialchars($inst['domain']) ?>">
            <button type="submit" class="btn btn-sm">Unblock</button>
          </form>
        <?php elseif ($inst['status'] === 'silenced'): ?>
          <form method="POST" action="/admin/federation/unblock">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
            <input type="hidden" name="domain" value="<?= htmlspecialchars($inst['domain']) ?>">
            <button type="submit" class="btn btn-sm">Unsilence</button>
          </form>
          <form method="POST" action="/admin/federation/block"
                onsubmit="return confirm('Block <?= htmlspecialchars($inst['domain']) ?>?')">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
            <input type="hidden" name="domain" value="<?= htmlspecialchars($inst['domain']) ?>">
            <button type="submit" class="btn btn-sm" style="color:var(--danger)">Block</button>
          </form>
        <?php else: ?>
          <form method="POST" action="/admin/federation/silence">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
            <input type="hidden" name="domain" value="<?= htmlspecialchars($inst['domain']) ?>">
            <button type="submit" class="btn btn-sm">Silence</button>
          </form>
          <form method="POST" action="/admin/federation/block"
                onsubmit="return confirm('Block <?= htmlspecialchars($inst['domain']) ?>?')">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
            <input type="hidden" name="domain" value="<?= htmlspecialchars($inst['domain']) ?>">
            <button type="submit" class="btn btn-sm" style="color:var(--danger)">Block</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<div class="aside-card" style="max-width:680px;margin-top:1.5rem">
  <h3>Popular public relays</h3>
  <ul style="margin:.5rem 0;padding-left:1.2rem;color:var(--text-2);font-size:.9rem;line-height:2">
    <li><code>https://relay.fedi.buzz</code></li>
    <li><code>https://ap.relay.chat</code></li>
    <li><code>https://relay.intahnet.co.uk</code></li>
    <li><code>https://social.tulsa.ok.us/relay</code></li>
  </ul>
  <p style="font-size:.82rem;color:var(--muted);margin-top:.5rem">
    A relay stays <strong>Pending</strong> until its server sends back an Accept activity to your inbox.
    This can take a few seconds to a few minutes. If it stays pending, check the
    <a href="/admin/queue">Queue page</a> for failed delivery jobs, or use <strong>Resend Follow</strong>.
  </p>
</div>
