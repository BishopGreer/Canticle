<div style="max-width:640px">
  <h1 style="margin-bottom:.25rem">Privacy Policy</h1>
  <p style="color:var(--muted);font-size:.9rem;margin-bottom:2rem">
    <?= htmlspecialchars(config('site_name', 'This instance')) ?>
  </p>

  <?php if (!empty(trim($privacy ?? ''))): ?>
    <div class="admin-form" style="white-space:pre-wrap;line-height:1.75;font-size:.97rem">
      <?= nl2br(htmlspecialchars($privacy)) ?>
    </div>
  <?php else: ?>
    <div style="color:var(--muted);padding:2rem;text-align:center;background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-sm)">
      No privacy policy has been posted yet.
    </div>
  <?php endif; ?>
</div>
