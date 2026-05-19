<?php
$baseUrl = BASE_URL;
$qs = fn(array $merge) => '?' . http_build_query(array_merge(
    array_filter(['q' => $q, 'type' => $type, 'local' => $local ? '1' : null]),
    $merge
));
$totalResults = count($accounts) + count($statuses) + count($hashtags);
?>

<!-- ── Search bar ─────────────────────────────────────────────────────────── -->
<form method="GET" action="/search" class="search-bar">
  <input type="search" name="q" value="<?= htmlspecialchars($q) ?>"
         placeholder="Search posts, people, tags…"
         autofocus>
  <button type="submit" class="btn btn-primary">Search</button>
</form>

<?php if ($q !== ''): ?>

<!-- ── Filter tabs ───────────────────────────────────────────────────────── -->
<div class="search-tabs">
  <?php
  $tabs = ['all' => 'All', 'people' => 'People', 'posts' => 'Posts', 'tags' => 'Hashtags'];
  foreach ($tabs as $tabKey => $tabLabel):
    $active = $type === $tabKey;
  ?>
    <a href="/search<?= $qs(['type' => $tabKey]) ?>"
       class="search-tab<?= $active ? ' active' : '' ?>">
      <?= $tabLabel ?>
    </a>
  <?php endforeach; ?>

  <label class="search-local-toggle">
    <input type="checkbox"
           onclick="window.location='/search<?= $qs(['local' => $local ? null : '1']) ?>'"
           <?= $local ? 'checked' : '' ?>>
    Local only
  </label>
</div>

<?php if ($totalResults === 0): ?>
  <div class="search-empty">
    <div class="search-empty-icon">🔍</div>
    <p>No results for <strong><?= htmlspecialchars($q) ?></strong></p>
    <p class="search-empty-hint">Try a different term, or use <code>@user@domain</code> to find a remote account.</p>
  </div>
<?php endif; ?>

<!-- ── People ──────────────────────────────────────────────────────────── -->
<?php if (($type === 'all' || $type === 'people') && !empty($accounts)): ?>
<section class="search-section">
  <?php if ($type === 'all'): ?>
    <h2 class="search-section-label">
      People <span class="search-section-count"><?= count($accounts) ?></span>
    </h2>
  <?php endif; ?>

  <div class="people-list">
  <?php foreach ($accounts as $acct):
    $isLocal = !str_contains($acct['acct'] ?? '', '@');
    // Always link to local profile page so the user can follow from here
    $webUrl  = $isLocal
      ? $baseUrl . '/@' . $acct['username']
      : $baseUrl . '/@' . $acct['acct'];
  ?>
    <a href="<?= htmlspecialchars($webUrl) ?>" class="person-card">
      <img src="<?= htmlspecialchars($acct['avatar'] ?? $baseUrl . '/assets/img/default_avatar.svg') ?>"
           alt="" class="avatar" style="width:46px;height:46px;flex-shrink:0">
      <div class="person-card-info">
        <div class="person-card-name">
          <?= htmlspecialchars($acct['display_name'] ?: $acct['username']) ?>
          <?php if ($acct['bot'] ?? false): ?>
            <span class="person-card-badge remote">BOT</span>
          <?php endif; ?>
          <?php if ($isLocal): ?>
            <span class="person-card-badge local">local</span>
          <?php endif; ?>
        </div>
        <div class="person-card-handle">
          @<?= htmlspecialchars($isLocal ? $acct['username'] : ($acct['acct'] ?? $acct['username'])) ?>
        </div>
        <?php if (!empty($acct['note'])): ?>
          <div class="person-card-bio">
            <?= htmlspecialchars(mb_substr(strip_tags($acct['note']), 0, 120)) ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="person-card-meta">
        <div class="person-card-followers"><?= number_format($acct['followers_count'] ?? 0) ?></div>
        <div class="person-card-followers-label">followers</div>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ── Posts ───────────────────────────────────────────────────────────── -->
<?php if (($type === 'all' || $type === 'posts') && !empty($statuses)): ?>
<section class="search-section">
  <?php if ($type === 'all'): ?>
    <h2 class="search-section-label">
      Posts <span class="search-section-count"><?= count($statuses) ?></span>
    </h2>
  <?php endif; ?>

  <div class="search-post-list">
  <?php foreach ($statuses as $status):
    $author  = $status['account'] ?? [];
    $isLocal = !str_contains($author['acct'] ?? '', '@');
    $profUrl = $isLocal
      ? $baseUrl . '/@' . ($author['username'] ?? '')
      : $baseUrl . '/@' . ($author['acct'] ?? '');
    $postUrl = '/statuses/' . $status['id'];
  ?>
    <div class="search-post-card">
      <div class="search-post-header">
        <a href="<?= htmlspecialchars($profUrl) ?>">
          <img src="<?= htmlspecialchars($author['avatar'] ?? $baseUrl . '/assets/img/default_avatar.svg') ?>"
               alt="" class="avatar search-post-avatar">
        </a>
        <div class="search-post-author">
          <a href="<?= htmlspecialchars($profUrl) ?>" class="search-post-name">
            <?= htmlspecialchars($author['display_name'] ?: ($author['username'] ?? '')) ?>
          </a>
          <div class="search-post-handle">
            @<?= htmlspecialchars($isLocal ? ($author['username'] ?? '') : ($author['acct'] ?? '')) ?>
          </div>
        </div>
        <a href="<?= htmlspecialchars($postUrl) ?>" class="search-post-date"
           title="<?= htmlspecialchars($status['created_at'] ?? '') ?>">
          <time datetime="<?= htmlspecialchars($status['created_at'] ?? '') ?>">
            <?= htmlspecialchars(substr($status['created_at'] ?? '', 0, 10)) ?>
          </time>
        </a>
      </div>
      <?php if (!empty($status['spoiler_text'])): ?>
        <div class="search-post-cw"><?= htmlspecialchars($status['spoiler_text']) ?></div>
      <?php endif; ?>
      <div class="search-post-content">
        <?= $status['content'] ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ── Hashtags ─────────────────────────────────────────────────────────── -->
<?php if (($type === 'all' || $type === 'tags') && !empty($hashtags)): ?>
<section class="search-section">
  <?php if ($type === 'all'): ?>
    <h2 class="search-section-label">
      Hashtags <span class="search-section-count"><?= count($hashtags) ?></span>
    </h2>
  <?php endif; ?>

  <div class="hashtag-list">
    <?php foreach ($hashtags as $tag): ?>
      <a href="<?= htmlspecialchars($tag['url']) ?>" class="hashtag-pill">
        #<?= htmlspecialchars($tag['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php else: ?>
<!-- No query yet — show hints -->
<div class="search-hints">
  <p style="margin-bottom:.75rem;color:var(--text-2)">Search across posts, people, and hashtags.</p>
  <ul>
    <li>Type a keyword to search posts and people</li>
    <li>Use <code>@username@domain.social</code> to find a specific remote account</li>
    <li>Use <code>@domain.social</code> to browse all known users from an instance</li>
    <li>Use <code>#hashtag</code> to find a hashtag</li>
  </ul>
</div>
<?php endif; ?>
