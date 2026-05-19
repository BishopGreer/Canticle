<?php
// $user = current user row, $flash_success, $flash_error
$avatarUrl = '';
if (!empty($user['avatar'])) {
    $avatarUrl = $user['avatar'][0] === 'h'
        ? $user['avatar']
        : BASE_URL . '/' . $user['avatar'];
} else {
    $avatarUrl = BASE_URL . '/assets/img/default_avatar.svg';
}
$headerUrl = '';
if (!empty($user['header'])) {
    $headerUrl = $user['header'][0] === 'h'
        ? $user['header']
        : BASE_URL . '/' . $user['header'];
} else {
    $headerUrl = BASE_URL . '/assets/img/default_header.svg';
}
?>

<h1>Edit profile</h1>

<?php if (!empty($flash_success)): ?>
  <div class="flash-success" style="margin-bottom:1.25rem"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>
<?php if (!empty($flash_error)): ?>
  <div class="flash-error" style="margin-bottom:1.25rem"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<form method="POST" action="/settings" enctype="multipart/form-data"
      style="max-width:640px;display:flex;flex-direction:column;gap:1.5rem">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">

  <!-- ── Identity ── -->
  <div class="card" style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem">
    <h3 style="margin:0 0 .25rem;font-size:1rem">Identity</h3>

    <div>
      <label style="font-size:.85rem;font-weight:600;color:var(--text-2);display:block;margin-bottom:.3rem">
        Display name
      </label>
      <input type="text" name="display_name" maxlength="100"
             value="<?= htmlspecialchars($user['display_name'] ?? '') ?>"
             style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text);font-size:.95rem">
    </div>

    <div>
      <label style="font-size:.85rem;font-weight:600;color:var(--text-2);display:block;margin-bottom:.3rem">
        Bio
      </label>
      <textarea name="bio" maxlength="500" rows="4"
                style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text);font-size:.95rem;resize:vertical"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
      <div style="font-size:.78rem;color:var(--muted);margin-top:.25rem">Up to 500 characters.</div>
    </div>
  </div>

  <!-- ── Avatar & Header ── -->
  <div class="card" style="padding:1.25rem;display:flex;flex-direction:column;gap:1.25rem">
    <h3 style="margin:0 0 .25rem;font-size:1rem">Images</h3>

    <div style="display:flex;align-items:center;gap:1rem">
      <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Current avatar"
           style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--border)">
      <div style="flex:1">
        <label style="font-size:.85rem;font-weight:600;color:var(--text-2);display:block;margin-bottom:.3rem">
          Avatar
        </label>
        <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp"
               style="font-size:.88rem;color:var(--text)">
        <div style="font-size:.78rem;color:var(--muted);margin-top:.25rem">JPEG, PNG, GIF, or WebP · max 2 MB</div>
      </div>
    </div>

    <div>
      <img src="<?= htmlspecialchars($headerUrl) ?>" alt="Current header"
           style="width:100%;height:100px;object-fit:cover;border-radius:var(--radius-sm);border:1px solid var(--border);margin-bottom:.5rem">
      <label style="font-size:.85rem;font-weight:600;color:var(--text-2);display:block;margin-bottom:.3rem">
        Header image
      </label>
      <input type="file" name="header" accept="image/jpeg,image/png,image/gif,image/webp"
             style="font-size:.88rem;color:var(--text)">
      <div style="font-size:.78rem;color:var(--muted);margin-top:.25rem">JPEG, PNG, GIF, or WebP · max 4 MB · recommended 1500×500</div>
    </div>
  </div>

  <!-- ── Account flags ── -->
  <div class="card" style="padding:1.25rem;display:flex;flex-direction:column;gap:.75rem">
    <h3 style="margin:0 0 .25rem;font-size:1rem">Account settings</h3>

    <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-size:.92rem">
      <input type="checkbox" name="locked" value="1" <?= !empty($user['locked']) ? 'checked' : '' ?>
             style="width:1rem;height:1rem;accent-color:var(--accent)">
      <span>
        <strong>Require follow approval</strong>
        <span style="display:block;font-size:.8rem;color:var(--muted)">New followers must be approved by you.</span>
      </span>
    </label>

    <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-size:.92rem">
      <input type="checkbox" name="discoverable" value="1" <?= !empty($user['discoverable']) ? 'checked' : '' ?>
             style="width:1rem;height:1rem;accent-color:var(--accent)">
      <span>
        <strong>Discoverable</strong>
        <span style="display:block;font-size:.8rem;color:var(--muted)">Allow your profile to appear in search and directories.</span>
      </span>
    </label>

    <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-size:.92rem">
      <input type="checkbox" name="bot" value="1" <?= !empty($user['bot']) ? 'checked' : '' ?>
             style="width:1rem;height:1rem;accent-color:var(--accent)">
      <span>
        <strong>This is a bot account</strong>
        <span style="display:block;font-size:.8rem;color:var(--muted)">Marks your profile as an automated account.</span>
      </span>
    </label>
  </div>

  <!-- ── Change password ── -->
  <div class="card" style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem">
    <h3 style="margin:0 0 .25rem;font-size:1rem">Change password</h3>
    <div style="font-size:.85rem;color:var(--muted)">Leave blank to keep your current password.</div>

    <div>
      <label style="font-size:.85rem;font-weight:600;color:var(--text-2);display:block;margin-bottom:.3rem">
        New password
      </label>
      <input type="password" name="password" autocomplete="new-password" minlength="8"
             style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text);font-size:.95rem">
    </div>

    <div>
      <label style="font-size:.85rem;font-weight:600;color:var(--text-2);display:block;margin-bottom:.3rem">
        Confirm new password
      </label>
      <input type="password" name="password_confirmation" autocomplete="new-password"
             style="width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text);font-size:.95rem">
    </div>
  </div>

  <div>
    <button type="submit" class="btn-primary">Save changes</button>
    <a href="/<?= htmlspecialchars('@' . $user['username']) ?>" class="btn" style="margin-left:.5rem">Cancel</a>
  </div>

</form>
