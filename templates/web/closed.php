<?php $siteName = config('site_name', 'Canticle'); ?>
<!DOCTYPE html>
<html lang="<?= config('site_lang', 'en') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Registrations Closed — <?= htmlspecialchars($siteName) ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div style="max-width:480px;margin:6rem auto;text-align:center;padding:2rem">
  <h1 style="font-size:1.5rem;margin-bottom:1rem"><?= htmlspecialchars($siteName) ?></h1>
  <p style="color:var(--muted);margin-bottom:2rem">Registrations are currently closed on this instance.</p>
  <a href="/auth/sign_in" class="btn-primary" style="text-decoration:none;padding:.6rem 1.4rem;border-radius:6px">Sign in</a>
</div>
</body>
</html>
