<h1>Users</h1>

<form method="GET" style="display:flex;gap:.5rem;margin-bottom:1.25rem;max-width:480px">
  <input name="q" value="<?= htmlspecialchars($q ?? '') ?>" placeholder="Search by username or email…"
    style="flex:1;padding:.55rem .85rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:.95rem;background:var(--surface);color:var(--text)">
  <button type="submit" class="btn">Search</button>
</form>

<table class="admin-table">
  <thead>
    <tr>
      <th>User</th><th>Email</th><th>Role</th><th>Status</th><th>Posts</th><th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td>
        <div style="display:flex;align-items:center;gap:.5rem">
          <?php
          $rowAvatar = $u['avatar']
              ? (str_starts_with($u['avatar'], 'http') ? $u['avatar'] : BASE_URL . '/' . $u['avatar'])
              : BASE_URL . '/assets/img/default_avatar.svg';
          ?>
          <img src="<?= htmlspecialchars($rowAvatar) ?>"
               style="width:28px;height:28px;border-radius:50%;object-fit:cover" alt="">
          <a href="/@<?= htmlspecialchars($u['username']) ?>"><?= htmlspecialchars($u['username']) ?></a>
        </div>
      </td>
      <td style="color:var(--muted);font-size:.88rem"><?= htmlspecialchars($u['email']) ?></td>
      <td>
        <span class="badge-pill <?= $u['role'] === 'admin' ? 'badge-danger' : ($u['role'] === 'moderator' ? 'badge-warning' : 'badge-muted') ?>">
          <?= htmlspecialchars($u['role']) ?>
        </span>
      </td>
      <td>
        <?php if ($u['suspended']): ?>
          <span class="badge-pill badge-danger">Suspended</span>
        <?php else: ?>
          <span class="badge-pill badge-success">Active</span>
        <?php endif; ?>
      </td>
      <td><?= number_format($u['statuses_count']) ?></td>
      <td>
        <div class="actions">
          <?php if (!$u['suspended']): ?>
            <form method="POST" action="/admin/users/<?= $u['id'] ?>/suspend">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
              <button type="submit" class="btn btn-sm btn-danger">Suspend</button>
            </form>
          <?php else: ?>
            <form method="POST" action="/admin/users/<?= $u['id'] ?>/unsuspend">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
              <button type="submit" class="btn btn-sm">Restore</button>
            </form>
          <?php endif; ?>
          <form method="POST" action="/admin/users/<?= $u['id'] ?>/promote" style="display:flex;gap:.3rem;align-items:center">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
            <select name="role" style="font-size:.82rem;padding:.25rem .45rem;border:1px solid var(--border);border-radius:var(--radius-xs);background:var(--surface);color:var(--text)">
              <option value="user"      <?= $u['role']==='user'      ? 'selected':'' ?>>User</option>
              <option value="moderator" <?= $u['role']==='moderator' ? 'selected':'' ?>>Moderator</option>
              <option value="admin"     <?= $u['role']==='admin'     ? 'selected':'' ?>>Admin</option>
            </select>
            <button type="submit" class="btn btn-sm">Set</button>
          </form>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($users)): ?>
    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:2rem">No users found.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
