<?php \Canticle\Core\Session::start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($title ?? 'Sign up') ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="auth-page">
<div class="auth-box">
  <h1>🎵 <?= htmlspecialchars(config('site_name')) ?></h1>
  <h2 style="font-size:1.1rem;margin-top:0">Create an account</h2>

  <?php if ($error ?? null): ?><div class="flash-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="POST" action="/auth/sign_up">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
    <label>Username<input type="text" name="username" required pattern="[a-z0-9_]{1,64}" placeholder="letters, numbers, underscore"></label>
    <label>Email<input type="email" name="email" required autocomplete="email"></label>
    <label>Password<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label>Confirm password<input type="password" name="password_confirmation" required minlength="8"></label>
    <?php if ($approval ?? false): ?>
      <p style="color:var(--muted);font-size:.9rem">Registration requires admin approval.</p>
    <?php endif; ?>
    <button type="submit" class="btn-primary">Create account</button>
  </form>
  <p style="margin-top:1.5rem;text-align:center;color:var(--muted);font-size:.9rem">Already have an account? <a href="/auth/sign_in">Sign in</a></p>
</div>
</body>
</html>
