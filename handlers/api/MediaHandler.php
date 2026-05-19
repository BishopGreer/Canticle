<?php
namespace Canticle\Handlers\Api;

use Canticle\Core\{Request, Response, Auth};
use Canticle\Models\Media;
use Canticle\Services\AltTextService;

class MediaHandler
{
    /** POST /api/v1/media or /api/v2/media */
    public function upload(Request $req, Response $res): void
    {
        $user = Auth::requireUser($res);
        $file = $req->file('file');
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $res->error('No file uploaded or upload error', 422);
        }

        $maxBytes = (int) config('max_media_mb', 40) * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            $res->error('File too large', 422);
        }

        $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm','audio/mpeg','audio/ogg'];
        $mime    = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $allowed)) {
            $res->error('Unsupported media type', 422);
        }

        // Store file
        $ext      = $this->extensionFromMime($mime);
        $subDir   = date('Y/m');
        $storeDir = config('storage_path') . '/media/' . $subDir;
        if (!is_dir($storeDir)) mkdir($storeDir, 0750, true);

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $filepath = $subDir . '/' . $filename;
        $fullPath = config('storage_path') . '/media/' . $filepath;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            $res->error('Failed to save file', 500);
        }

        // Dimensions
        [$width, $height] = $this->getDimensions($fullPath, $mime);

        // Blurhash (simple fallback if extension not available)
        $blurhash = '';

        // AI alt text
        $description = trim($req->input('description', ''));
        if (!$description && str_starts_with($mime, 'image/') && config('alttext_provider')) {
            try {
                $svc         = new AltTextService();
                $description = $svc->generate($fullPath, $mime);
            } catch (\Throwable $e) {
                error_log('[AltText] ' . $e->getMessage());
            }
        }

        $type = match(true) {
            str_starts_with($mime, 'image/gif')   => 'gifv',
            str_starts_with($mime, 'image/')       => 'image',
            str_starts_with($mime, 'video/')       => 'video',
            str_starts_with($mime, 'audio/')       => 'audio',
            default                                => 'unknown',
        };

        $mediaId = Media::create([
            'local_user_id' => $user['id'],
            'type'          => $type,
            'file_path'     => $filepath,
            'file_name'     => $file['name'],
            'mime_type'     => $mime,
            'file_size'     => $file['size'],
            'width'         => $width,
            'height'        => $height,
            'blurhash'      => $blurhash,
            'description'   => $description,
        ]);

        $media = Media::find($mediaId);
        $res->json(Media::toMastodon($media), 202);
    }

    /** PUT /api/v1/media/:id */
    public function update(Request $req, Response $res): void
    {
        $user  = Auth::requireUser($res);
        $media = Media::find((int) $req->param('id'));
        if (!$media || $media['local_user_id'] !== $user['id']) $res->error('Not found', 404);

        $description = $req->input('description');
        if ($description !== null) {
            db()->update('media', ['description' => $description], 'id = ?', [$media['id']]);
        }

        $res->json(Media::toMastodon(Media::find($media['id'])));
    }

    /** GET /api/v1/media/:id */
    public function show(Request $req, Response $res): void
    {
        $user  = Auth::requireUser($res);
        $media = Media::find((int) $req->param('id'));
        if (!$media || $media['local_user_id'] !== $user['id']) $res->error('Not found', 404);
        $res->json(Media::toMastodon($media));
    }

    private function extensionFromMime(string $mime): string
    {
        return match($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'video/mp4'  => 'mp4',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/ogg'  => 'ogg',
            default      => 'bin',
        };
    }

    private function getDimensions(string $path, string $mime): array
    {
        if (str_starts_with($mime, 'image/') && function_exists('getimagesize')) {
            $size = @getimagesize($path);
            if ($size) return [$size[0], $size[1]];
        }
        return [null, null];
    }
}
