# Changelog

All notable changes to Canticle will be documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

- **MAJOR** — breaking changes or full rewrites
- **MINOR** — new features, new admin/user-facing behaviour (backwards-compatible)
- **PATCH** — bug fixes, security patches, performance improvements

---

## [1.1.1] — 2026-05-26

### Fixed
- **Followers-only posts not federated** — posts with `private` (followers-only) visibility were never delivered to remote followers. Only `public` and `unlisted` passed the visibility guard in the post handler.
- **Unlisted posts had wrong ActivityPub addressing** — unlisted posts are now correctly addressed `to: [followers], cc: [Public]` per the Mastodon convention, making them discoverable in remote federated timelines without appearing in the public firehose. Previously `cc` was empty, causing unlisted posts to be invisible on many remote servers.

---

## [1.1.0] — 2026-05-26

Six federation health improvements modelled on patterns from Mastodon's source:

### Added
- **Object dereferencing** — `handleCreate` now fetches the full post from the remote server when the activity delivers only a bare URI string as the object (some servers send stub-style activities). Previously those posts were silently dropped.
- **Relevance guard** — incoming `Create` and `Announce` activities are now filtered before being stored. A post is only saved if the remote actor is followed by a local user, the post addresses/mentions a local user, it replies to a local user's post, or it arrives from a subscribed relay. Relay-pushed content is always accepted. This prevents unbounded DB growth from unsolicited federation traffic.
- **30-day age window** — remote posts older than 30 days are not stored. They won't appear in any timeline and only consume space.
- **Tombstones** (migration `006_inbox_safety.sql`) — when a `Delete` activity arrives for a URI that is not locally known, a tombstone record is written. If a `Create` or `Announce` for that same URI arrives later (Delete beat Create in network ordering), it is silently discarded. Tombstones are pruned after 7 days by the nightly pruner.
- **Delivery failure tracking** (migration `006_inbox_safety.sql`) — the worker now records success/failure counts per remote domain in the `instances` table. After 10 consecutive failures, deliveries to that domain are skipped for 1 hour (back-off). Successful delivery resets the counter. This prevents the queue from flooding with retries to dead servers.
- **Boost deduplication in timelines** — if the same original post is boosted multiple times within a single timeline fetch, only the most-recent boost is shown. Prevents a single popular post from filling the entire Explore or home feed.

### Changed
- `handleAnnounce` now also accepts `Article`, `Page`, and `Question` object types (in addition to `Note`) when fetching the original post — consistent with Mastodon's behaviour.
- Mention notifications are now fired for all local users in the `to`/`cc` audience of an incoming Create, not only for the parent post's author.

---

## [1.0.3] — 2026-05-26

### Changed
- Public/Explore timeline now includes boosts — previously `reblog_of_id IS NULL` silently hid all boosted posts from both the web Explore page and the Mastodon API federated timeline (`GET /api/v1/timelines/public`). Relay-pushed content arrives as Announce activities, so hiding boosts was hiding the majority of relay content. The web renderer already showed "🔄 X boosted" correctly; the filter was just suppressing the rows before they reached the renderer.

---

## [1.0.2] — 2026-05-19

### Fixed
- **Boosts (Announce) were not federating** — boosting a post never sent an ActivityPub Announce activity to remote followers or to the original post's server
- **Unboost was not federating** — removing a boost never sent an Undo Announce activity
- **Duplicate boost on re-click** — missing `return` after idempotency check in `reblog()` caused a second boost row to be created silently on every re-click
- **Incoming remote boosts not shown in timeline** — `handleAnnounce` only incremented the reblogs count but never created a boost status row, so followed accounts' boosts never appeared in the home feed
- **Incoming Undo Announce not handled** — un-boosting from a remote server was silently ignored; now removes the boost row and decrements the count

---

## [1.0.1] — 2026-05-19

### Fixed
- Search result people cards now link to the local `/@user@domain` profile page instead of the remote instance URL, so users can follow remote accounts without leaving the instance
- Search UI redesigned: replaced all inline styles with design-token CSS classes for consistent theming, dark-mode support, and hover states

### Added
- Section labels with result counts on search results page
- Post cards in search results with author avatar, handle, date permalink, and hover highlight
- Polished empty-state and search-hints panels

---

## [1.0.0] — 2026-05-19

First public release. Full-featured self-hosted ActivityPub/Mastodon-compatible
federated social platform in plain PHP with no framework dependencies.

### Core platform
- ActivityPub federation — Follow, Accept, Reject, Undo, Create, Announce, Like, Update, Delete activities
- HTTP Signatures (draft-cavage-http-signatures-12) on all outgoing activities; verification on all incoming
- WebFinger (RFC 7033) actor discovery served as `application/jrd+json`
- NodeInfo 2.0 endpoint for instance metadata
- Mastodon-compatible REST API v1 and v2 — works with Ivory, Tusky, Elk, Phanpy, Ice Cubes, and others
- Shared inbox and per-user inbox
- DB-backed async job queue with worker process (`php artisan.php worker`)
- SQL migration runner (`php artisan.php migrate`) with comment-safe semicolon splitting

