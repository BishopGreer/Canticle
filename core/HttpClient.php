<?php
namespace Canticle\Core;

class HttpClient
{
    private int $timeout;

    public function __construct(int $timeout = 15)
    {
        $this->timeout = $timeout;
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, '', $headers);
    }

    public function postJson(string $url, array $data, array $headers = []): array
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers['Content-Type'] = 'application/json';
        return $this->request('POST', $url, $body, $headers);
    }

    public function postActivity(string $url, array $activity, string $keyId, string $privateKeyPem): array
    {
        $body    = json_encode($activity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/activity+json'];
        HttpSignature::sign($headers, $url, 'POST', $body, $keyId, $privateKeyPem);
        // MUST NOT follow redirects on signed requests: the signature covers the
        // original URL/method/body.  A redirect would change the (request-target)
        // or silently convert POST→GET, making the signature invalid on the new leg.
        return $this->request('POST', $url, $body, $headers, followRedirects: false);
    }

    public function fetchActor(string $url): ?array
    {
        $res = $this->get($url, [
            'Accept' => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
        ]);
        if ($res['status'] !== 200) return null;
        return json_decode($res['body'], true);
    }

    /** Resolve username@domain → actor URI via WebFinger. */
    public function webfinger(string $username, string $domain): ?string
    {
        $url = 'https://' . $domain . '/.well-known/webfinger?resource=' . urlencode("acct:{$username}@{$domain}");
        $res = $this->get($url, ['Accept' => 'application/jrd+json, application/json']);
        if ($res['status'] !== 200) return null;

        $data  = json_decode($res['body'], true);
        $links = $data['links'] ?? [];
        foreach ($links as $link) {
            if (($link['rel'] ?? '') === 'self' &&
                in_array($link['type'] ?? '', ['application/activity+json', 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'])) {
                return $link['href'] ?? null;
            }
        }
        return null;
    }

    private function request(string $method, string $url, string $body, array $headers, bool $followRedirects = true): array
    {
        $ch = curl_init();

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "$k: $v";
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS      => $followRedirects ? 3 : 0,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_USERAGENT      => 'Canticle/' . CANTICLE_VERSION . ' (ActivityPub; +' . BASE_URL . ')',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error        = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[HttpClient] cURL error for $url: $error");
            return ['status' => 0, 'body' => '', 'error' => $error];
        }

        return ['status' => $status, 'body' => $responseBody ?: ''];
    }
}
