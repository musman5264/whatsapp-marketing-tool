<?php

namespace App\Modules\Integrations\Services\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The only way the app calls a linked system. The host comes from a remote system, so every call
 * resolves DNS, refuses private/loopback/link-local/metadata addresses, pins the connection to the
 * address it checked, follows no redirects, verifies TLS and stops at 5 s.
 */
class SafeHttp
{
    public const DEFAULT_MAX_BYTES = 102400;

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @param  array<string, mixed>|null  $json
     * @param  array<string, string>  $headers
     *
     * @throws ExternalCallException
     */
    public function request(string $method, string $url, string $key, ?array $json = null, int $maxBytes = self::DEFAULT_MAX_BYTES, array $headers = []): Response
    {
        ['host' => $host, 'port' => $port, 'ip' => $ip] = $this->assertSafe($url);

        $options = ['allow_redirects' => false, 'verify' => true];
        if (! filter_var($host, FILTER_VALIDATE_IP) && ! config('external_integrations.allow_private_hosts')) {
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]];
        }

        try {
            $request = Http::withHeaders($headers + [
                'Authorization' => 'Bearer '.$key,
                'Accept' => 'application/json',
                'X-Request-Id' => (string) Str::uuid(),
            ])->timeout(5)->connectTimeout(5)->withOptions($options);

            $response = $json === null ? $request->send($method, $url) : $request->send($method, $url, ['json' => $json]);
        } catch (ConnectionException|RequestException) {
            // The library message can carry the URL; the caller only needs to know it failed.
            throw new ExternalCallException('unreachable', 'The remote system could not be reached.');
        }

        // shortcut: the body is fully read before this check (bounded by the 5 s timeout); stream-abort if memory ever matters.
        if (strlen($response->body()) > $maxBytes) {
            throw new ExternalCallException('too_large', 'The remote system sent a response that is too large.');
        }

        return $response;
    }

    /**
     * @return array{host: string, port: int, ip: string}
     *
     * @throws ExternalCallException
     */
    public function assertSafe(string $url): array
    {
        $dev = (bool) config('external_integrations.allow_private_hosts');
        $parts = parse_url($url) ?: [];
        $scheme = $parts['scheme'] ?? '';
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if ($host === '' || ! in_array($scheme, $dev ? ['http', 'https'] : ['https'], true)) {
            throw new ExternalCallException('unsafe_target', 'The address must be an https URL.');
        }

        $ips = $this->resolver->ips($host);
        if ($ips === []) {
            throw new ExternalCallException('unsafe_target', 'The address could not be resolved.');
        }
        if (! $dev) {
            foreach ($ips as $ip) {
                if (! $this->isPublic($ip)) {
                    throw new ExternalCallException('unsafe_target', 'The address points to a private network.');
                }
            }
        }

        return ['host' => $host, 'port' => $parts['port'] ?? ($scheme === 'https' ? 443 : 80), 'ip' => $ips[0]];
    }

    private function isPublic(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is judged by the IPv4 address inside it.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $ip = inet_ntop(substr($packed, 12));
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
