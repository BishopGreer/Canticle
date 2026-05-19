<?php
$baseUrl = BASE_URL;
$qs = fn(array $merge) => '?' . http_build_query(array_merge(
    array_filter(['q' => $q, 'type' => $type, 'local' => $local ? '1' : null]),
    $merge
));
$totalResults = count($accounts) + count($statuses) + count($hashtags);
?>

<!-- ── Search bar ─────────────────────────────────────────────────────────── -->
<form method="GET" action="/search" style="margin-bottom:1.25rem">
  <div style="display:flex;gap:.5rem">
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>"
           placeholder="Search posts, people, tags…"
           autofocus
           style="flex:1;padding:.6rem .85rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text);font-size:1rem">
    <button type="submit" class="btn btn-primary" style="padding:.6rem 1.1rem">Search</button>
  </div>
</form>

<?php if ($q !== ''): ?>

<!-- ── Filter tabs ───────────────────────────────────────────────────────── -->
<div style="display:flex;gap:.25rem;flex-wrap:wrap;margin-bottom:1rem;border-bottom:1px solid var(--border);padding-bottom:.5rem">
  <?php
  $tabs = ['all' => 'All', 'people' => 'People', 'posts' => 'Posts', 'tags' => 'Hashtags'];
  foreach ($tabs as $tabKey => $tabLabel):
    $active = $type === $tabKey;
  ?>
    <a href="/search<?= $qs(['type' => $tabKey]) ?>"
       style="padding:.35rem .75rem;border-radius:var(--radius-sm);font-size:.9rem;text-decoration:none;
              background:<?= $active ? 'var(--primary)' : 'var(--surface)' ?>;
              color:<?= $active ? '#fff' : 'var(--text)' ?>;
              box-shadow:var(--shadow-sm)">
      <?= $tabLabel ?>
    </a>
  <?php endforeach; ?>

  <div style="margin-left:auto;display:flex;align-items:center;gap:.4rem;font-size:.88rem;color:var(--muted)">
    <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer">
      <input type="checkbox" form="__none__"
             onclick="window.location='/search<?= $qs(['local' => $local ? null : '1']) ?>'"
             <?= $local ? 'checked' : '' ?>>
      Local only
    </label>
  </div>
</div>

<?php if ($q !== '' && $totalResults === 0): ?>
  <div style="text-align:center;padding:3rem 1rem;color:var(--muted)">
    No results for <strong><?= htmlspecialchars($q) ?></strong>.<br>
    <span style="font-size:.88rem">Try a different term, or search for <code>@user@domain</code> to find remote accounts.</span>
  </div>
<?php endif; ?>

<!-- ── People ──────────────────────────────────────────────────────────── -->
<?php if (($type === 'all' || $type === 'people') && !empty($accounts)): ?>
<section style="margin-bottom:1.5rem">
  <?php if ($type === 'all'): ?>
    <h2 style="font-size:1rem;font-weight:600;margin:0 0 .6rem;color:var(--muted)">
      People <span style="font-weight:400">(<?= count($accounts) ?>)</span>
    </h2>
  <?php endif; ?>

  <div style="display:flex;flex-direction:column;gap:.5rem">
  <?php foreach ($accounts as $acct):
    $acctUrl = $acct['url'] ?? '#';
    $isLocal  = !str_contains($acct['acct'] ?? '', '@');
    $webUrl   = $isLocal ? $baseUrl . '/@' . $acct['username'] : $acctUrl;
  ?>
    <a href="<?= htmlspecialchars($webUrl) ?>"
       style="display:flex;gap:.75rem;align-items:center;padding:.75rem;background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-sm);text-decoration:none;color:var(--text)">
      <img src="<?= htmlspecialchars($acct['avatar'] ?? $baseUrl . '/assets/img/default_avatar.svg') ?>"
           alt="" class="avatar" style="width:44px;height:44px;flex-shrink:0">
      <div style="min-width:0">
        <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?= htmlspecialchars($acct['display_name'] ?: $acct['username']) ?>
          <?php if ($acct['bot'] ?? false): ?>
            <span style="font-size:.75rem;background:var(--surface-2);border-radius:3px;padding:.1rem .35rem;margin-left:.25rem;color:var(--muted)">BOT</span>
          <?php endif; ?>
        </div>
        <div style="font-size:.85rem;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          @<?= htmlspecialchars($isLocal ? $acct['username'] : ($acct['acct'] ?? $acct['username'])) ?>
        </div>
        <?php if (!empty($acct['note'])): ?>
          <div style="font-size:.83rem;color:var(--muted);margin-top:.2rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= htmlspecialchars(mb_substr(strip_tags($acct['note']), 0, 100)) ?>
          </div>
        <?php endif; ?>
      </div>
      <div style="margin-left:auto;text-align:right;font-size:.8rem;color:var(--muted);flex-shrink:0">
        <div><?= number_format($acct['followers_count'] ?? 0) ?> followers</div>
        <?= $isLocal ? '<div style="color:var(--primary);font-size:.75rem">local</div>' : '' ?>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ── Posts ───────────────────────────────────────────────────────────── -->