### Federation
- Follow remote accounts; Accept/Reject incoming follow requests
- Outgoing Follow activities signed and delivered to shared or per-user inboxes
- Incoming activities verified before processing
- Domain block (defederate) and silence with public and private reasons
- Federation block list import (Mastodon-compatible CSV) and export
- Relay subscriptions (subscribe/unsubscribe, automatic Follow/Undo delivery)
- Remote actor profile fetch and cache with 1-hour TTL
- Remote outbox import (first 20 public posts on profile visit)
- Media attachments saved from incoming Create and Announce activities

### Web UI
- Home timeline (own posts + followed accounts, all visibility levels)
- Public/Explore timeline (public posts from local and federated accounts)
- Hashtag timeline pages (`/tags/:name`)
- Compose modal with character counter, image upload, optional poll builder, visibility selector
- Profile pages for local and remote users with pinned posts, follow/unfollow (form POST, no JS required)
- Status permalink pages
- Notifications (mentions, follows, favourites, boosts)
- Search — posts, people (local + remote), hashtags; `@user@domain` WebFinger lookup; `@domain` instance browse
- Rules page (`/rules`) — editable by admins, public-facing
- Privacy policy page (`/privacy`) — editable by admins, public-facing

### Admin panel
- Dashboard with instance stats
- Settings — site name, description, registrations, post limits, AI alt text provider
- Rules editor (`/admin/rules`)
- Privacy policy editor (`/admin/privacy`)
- User management — list, suspend/unsuspend, promote to admin/moderator
- Content moderation — view and delete any status
- Federation — block/silence domains, import/export block CSV, view blocked instances
- Relay management — add, remove, retry
- Queue monitor — view pending and failed jobs, retry or delete individual failed jobs, clear all failed
- Retention settings — configure automatic remote content pruning
- Upgrades panel — run migrations, git pull, OPcache flush from the browser

### Posts
- Public, unlisted, followers-only, and direct visibility
- Content warnings (spoiler text)
- Sensitive media flag
- Multiple media attachments (images, video, audio)
- AI-generated alt text (Claude, OpenAI, or Ollama)
- Multiple-choice polls with expiry times
- Hashtag extraction and linking
- Mention extraction
- Boost (Announce) and unboost
- Favourite and unfavourite
- Pin and unpin posts on profile
- Edit history (stored; served via AP Update activity)
- Delete (soft-delete with Tombstone delivery)

### Accounts
- Local user registration (open, approval-required, or closed)
- RSA-2048 keypair generated per user at registration
- Avatar and header image upload
- Profile bio, display name, locked account toggle
- Followers and following lists
- Block and mute local and remote accounts

### AI alt text
- Pluggable provider architecture
- Claude (Anthropic) — via Messages API
- OpenAI — via Chat Completions API
- Ollama — local models (LLaVA etc.)

### CLI (`php artisan.php`)
- `worker` — process queue jobs (run forever or `--once`)
- `migrate` — apply pending database migrations
- `prune` — remove old remote content per retention settings
- `check:actor [username]` — verify keypair validity and actor endpoint reachability; diagnose 401 errors
- `regenerate:keys <username>` — generate a fresh RSA keypair for a user

### Security
- CSRF protection on all state-changing web forms (double-submit session token)
- Bearer token authentication for API endpoints
- `.htaccess` blocks `config.php`, `storage/`, `migrations/`, `artisan.php`, and dotfiles
- `/.well-known/` correctly routed through the PHP front controller (WebFinger accessible)
- HTTP Signatures verified on every inbox delivery before any processing

### Database migrations
| File | Description |
|------|-------------|
| `001_initial.sql` | Full base schema |
| `002_relays.sql` | Relay subscriptions |
| `003_remote_content_retention.sql` | Prune log and retention settings |
| `004_pinned_posts.sql` | `pinned_at` column on statuses |
| `005_federation_block_reasons.sql` | `public_reason` and `private_reason` on instances |

---

## Versioning guide for contributors

```
v1.0.0   — initial release
v1.0.1   — patch: fix a bug, no new features
v1.1.0   — minor: add a feature, no breaking changes
v2.0.0   — major: breaking DB changes, removed APIs, architectural rewrites
```

Tag every release: `git tag -a v1.0.1 -m "Fix: description"` then `git push origin v1.0.1`.

[1.1.1]: https://github.com/BishopGreer/canticle/releases/tag/v1.1.1
[1.1.0]: https://github.com/BishopGreer/canticle/releases/tag/v1.1.0
[1.0.3]: https://github.com/BishopGreer/canticle/releases/tag/v1.0.3
[1.0.2]: https://github.com/BishopGreer/canticle/releases/tag/v1.0.2
[1.0.1]: https://github.com/BishopGreer/canticle/releases/tag/v1.0.1
[1.0.0]: https://github.com/BishopGreer/canticle/releases/tag/v1.0.0
