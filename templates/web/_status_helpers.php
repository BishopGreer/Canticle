<?php
// Shared status rendering helpers — included by timeline, profile, and remote_profile templates.
// Safe to include multiple times (functions are only defined once).

if (!function_exists('renderStatus')):

function renderStatus(array $s, ?array $viewer): string
{
    $status = \Canticle\Models\Status::toMastodon($s, $viewer);
    return renderStatusMastodon($status, $viewer);
}

function renderStatusMastodon(array $status, ?array $viewer): string
{
    $baseUrl   = BASE_URL;
    $isBoosted = isset($status['reblog']) && $status['reblog'];
    $display   = $isBoosted ? $status['reblog'] : $status;
    $account   = $display['account'] ?? $status['account'];

    if (!$account) return '';

    $avatarUrl  = htmlspecialchars($account['avatar'] ?? '/assets/img/default_avatar.svg');
    $acct       = htmlspecialchars($account['acct'] ?? '');
    $name       = htmlspecialchars($account['display_name'] ?: ($account['username'] ?? ''));
    $profileUrl = htmlspecialchars($baseUrl . '/@' . ($account['acct'] ?? $account['username'] ?? ''));
    $time       = htmlspecialchars($display['created_at'] ?? '');
    $content    = $display['content'] ?? '';
    $cw         = htmlspecialchars($display['spoiler_text'] ?? '');
    $sid        = htmlspecialchars((string) $display['id']);
    $favCount   = (int) $display['favourites_count'];
    $rebCount   = (int) $display['reblogs_count'];
    $repCount   = (int) $display['replies_count'];
    $faved      = $display['favourited'] ?? false;
    $rebogged   = $display['reblogged'] ?? false;

    $html = '<article class="status">';

    if ($isBoosted) {
        $boosterName = htmlspecialchars($status['account']['display_name'] ?: $status['account']['username']);
        $html .= '<div class="boosted-by">🔄 ' . $boosterName . ' boosted</div>';
    }

    $html .= '<div class="status-header">';
    $html .= '<a href="' . $profileUrl . '"><img src="' . $avatarUrl . '" class="avatar" alt="' . $name . '"></a>';
    $html .= '<div><div class="account-name"><a href="' . $profileUrl . '">' . $name . '</a></div>';
    $html .= '<div class="account-acct">@' . $acct . '</div></div>';
    $html .= '<time class="status-time" datetime="' . $time . '">' . $time . '</time>';
    $html .= '</div>';

    if ($cw) {
        $html .= '<button class="cw-toggle">CW: ' . $cw . '</button>';
        $html .= '<div class="cw-body"><div class="status-content">' . $content . '</div></div>';
    } else {
        $html .= '<div class="status-content">' . $content . '</div>';
    }

    // Media
    $media = $display['media_attachments'] ?? [];
    if ($media) {
        $count = count($media);
        $html .= '<div class="status-media media-' . $count . '">';
        foreach ($media as $m) {
            $url = htmlspecialchars($m['url'] ?? '');
            $alt = htmlspecialchars($m['description'] ?? '');
            if (($m['type'] ?? '') === 'video') {
                $html .= '<video src="' . $url . '" controls muted></video>';
            } else {
                $html .= '<img src="' . $url . '" alt="' . $alt . '">';
            }
        }
        $html .= '</div>';
    }

    // Poll
    if (!empty($display['poll'])) {
        $html .= renderPoll($display['poll'], $display['id'], (bool) ($display['poll']['voted'] ?? false), $viewer);
    }

    // Actions
    $html .= '<div class="status-actions">';
    $html .= '<button data-action="reply" data-id="' . $sid . '">💬 <span class="count">' . $repCount . '</span></button>';
    $html .= '<button data-action="reblog" data-id="' . $sid . '" class="' . ($rebogged ? 'active' : '') . '">🔄 <span class="count">' . $rebCount . '</span></button>';
    $html .= '<button data-action="favourite" data-id="' . $sid . '" class="' . ($faved ? 'active' : '') . '">⭐ <span class="count">' . $favCount . '</span></button>';
    $html .= '<a href="' . htmlspecialchars($baseUrl . '/@' . ($account['acct'] ?? $account['username'] ?? '') . '/' . $sid) . '" style="font-size:.82rem;color:var(--muted);margin-left:auto">🔗</a>';
    $html .= '</div>';
    $html .= '</article>';

    return $html;
}

function renderPoll(array $poll, string $statusId, bool $voted, ?array $viewer): string
{
    $expired = $poll['expired'] ?? false;
    $html    = '<div class="poll">';

    if ($voted || $expired || !$viewer) {
        foreach ($poll['options'] as $opt) {
            $votes = $opt['votes_count'] ?? 0;
            $total = max(1, $poll['votes_count'] ?? 1);
            $pct   = round($votes / $total * 100);
            $html .= '<div class="poll-option">';
            $html .= '<div class="poll-bar"><div class="poll-bar-fill" style="width:' . $pct . '%"></div>';
            $html .= '<span class="poll-bar-label">' . htmlspecialchars($opt['title']) . ' — ' . $pct . '%</span></div>';
            $html .= '</div>';
        }
        $html .= '<div class="poll-votes">' . $poll['votes_count'] . ' votes';
        if ($poll['expires_at']) $html .= ' · Expires ' . htmlspecialchars($poll['expires_at']);
        $html .= '</div>';
    } else {
        $html .= '<form class="poll-vote-form" method="POST" action="/api/v1/polls/' . htmlspecialchars($poll['id']) . '/votes">';
        $type  = $poll['multiple'] ? 'checkbox' : 'radio';
        foreach ($poll['options'] as $i => $opt) {
            $html .= '<label><input type="' . $type . '" name="choices[]" value="' . $i . '"> ' . htmlspecialchars($opt['title']) . '</label>';
        }
        $html .= '<button type="submit" style="margin-top:.5rem">Vote</button>';
        $html .= '</form>';
        if ($poll['expires_at']) $html .= '<div class="poll-votes">Expires ' . htmlspecialchars($poll['expires_at']) . '</div>';
    }

    $html .= '</div>';
    return $html;
}

endif;
