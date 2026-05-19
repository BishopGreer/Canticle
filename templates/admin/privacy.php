<?php \Canticle\Core\Session::start(); ?>
<h1>Privacy Policy</h1>
<p style="color:var(--muted);font-size:.9rem;margin-bottom:1.5rem">
  This policy is shown publicly at <a href="/privacy" target="_blank">/privacy</a>.
  You may use plain text or HTML.
</p>

<form method="POST" action="/admin/privacy" style="max-width:720px">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
  <div class="admin-form">
    <label style="display:block">
      <span style="font-weight:600;display:block;margin-bottom:.4rem">Privacy policy</span>
      <textarea name="privacy" rows="24"
                style="width:100%;font-family:inherit;font-size:.95rem;padding:.6rem .75rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text);resize:vertical;line-height:1.6"
                placeholder="This instance collects...&#10;&#10;Data we store:&#10;- Your username and email address&#10;- Posts you create&#10;..."><?= htmlspecialchars($privacy ?? '') ?></textarea>
    </label>
    <div style="margin-top:.75rem">
      <button type="submit" class="btn btn-primary">Save privacy policy</button>
      <a href="/privacy" target="_blank" class="btn btn-sm" style="margin-left:.5rem">Preview</a>
    </div>
  </div>
</form>
