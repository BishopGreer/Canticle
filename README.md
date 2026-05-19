# Canticle

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](CHANGELOG.md)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://php.net)
[![ActivityPub](https://img.shields.io/badge/ActivityPub-compatible-orange.svg)](https://www.w3.org/TR/activitypub/)

A self-hosted, ActivityPub-compatible federated social platform with a full Mastodon API — written in plain PHP with no framework dependencies.

Connect from any Mastodon-compatible app (Ivory, Tusky, Elk, etc.) or use the built-in web interface. Federates with Mastodon, Pleroma, Misskey, Pixelfed, and any other ActivityPub server.

---

## Features

- **ActivityPub federation** — Follow, unfollow, post, boost, and like across the Fediverse
- **Full Mastodon API v1/v2** — works with all existing Mastodon apps out of the box
- **Web UI** — home timeline, explore, profiles, notifications, compose, hashtag pages
- **Search** — posts, people (local + remote), and hashtags; `@user@domain` live WebFinger lookup
- **Admin panel** — users, federation controls, relay management, queue monitor, content retention, rules, privacy policy
- **Relay support** — subscribe to relay servers to pull in public federated content
- **AI-generated alt text** — pluggable provider: Claude (Anthropic), OpenAI, or Ollama (local)
- **Media uploads** — images, video, and audio with blurhash previews
- **Polls** — optional multiple-choice polls with expiry times
- **Federation block list** — import/export Mastodon-compatible CSV; per-domain public and private reasons
- **Rules & Privacy Policy** — admin-editable pages served publicly at `/rules` and `/privacy`
- **Content retention** — automatic pruning of old remote content to control disk/DB growth
- **Queue worker** — async activity delivery with retry, per-job delete, and clear-all-failed
- **HTTP Signatures** — all outgoing activities are signed; all incoming are verified
- **Zero dependencies** — vanilla PHP 8.1+, MariaDB, Apache2; no Composer, no npm

---

## Requirements

| Component | Version |
|-----------|---------|
| PHP | 8.1 or newer |
| PHP extensions | `pdo_mysql`, `openssl`, `curl`, `gd` (or `imagick`), `mbstring` |
| Database | MariaDB 10.4+ or MySQL 8+ |
| Web server | Apache2 with `mod_rewrite`, `mod_headers`, `mod_ssl` |
| Shell access | Required for the queue worker and CLI commands |

---

## Installation

### 1. Get the code

```bash
git clone https://github.com/your-org/canticle /var/www/canticle
cd /var/www/canticle
chmod -R 750 storage/
chown -R www-data:www-data /var/www/canticle
```

### 2. Create the database

```sql
CREATE DATABASE canticle CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'canticle'@'localhost' IDENTIFIED BY 'use-a-strong-password';
GRANT ALL PRIVILEGES ON canticle.* TO 'canticle'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Configure Apache2

Enable required modules:
```bash
sudo a2enmod rewrite headers ssl proxy_fcgi setenvif
sudo a2enconf php8.2-fpm        # adjust to your PHP-FPM version
```

Copy and edit the vhost config:
```bash
sudo cp apache2.conf.example /etc/apache2/sites-available/canticle.conf
sudo nano /etc/apache2/sites-available/canticle.conf   # set your domain
sudo a2ensite canticle
sudo systemctl reload apache2
```

### 4. Get a TLS certificate

```bash
sudo certbot --apache -d social.example.com
```

### 5. Run the web installer

Open `https://social.example.com/install.php` and follow the steps. After installation **delete `install.php`**:

```bash
rm /var/www/canticle/install.php
```

### 6. Start the queue worker

The queue worker delivers outgoing ActivityPub activities. Use the systemd service (recommended):

```bash
sudo cp canticle-worker.service /etc/systemd/system/
# Edit the file to match your actual path and username
sudo systemctl daemon-reload
sudo systemctl enable --now canticle-worker
```

Or add to crontab (`crontab -e` as `www-data`):
```
* * * * * php /var/www/canticle/artisan.php worker --once >> /var/www/canticle/storage/logs/worker.log 2>&1
```

### 7. Schedule the content pruner (optional but recommended)

Add to crontab to automatically remove old remote content each night:
```
0 3 * * * php /var/www/canticle/artisan.php prune >> /var/www/canticle/storage/logs/prune.log 2>&1
```

Configure retention limits in **Admin → Retention** or in `config.php`.

---

## Connecting apps

When signing in to a Mastodon-compatible app, enter your instance domain (e.g. `social.example.com`).

| Platform | Recommended apps |
|----------|-----------------|
| iOS | Ivory, Toot!, Mona, Ice Cubes |
| macOS | Ivory, Mona |
| Android | Tusky, Megalodon |
| Windows / Linux | Whalebird |
| Web | Elk, Phanpy, Pinafore |

---

## Updating

### Git pull (recommended)

```bash
cd /var/www/canticle
git pull origin main
php artisan.php migrate
```

The Admin → Upgrades panel can also run a git pull and apply migrations from the browser.

### After any update

If you added new PHP files, flush OPcache so PHP picks them up:
```bash
php artisan.php migrate          # apply any new migrations
# or from the browser: Admin → Upgrades → Flush OPcache
```

---

## Database migrations

Migrations are numbered SQL files in `migrations/` and are applied in order. Each file runs exactly once (tracked in the `migrations` table).

| File | What it adds |
|------|-------------|
| `001_initial.sql` | Full base schema — users, statuses, follows, media, OAuth, queue, etc. |
| `002_relays.sql` | Relay subscription table |
| `003_remote_content_retention.sql` | Prune log table + default retention settings |
| `004_pinned_posts.sql` | `pinned_at` column on statuses for profile pinning |
| `005_federation_block_reasons.sql` | `public_reason` and `private_reason` columns on instances |

**Versioning convention:** `NNN_short_description.sql` — three-digit zero-padded sequence number, lowercase underscored description. New migrations always get the next number in sequence and must never alter or replace an existing migration file.

To apply all pending migrations:
```bash
php artisan.php migrate
```

---

## CLI reference

```
php artisan.php worker                     # Run queue worker (Ctrl+C to stop)
php artisan.php worker --once              # Process one batch and exit
php artisan.php migrate                    # Apply pending migrations
php artisan.php prune                      # Remove old remote content
php artisan.php prune --status-days=60     # Override status retention for this run
php artisan.php prune --actor-days=120     # Override actor retention for this run
php artisan.php check:actor [username]     # Verify keypair + actor endpoint (debug 401s)
php artisan.php regenerate:keys <username> # Generate a fresh RSA keypair for a user
```

---

## Content retention

Canticle stores posts and actor profiles from remote federated instances in your local database. Without pruning, this grows indefinitely. The pruner removes old remote content while protecting anything your local users have interacted with.

**What is always kept:**
- All posts by your local users
- Remote posts that a local user has favourited
- Remote posts that a local user has replied to
- Remote posts that a local user has boosted
- Remote actor profiles that any local user follows

**What can be pruned:**
- Remote statuses (posts from other servers) older than `remote_status_max_days`
- Cached remote actor profiles with no recent posts and no local followers, older than `remote_actor_max_days`
- Local media files attached to pruned remote statuses

**Configure in the admin panel:** Admin → Retention

**Or in `config.php`:**
```php
'remote_status_max_days' => 90,   // 0 = keep forever
'remote_actor_max_days'  => 180,  // 0 = keep forever
```

---

## Federation

Canticle implements [ActivityPub](https://www.w3.org/TR/activitypub/) and [WebFinger](https://datatracker.ietf.org/doc/html/rfc7033). All outgoing activities are signed with HTTP Signatures; all incoming activities have their signatures verified before processing.

### Relay subscriptions

Relays push public federated content to your instance. Add them at **Admin → Relays**.

Popular relays:
- `https://relay.fedi.buzz`
- `https://relay.mastodon.world`
- `https://relay.joinmastodon.org`

### Defederate an instance

**Admin → Federation → Block domain.** Blocked instances cannot deliver to your inbox.

### Silence an instance

**Admin → Federation → Silence domain.** Posts from silenced instances are hidden from the public timeline but federation still functions.

---

## AI alt text

Automatically generates accessibility descriptions for uploaded images.

Configure at **Admin → Settings → AI Alt Text**:

| Provider | Setting |
|----------|---------|
| Claude (Anthropic) | `alttext_provider = claude`, API key from console.anthropic.com |
| OpenAI | `alttext_provider = openai`, API key |
| Ollama (local) | `alttext_provider = ollama`, endpoint e.g. `http://localhost:11434` |

Leave `alttext_provider` blank to disable.

---

## Directory structure

```
canticle/
├── index.php               Front controller — all HTTP requests enter here
├── bootstrap.php           Autoloader, database connection, config, helpers
├── artisan.php             CLI — queue worker, migrations, content pruner
├── install.php             Web installer (delete after install)
├── update.php              Web/CLI updater
├── config.php              Your instance config (generated by installer)
├── config.example.php      Reference config with all documented options
├── apache2.conf.example    Apache2 VirtualHost template
├── canticle-worker.service systemd unit file for the queue worker
├── .htaccess               URL rewriting and security rules
│
├── core/                   Framework core
│   ├── Auth.php            Session + Bearer token authentication
│   ├── Database.php        PDO wrapper with fetch/insert/update/delete helpers
│   ├── Federator.php       Builds and enqueues outgoing AP activities
│   ├── HttpClient.php      cURL wrapper — fetches AP documents, posts activities
│   ├── HttpSignature.php   Signs and verifies HTTP Signatures (RFC 9421 draft)
│   ├── Pruner.php          Remote content pruning engine
│   ├── Queue.php           DB-backed job queue
│   ├── Request.php         HTTP request wrapper
│   ├── Response.php        HTTP response helpers (json, html, redirect)
│   ├── Router.php          Lightweight path + method router
│   └── Session.php         PHP session wrapper with CSRF support
│
├── models/                 Database models
│   ├── Block.php
│   ├── Follow.php
│   ├── Hashtag.php
│   ├── Media.php
│   ├── Mention.php
│   ├── Mute.php
│   ├── Notification.php
│   ├── OAuthToken.php
│   ├── Poll.php
│   ├── RemoteActor.php     Remote user profiles and outbox import
│   ├── Status.php          Posts — local and federated
│   └── User.php            Local users with AP keypair generation
│
├── handlers/
│   ├── activitypub/        WebFinger, NodeInfo, Actor, Inbox
│   ├── api/                Mastodon API v1/v2
│   ├── web/                Web UI (timeline, profiles, auth, settings)
│   └── admin/              Admin panel
│
├── services/
│   └── AltTextService.php  AI alt text generation (Claude / OpenAI / Ollama)
│
├── templates/
│   ├── web/                Web UI templates
│   └── admin/              Admin panel templates
│
├── migrations/             SQL migration files (applied in numeric order)
│
└── storage/
    ├── media/              Uploaded media files (avatars, attachments)
    │   ├── avatars/
    │   ├── headers/
    │   └── attachments/
    ├── cache/              File cache
    └── logs/               Worker and prune logs
```

---

## Security

- Delete `install.php` immediately after installation — it contains no auth
- `HTTPS` is required — HTTP Signature verification rejects plain-HTTP requests
- All CSRF-sensitive forms use a double-submit token stored in the session
- API endpoints validate Bearer tokens against the `oauth_tokens` table
- The `storage/` directory is blocked by `.htaccess` (Apache) and the vhost config
- Run PHP-FPM under a low-privilege user (`www-data` or similar)
- ActivityPub HTTP Signatures are verified on every incoming inbox delivery

---

## Contributing

Pull requests welcome. Please:

1. Target the `main` branch
2. Keep PHP at 8.1 compatibility (no 8.2+ syntax)
3. Add a migration if you change the schema (next number in sequence)
4. Test federation with a real instance or [ActivityPub.Academy](https://activitypub.academy/)
5. Run `php artisan.php migrate` before testing locally

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full history of releases and what changed in each.

---

## License

MIT — see [LICENSE](LICENSE) for details.

---

*Pax et Bonum*
