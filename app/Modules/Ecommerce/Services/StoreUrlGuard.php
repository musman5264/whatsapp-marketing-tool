<?php

namespace App\Modules\Ecommerce\Services;

/**
 * Validates user-supplied store identifiers before any outbound HTTP request,
 * to prevent SSRF (pointing a "store" at internal/cloud-metadata addresses) and
 * host injection.
 *
 * - Shopify: must be a bare *.myshopify.com host (always public — no IP check needed).
 * - BigCommerce: the store hash must be lowercase alphanumeric (interpolated into a
 *   fixed api.bigcommerce.com path).
 * - WooCommerce: an arbitrary http(s) URL — the host is resolved and rejected if it
 *   maps to a private/loopback/link-local/reserved range.
 */
class StoreUrlGuard
{
    /**
     * @return string|null  An error message if invalid, or null if safe.
     */
    public static function validate(string $platform, string $domain): ?string
    {
        return match ($platform) {
            'shopify' => self::validateShopify($domain),
            'bigcommerce' => self::validateBigCommerce($domain),
            'woocommerce' => self::validateWoo($domain),
            default => 'Unsupported platform.',
        };
    }

    private static function validateShopify(string $domain): ?string
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i', $domain) === 1
            ? null
            : 'Shopify domain must be your store\'s *.myshopify.com address.';
    }

    private static function validateBigCommerce(string $hash): ?string
    {
        return preg_match('/^[a-z0-9]+$/i', $hash) === 1
            ? null
            : 'BigCommerce store hash is invalid (expected the alphanumeric code from your API path).';
    }

    private static function validateWoo(string $url): ?string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return 'Store URL must start with http:// or https://.';
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return 'Store URL is not a valid URL.';
        }

        // Resolve all A/AAAA records; reject if any resolves to a non-public range.
        $ips = self::resolveHost($host);
        if ($ips === []) {
            return 'Store URL host could not be resolved.';
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return 'Store URL must point to a public host (private/internal addresses are blocked).';
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function resolveHost(string $host): array
    {
        // Host given as a literal IP.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $r) {
            if (! empty($r['ip'])) {
                $ips[] = $r['ip'];
            } elseif (! empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        if ($ips === []) {
            $resolved = @gethostbyname($host);
            if ($resolved && $resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        return $ips;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
