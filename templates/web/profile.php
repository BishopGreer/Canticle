<?php
// $profile = user row, $statuses = array, $pinnedStatuses = array,
// $followState = null|'pending'|'accepted', $csrf = string, $user = viewer
require_once CANTICLE_ROOT . '/templates/web/_status_helpers.php';
$profileAccount = \Canticle\Models\User::toMastodon($profile);
$headerUrl  = htmlspecialchars($profileAccount['header']  ?: '/assets/img/default_header.svg');
$avatarUrl  = htmlspecialchars($profileAccount['avatar']  ?: '/assets/img/default_avatar.svg');
$displayName= htmlspecialchars($profileAccount['display_name']);
$acct       = htmlspecialchars($profileAccount['acct']);
$bio        = $profileAccount['note'];
$isMe       = $user && $user['id'] === $profile['id'];

$isFollowing = in_array($followState ?? null, ['accepted', 'pending']);
$btnLabel = match($followState ?? null) {
    'accepted' => 'Following',
    'pending'  => 'Requested',
    default    => 'Follow',
};
?>
<div class="card">
  <div class="profile-header">
    <img src="<?= $headerUrl ?>" alt="" class="profile-header-img">
    <div class="profile-avatar-wrap">
      <img src="<?= $avatarUrl ?>" class="avatar profile-avatar" alt="<?= $displayName ?>">
    </div>
  </div>
  <div class="profile-info">
    <div class="profile-name"><?= $displayName ?></div>
    <div class="profile-acct">@<?= $acct ?></div>
    <?php if ($bio): ?><div class="profile-bio"><?= $bio ?></div><?php endif; ?>
    <div class="profile-stats">
      <span><strong><?= $profileAccount['statuses_count'] ?></strong> posts</span>
      <span><strong><?= $profileAccount['followers_count'] ?></strong> followers</span>
      <span><strong><?= $profileAccount['following_count'] ?></strong> following</span>
    </div>
    <?php if ($user && !$isMe): ?>
    <div class="profile-actions">
      <?php if ($isFollowing): ?>
        <form method="POST" action="/web/unfollow/local/<?= $profile['id'] ?>" style="display:inline">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <button type="submit" class="btn"><?= $btnLabel ?></button>
        </form>
      <?php else: ?>
        <form method="POST" action="/web/follow/local/<?= $profile['id'] ?>" style="display:inline">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <button type="submit" class="btn-primary">Follow</button>
        </form>
      <?php endif; ?>
    </div>
    <?php elseif ($isMe): ?>
    <div class="profile-actions">
      <a href="/settings" class="btn">Edit profile</a>
    </div>
    <?php endif; ?>
  </div>

  <div style="border-top:1px solid var(--border)">
    <?php
    // ── Pinned posts ────────────────────────────────────────────────────────
    $pinnedMastodon = \Canticle\Models\Status::manyToMastodon($pinnedStatuses ?? [], $user);
    foreach ($pinnedMastodon as $status):
    ?>
      <div style="position:relative">
        <div style="font-size:.78rem;color:var(--muted);padding:.4rem 1rem 0;display:flex;align-items:center;gap:.3rem">
          <span>📌</span> <span>Pinned</span>
        </div>
        <?= renderStatusMastodon($status, $user) ?>
      </div>
    <?php endforeach; ?>

    <?php
    // ── Regular posts (exclude pinned duplicates) ───────────────────────────
    $pinnedIds    = array_column($pinnedStatuses ?? [], 'id');
    $regularRows  = array_filter($statuses, fn($s) => !in_array($s['id'], $pinnedIds));
    $regularMasto = \Canticle\Models\Status::manyToMastodon(array_values($regularRows), $user);
    foreach ($regularMasto as $status):
        echo renderStatusMastodon($status, $user);
    endforeach;
    ?>
    <?php if (empty($pinnedStatuses) && empty($statuses)): ?>
      <div style="padding:2rem;text-align:center;color:var(--muted)">No posts yet.</div>
    <?php endif; ?>
  </div>
</div>
