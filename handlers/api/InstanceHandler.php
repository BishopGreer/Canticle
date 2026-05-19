<?php
namespace Canticle\Handlers\Api;

use Canticle\Core\{Request, Response};

class InstanceHandler
{
    public function v1(Request $req, Response $res): void
    {
        $res->json($this->instanceData());
    }

    public function v2(Request $req, Response $res): void
    {
        $data = $this->instanceData();
        // v2 wraps contact_account differently
        $res->json(array_merge($data, [
            'domain'      => config('domain'),
            'source_url'  => 'https://github.com/your-org/canticle',
            'usage'       => ['users' => ['active_month' => 0]],
            'thumbnail'   => ['url' => BASE_URL . '/assets/img/logo.png'],
            'configuration' => $data['configuration'],
        ]));
    }

    public function peers(Request $req, Response $res): void
    {
        $domains = db()->fetchAll("SELECT domain FROM instances WHERE status = 'allowed'");
        $res->json(array_column($domains, 'domain'));
    }

    public function blockedDomains(Request $req, Response $res): void
    {
        // Public defederation list
        $blocked = db()->fetchAll("SELECT domain, block_reason AS comment FROM instances WHERE status = 'blocked'");
        $res->json($blocked);
    }

    public function extendedDescription(Request $req, Response $res): void
    {
        $res->json([
            'updated_at' => date('c'),
            'content'    => config('site_desc', ''),
        ]);
    }

    private function instanceData(): array
    {
        $adminUser = db()->fetch("SELECT * FROM users WHERE role = 'admin' LIMIT 1");
        $contact   = $adminUser ? \Canticle\Models\User::toMastodon($adminUser) : null;

        return [
            'uri'              => config('domain'),
            'title'            => config('site_name'),
            'short_description'=> config('site_desc'),
            'description'      => config('site_desc'),
            'email'            => config('contact_email'),
            'version'          => '4.2.0 (Canticle ' . CANTICLE_VERSION . ')',
            'urls'             => ['streaming_api' => null],
            'stats'            => [
                'user_count'   => (int) (db()->fetch('SELECT COUNT(*) c FROM users WHERE suspended=0')['c'] ?? 0),
                'status_count' => (int) (db()->fetch('SELECT COUNT(*) c FROM statuses WHERE deleted_at IS NULL AND local_user_id IS NOT NULL')['c'] ?? 0),
                'domain_count' => (int) (db()->fetch('SELECT COUNT(*) c FROM instances')['c'] ?? 0),
            ],
            'languages'        => [config('site_lang', 'en')],
            'contact_account'  => $contact,
            'rules'            => [],
            'registrations'    => config('registrations') === 'open',
            'approval_required'=> config('registrations') === 'approval',
            'invites_enabled'  => false,
            'configuration'    => [
                'accounts'     => ['max_featured_tags' => 10],
                'statuses'     => [
                    'max_characters'              => (int) config('max_chars', 500),
                    'max_media_attachments'       => (int) config('max_media', 4),
                    'characters_reserved_per_url' => 23,
                ],
                'media_attachments' => [
                    'supported_mime_types'  => ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm'],
                    'image_size_limit'      => (int) config('max_media_mb', 40) * 1024 * 1024,
                    'image_matrix_limit'    => 16777216,
                    'video_size_limit'      => (int) config('max_media_mb', 40) * 1024 * 1024,
                    'video_frame_rate_limit'=> 60,
                    'video_matrix_limit'    => 2304000,
                ],
                'polls' => [
                    'max_options'             => (int) config('max_poll_options', 4),
                    'max_characters_per_option'=> 50,
                    'min_expiration'          => 300,
                    'max_expiration'          => 2629746,
                ],
            ],
        ];
    }
}