<?php if (($type === 'all' || $type === 'posts') && !empty($statuses)): ?>
<section style="margin-bottom:1.5rem">
  <?php if ($type === 'all'): ?>
    <h2 style="font-size:1rem;font-weight:600;margin:0 0 .6rem;color:var(--muted)">
      Posts <span style="font-weight:400">(<?= count($statuses) ?>)</span>
    </h2>
  <?php endif; ?>

  <?php
  // Render posts using the shared status helper
  foreach ($statuses as $status):
    $author  = $status['account'] ?? [];
    $isLocal = !str_contains($author['acct'] ?? '', '@');
    $profUrl = $isLocal ? $baseUrl . '/@' . ($author['username'] ?? '') : ($author['url'] ?? '#');
    $postUrl = $isLocal ? $baseUrl . '/statuses/' . $status['id'] : ($status['url'] ?? '#');
  ?>
    <div style="background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-sm);padding:.9rem 1rem;margin-bottom:.5rem">
      <div style="display:flex;gap:.6rem;align-items:center;margin-bottom:.5rem">
        <a href="<?= htmlspecialchars($profUrl) ?>">
          <img src="<?= htmlspecialchars($author['avatar'] ?? $baseUrl . '/assets/img/default_avatar.svg') ?>"
               alt="" class="avatar" style="width:36px;height:36px">
        </a>
        <div style="min-width:0">
          <a href="<?= htmlspecialchars($profUrl) ?>" style="font-weight:600;text-decoration:none;color:var(--text);font-size:.93rem">
            <?= htmlspecialchars($author['display_name'] ?: ($author['username'] ?? '')) ?>
          </a>
          <div style="font-size:.8rem;color:var(--muted)">
            @<?= htmlspecialchars($isLocal ? ($author['username'] ?? '') : ($author['acct'] ?? '')) ?>
          </div>
        </div>
        <a href="<?= htmlspecialchars($postUrl) ?>"
           style="margin-left:auto;font-size:.8rem;color:var(--muted);text-decoration:none;flex-shrink:0"
           title="<?= htmlspecialchars($status['created_at'] ?? '') ?>">
          <time datetime="<?= htmlspecialchars($status['created_at'] ?? '') ?>">
            <?= htmlspecialchars(substr($status['created_at'] ?? '', 0, 10)) ?>
          </time>
        </a>
      </div>
      <?php if (!empty($status['spoiler_text'])): ?>
        <div style="font-weight:600;margin-bottom:.3rem"><?= htmlspecialchars($status['spoiler_text']) ?></div>
      <?php endif; ?>
      <div style="font-size:.93rem;line-height:1.55;overflow-wrap:break-word">
        <?= $status['content'] ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<!-- ── Hashtags ─────────────────────────────────────────────────────────── -->
<?php if (($type === 'all' || $type === 'tags') && !empty($hashtags)): ?>
<section style="margin-bottom:1.5rem">
  <?php if ($type === 'all'): ?>
    <h2 style="font-size:1rem;font-weight:600;margin:0 0 .6rem;color:var(--muted)">
      Hashtags <span style="font-weight:400">(<?= count($hashtags) ?>)</span>
    </h2>
  <?php endif; ?>

  <div style="display:flex;flex-wrap:wrap;gap:.5rem">
    <?php foreach ($hashtags as $tag): ?>
      <a href="<?= htmlspecialchars($tag['url']) ?>"
         style="padding:.35rem .8rem;background:var(--surface);border-radius:999px;box-shadow:var(--shadow-sm);text-decoration:none;color:var(--text);font-size:.9rem">
        #<?= htmlspecialchars($tag['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php else: ?>
<!-- No query yet — show hints -->
<div style="color:var(--muted);padding:2rem 0;font-size:.95rem">
  <p style="margin-bottom:.75rem">Search across posts, people, and hashtags.</p>
  <ul style="margin:0;padding-left:1.25rem;line-height:2">
    <li>Type a keyword to search posts and people</li>
    <li>Use <code>@username@domain.social</code> to find a specific remote account</li>
    <li>Use <code>@domain.social</code> to browse all known users from an instance</li>
    <li>Use <code>#hashtag</code> to find hashtags</li>
  </ul>
</div>
<?php endif; ?>
