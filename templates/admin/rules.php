<?php \Canticle\Core\Session::start(); ?>
<h1>Rules</h1>
<p style="color:var(--muted);font-size:.9rem;margin-bottom:1.5rem">
  These rules are shown publicly at <a href="/rules" target="_blank">/rules</a>.
  Use plain text or basic HTML. Each rule on its own line is recommended.
</p>

<form method="POST" action="/admin/rules" style="max-width:720px">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
  <div class="admin-form">
    <label style="display:block">
      <span style="font-weight:600;display:block;margin-bottom:.4rem">Instance rules</span>
      <textarea name="rules" rows="20"
                style="width:100%;font-family:inherit;font-size:.95rem;padding:.6rem .75rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text);resize:vertical;line-height:1.6"
                placeholder="1. Be kind and respectful.&#10;2. No harassment or hate speech.&#10;3. ..."><?= htmlspecialchars($rules ?? '') ?></textarea>
    </label>
    <div style="margin-top:.75rem">
      <button type="submit" class="btn btn-primary">Save rules</button>
      <a href="/rules" target="_blank" class="btn btn-sm" style="margin-left:.5rem">Preview</a>
    </div>
  </div>
</form>
