<?php
namespace Canticle\Core;

/**
 * HTTP Signatures — draft-cavage-http-signatures-12
 * The version used by Mastodon and most AP implementations.
 */
class HttpSignature
{
    public static function sign(array &$headers, string $url, string $method, string $body, string $keyId, string $privateKeyPem): void
    {
        $method = strtolower($method);
        $parsed = parse_url($url);
        $path   = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        $host   = $parsed['host'];
        $date   = gmdate('D, d M Y H:i:s') . ' GMT';
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));

        $headers['Host']   = $host;
        $headers['Date']   = $date;
        $headers['Digest'] = $digest;

        $sigHeaders = ['(request-target)', 'host', 'date', 'digest'];
        $sigString  = "(request-target): $method $path\nhost: $host\ndate: $date\ndigest: $digest";

        if (!$privateKeyPem) {
            throw new \RuntimeException('HTTP signature: private key is empty');
        }
        $privKey = openssl_pkey_get_private($privateKeyPem);
        if (!$privKey) {
            throw new \RuntimeException('HTTP signature: could not load private key — ' . openssl_error_string());
        }
        if (!openssl_sign($sigString, $signature, $privKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('HTTP signature: openssl_sign failed — ' . openssl_error_string());
        }
        $sig64 = base64_encode($signature);

        $headers['Signature'] = sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $keyId,
            implode(' ', $sigHeaders),
            $sig64
        );
    }

    public static function verify(string $method, string $path, array $requestHeaders, string $publicKeyPem): bool
    {
        $sigHeader = $requestHeaders['signature'] ?? $requestHeaders['Signature'] ?? '';
        if (!$sigHeader) return false;

        $parts = [];
        preg_match_all('/(\w+)="([^"]*)"/', $sigHeader, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $parts[$match[1]] = $match[2];
        }

        $headerNames = explode(' ', $parts['headers'] ?? '');
        $lines = [];
        foreach ($headerNames as $h) {
            if ($h === '(request-target)') {
                $lines[] = "(request-target): " . strtolower($method) . " $path";
            } else {
                $val = $requestHeaders[strtolower($h)] ?? $requestHeaders[$h] ?? '';
                $lines[] = strtolower($h) . ": $val";
            }
        }

        $sigString = implode("\n", $lines);
        $signature = base64_decode($parts['signature'] ?? '');
        $pubKey    = openssl_pkey_get_public($publicKeyPem);

        return openssl_verify($sigString, $signature, $pubKey, OPENSSL_ALGO_SHA256) === 1;
    }

    public static function generateKeypair(): array
    {
        $res  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        $publicKey = openssl_pkey_get_details($res)['key'];
        return ['private' => $privateKey, 'public' => $publicKey];
    }
}
