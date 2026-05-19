<?php \Canticle\Core\Session::start(); ?>
<h1>Instance Settings</h1>

<form method="POST" action="/admin/settings" style="max-width:680px">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\Canticle\Core\Session::csrfToken()) ?>">

  <div class="admin-form">
    <h2 style="margin-top:0;margin-bottom:1rem">Instance</h2>
    <label>Site name
      <input type="text" name="site_name" value="<?= htmlspecialchars($config['site_name'] ?? '') ?>">
    </label>
    <label>Description
      <textarea name="site_desc" rows="3"><?= htmlspecialchars($config['site_desc'] ?? '') ?></textarea>
    </label>
    <label>Contact email
      <input type="email" name="contact_email" value="<?= htmlspecialchars($config['contact_email'] ?? '') ?>">
    </label>
    <label>Registrations
      <select name="registrations">
        <option value="open"     <?= ($config['registrations'] ?? '') === 'open'     ? 'selected' : '' ?>>Open — anyone can sign up</option>
        <option value="approval" <?= ($config['registrations'] ?? '') === 'approval' ? 'selected' : '' ?>>Approval required</option>
        <option value="closed"   <?= ($config['registrations'] ?? '') === 'closed'   ? 'selected' : '' ?>>Closed</option>
      </select>
    </label>
  </div>

  <div class="admin-form">
    <h2 style="margin-top:0;margin-bottom:1rem">Post limits</h2>
    <label>Max characters per post
      <input type="number" name="max_chars" value="<?= (int)($config['max_chars'] ?? 500) ?>" min="100" max="99999">
    </label>
    <label>Max poll options
      <input type="number" name="max_poll_options" value="<?= (int)($config['max_poll_options'] ?? 4) ?>" min="2" max="20">
    </label>
    <label>Max media attachments per post
      <input type="number" name="max_media" value="<?= (int)($config['max_media'] ?? 4) ?>" min="1" max="20">
    </label>
    <label>Max upload size (MB)
      <input type="number" name="max_media_mb" value="<?= (int)($config['max_media_mb'] ?? 40) ?>" min="1" max="1000">
    </label>
  </div>

  <div class="admin-form">
    <h2 style="margin-top:0;margin-bottom:1rem">AI Alt Text</h2>
    <p style="color:var(--muted);font-size:.88rem;margin-bottom:1rem">
      Automatically generate alt text for uploaded images using an AI model.
    </p>
    <label>Provider
      <select name="alttext_provider">
        <option value=""       <?= empty($config['alttext_provider'])                          ? 'selected' : '' ?>>Disabled</option>
        <option value="claude" <?= ($config['alttext_provider'] ?? '') === 'claude' ? 'selected' : '' ?>>Claude (Anthropic)</option>
        <option value="openai" <?= ($config['alttext_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI</option>
        <option value="ollama" <?= ($config['alttext_provider'] ?? '') === 'ollama' ? 'selected' : '' ?>>Ollama (local)</option>
      </select>
    </label>
    <label>API Key
      <input type="password" name="alttext_api_key" value="<?= htmlspecialchars($config['alttext_api_key'] ?? '') ?>">
    </label>
    <label>Model <span style="font-weight:400;color:var(--muted)">(leave blank for default)</span>
      <input type="text" name="alttext_model" placeholder="claude-haiku-4-5-20251001 / gpt-4o-mini / llava" value="<?= htmlspecialchars($config['alttext_model'] ?? '') ?>">
    </label>
    <label>Endpoint <span style="font-weight:400;color:var(--muted)">(Ollama only)</span>
      <input type="text" name="alttext_endpoint" placeholder="http://localhost:11434" value="<?= htmlspecialchars($config['alttext_endpoint'] ?? '') ?>">
    </label>
  </div>

  <button type="submit" class="btn-primary">Save settings</button>
</form>
