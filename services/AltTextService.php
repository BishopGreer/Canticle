<?php
namespace Canticle\Services;

/**
 * Pluggable AI alt text generation.
 * Supported providers: claude, openai, ollama
 */
class AltTextService
{
    private string $provider;
    private string $apiKey;
    private string $model;
    private string $endpoint;

    public function __construct()
    {
        $this->provider = config('alttext_provider', '');
        $this->apiKey   = config('alttext_api_key', '');
        $this->model    = config('alttext_model', '');
        $this->endpoint = config('alttext_endpoint', '');
    }

    public function generate(string $imagePath, string $mimeType): string
    {
        $imageData = base64_encode(file_get_contents($imagePath));

        return match ($this->provider) {
            'claude' => $this->generateClaude($imageData, $mimeType),
            'openai' => $this->generateOpenAI($imageData, $mimeType),
            'ollama' => $this->generateOllama($imageData, $mimeType),
            default  => '',
        };
    }

    private function generateClaude(string $imageData, string $mimeType): string
    {
        $model = $this->model ?: 'claude-haiku-4-5-20251001';
        $body  = json_encode([
            'model'      => $model,
            'max_tokens' => 300,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'  => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $mimeType,
                            'data'       => $imageData,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Describe this image concisely for use as alt text (1-2 sentences, factual, no "This image shows").',
                    ],
                ],
            ]],
        ]);

        $res = $this->curlPost('https://api.anthropic.com/v1/messages', $body, [
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ]);

        $data = json_decode($res, true);
        return trim($data['content'][0]['text'] ?? '');
    }

    private function generateOpenAI(string $imageData, string $mimeType): string
    {
        $model = $this->model ?: 'gpt-4o-mini';
        $body  = json_encode([
            'model'      => $model,
            'max_tokens' => 300,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => "data:$mimeType;base64,$imageData"]],
                    ['type' => 'text', 'text' => 'Describe this image concisely for alt text (1-2 sentences).'],
                ],
            ]],
        ]);

        $res = $this->curlPost('https://api.openai.com/v1/chat/completions', $body, [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ]);

        $data = json_decode($res, true);
        return trim($data['choices'][0]['message']['content'] ?? '');
    }

    private function generateOllama(string $imageData, string $mimeType): string
    {
        $endpoint = rtrim($this->endpoint ?: 'http://localhost:11434', '/');
        $model    = $this->model ?: 'llava';
        $body     = json_encode([
            'model'  => $model,
            'prompt' => 'Describe this image concisely for alt text (1-2 sentences).',
            'images' => [$imageData],
            'stream' => false,
        ]);

        $res  = $this->curlPost("$endpoint/api/generate", $body, ['Content-Type' => 'application/json']);
        $data = json_decode($res, true);
        return trim($data['response'] ?? '');
    }

    private function curlPost(string $url, string $body, array $headers): string
    {
        $ch = curl_init($url);
        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $result = curl_exec($ch);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($err) throw new \RuntimeException("AltText cURL error: $err");
        return $result ?: '';
    }
}
