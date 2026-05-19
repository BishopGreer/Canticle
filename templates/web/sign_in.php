<?php
\Canticle\Core\Session::start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title ?? 'Sign in') ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="auth-page">
<div class="auth-box">
  <h1>🎵 <?= htmlspecialchars(config('site_name')) ?></h1>
  <h2 style="font-size:1.1rem;margin-top:0">Sign in to your account</h2>

  <?php if ($error): ?>
    <div class="flash-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="/auth/sign_in">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">
    <label>Email
      <input type="email" name="email" required autocomplete="email">
    </label>
    <label>Password
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit" class="btn-primary">Sign in</button>
  </form>

  <?php if (config('registrations') !== 'closed'): ?>
    <p style="margin-top:1.5rem;text-align:center;color:var(--muted);font-size:.9rem">
      New here? <a href="/auth/sign_up">Create an account</a>
    </p>
  <?php endif; ?>
</div>
</body>
</html>
