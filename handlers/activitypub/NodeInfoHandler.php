<?php
namespace Canticle\Handlers\Activitypub;

use Canticle\Core\{Request, Response};

class NodeInfoHandler
{
    public function wellKnown(Request $req, Response $res): void
    {
        $res->json([
            'links' => [[
                'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
                'href' => BASE_URL . '/nodeinfo/2.1',
            ]],
        ]);
    }

    public function nodeinfo21(Request $req, Response $res): void
    {
        $userCount   = db()->fetch('SELECT COUNT(*) AS cnt FROM users WHERE suspended = 0')['cnt'] ?? 0;
        $statusCount = db()->fetch('SELECT COUNT(*) AS cnt FROM statuses WHERE deleted_at IS NULL AND local_user_id IS NOT NULL')['cnt'] ?? 0;

        $res->json([
            'version' => '2.1',
            'software' => [
                'name'       => 'canticle',
                'version'    => CANTICLE_VERSION,
                'repository' => 'https://github.com/your-org/canticle',
                'homepage'   => BASE_URL,
            ],
            'protocols'  => ['activitypub'],
            'usage'      => [
                'users' => [
                    'total'          => (int) $userCount,
                    'activeMonth'    => (int) $userCount,
                    'activeHalfyear' => (int) $userCount,
                ],
                'localPosts' => (int) $statusCount,
            ],
            'openRegistrations' => config('registrations') === 'open',
            'metadata' => [
                'nodeName'        => config('site_name'),
                'nodeDescription' => config('site_desc'),
            ],
        ]);
    }
}
