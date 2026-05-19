<h1>Statuses</h1>

<table class="admin-table">
  <thead>
    <tr><th>ID</th><th>Author</th><th>Content preview</th><th>Visibility</th><th>Posted</th><th>Actions</th></tr>
  </thead>
  <tbody>
  <?php foreach ($statuses as $s): ?>
    <tr>
      <td style="color:var(--muted);font-size:.85rem"><?= $s['id'] ?></td>
      <td>
        <?php if ($s['username']): ?>
          <a href="/@<?= htmlspecialchars($s['username']) ?>">@<?= htmlspecialchars($s['username']) ?></a>
        <?php else: ?>
          <span style="color:var(--muted)">remote</span>
        <?php endif; ?>
      </td>
      <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.9rem">
        <?= htmlspecialchars(strip_tags($s['content'] ?? '')) ?>
      </td>
      <td>
        <?php
          $vcls = match($s['visibility'] ?? 'public') {
            'public'    => 'badge-success',
            'unlisted'  => 'badge-muted',
            'private'   => 'badge-warning',
            'direct'    => 'badge-muted',
            default     => 'badge-muted',
          };
        ?>
        <span class="badge-pill <?= $vcls ?>"><?= htmlspecialchars($s['visibility'] ?? 'public') ?></span>
      </td>
      <td style="color:var(--muted);font-size:.82rem"><?= htmlspecialchars(substr($s['created_at'] ?? '', 0, 16)) ?></td>
      <td>
        <div class="actions">
          <?php if ($s['username']): ?>
            <a href="/@<?= htmlspecialchars($s['username']) ?>/<?= $s['id'] ?>" class="btn btn-sm" target="_blank">View</a>
          <?php endif; ?>
          <form method="POST" action="/admin/statuses/<?= $s['id'] ?>/delete"
                onsubmit="return confirm('Delete this post? This cannot be undone.')">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
          </form>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($statuses)): ?>
    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:2rem">No posts yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
