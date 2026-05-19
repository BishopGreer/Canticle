<?php
// $actor = remote_actors row, $statuses = array, $user = local viewer, $csrf = string
require_once CANTICLE_ROOT . '/templates/web/_status_helpers.php';
use Canticle\Models\{RemoteActor, Status};

$account     = RemoteActor::toMastodon($actor);
$headerUrl   = htmlspecialchars($account['header']  ?: '/assets/img/default_header.svg');
$avatarUrl   = htmlspecialchars($account['avatar']  ?: '/assets/img/default_avatar.svg');
$displayName = htmlspecialchars($account['display_name']);
$acct        = htmlspecialchars($account['acct']);
$bio         = $account['note'];
$actorDbId   = (int) $actor['id'];

// Check if the viewer already follows this actor, and what state
$followState = null;
if ($user) {
    $existingFollow = db()->fetch(
        "SELECT state FROM follows WHERE follower_local_id = ? AND followee_remote_id = ?",
        [$user['id'], $actorDbId]
    );
    $followState = $existingFollow['state'] ?? null;
}
$isFollowing = in_array($followState, ['accepted', 'pending']);
$btnLabel    = match($followState) {
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
      <span><strong><?= number_format($account['statuses_count']) ?></strong> posts</span>
      <span><strong><?= number_format($account['followers_count']) ?></strong> followers</span>
      <span><strong><?= number_format($account['following_count']) ?></strong> following</span>
    </div>
    <div class="profile-actions" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
      <?php if ($user): ?>
        <?php if ($isFollowing): ?>
          <form method="POST" action="/web/unfollow/remote/<?= $actorDbId ?>" style="display:inline">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="btn"><?= $btnLabel ?></button>
          </form>
        <?php else: ?>
          <form method="POST" action="/web/follow/remote/<?= $actorDbId ?>" style="display:inline">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="btn-primary">Follow</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <a href="/auth/sign_in" class="btn-primary">Sign in to follow</a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($actor['uri']) ?>" target="_blank" rel="noopener noreferrer"
         style="font-size:.85rem;color:var(--muted)">View on <?= htmlspecialchars($actor['domain']) ?> ↗</a>
    </div>
  </div>

  <div style="border-top:1px solid var(--border)">
    <?php if (empty($statuses)): ?>
      <div style="padding:2rem;text-align:center;color:var(--muted)">
        No cached posts from this account yet.<br>
        <span style="font-size:.88rem">Posts appear here as they arrive via federation.</span>
      </div>
    <?php else: ?>
      <?php
        $mastoStatuses = Status::manyToMastodon($statuses, $user);
        foreach ($mastoStatuses as $status):
            if (function_exists('renderStatusMastodon')) echo renderStatusMastodon($status, $user);
        endforeach;
      ?>
    <?php endif; ?>
  </div>
</div>
