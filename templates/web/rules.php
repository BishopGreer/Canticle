<div style="max-width:640px">
  <h1 style="margin-bottom:.25rem">Rules</h1>
  <p style="color:var(--muted);font-size:.9rem;margin-bottom:2rem">
    By using <?= htmlspecialchars(config('site_name', 'this instance')) ?> you agree to follow these rules.
  </p>

  <?php if (!empty(trim($rules ?? ''))): ?>
    <div class="admin-form" style="white-space:pre-wrap;line-height:1.75;font-size:.97rem">
      <?= nl2br(htmlspecialchars($rules)) ?>
    </div>
  <?php else: ?>
    <div style="color:var(--muted);padding:2rem;text-align:center;background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-sm)">
      No rules have been posted yet.
    </div>
  <?php endif; ?>
</div>
