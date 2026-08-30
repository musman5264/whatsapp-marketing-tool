<?php

namespace App\Modules\Ecommerce\Services\OAuth;

use App\Modules\Integrations\Services\CredentialResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Builds OAuth authorization URLs and performs token exchange for the three
 * commerce platforms. Mirrors the Social module's OAuthManager. App (client)
 * credentials are resolved system-wide from integration_configs via
 * CredentialResolver::oauth('shopify'|'bigcommerce'). WooCommerce needs no app
 * credentials — it uses its built-in /wc-auth authorize endpoint.
 */
class EcommerceOAuthManager
{
    /** Access scopes requested per platform. */
    public const SHOPIFY_SCOPES = 'read_orders,write_orders,read_customers,read_products,read_checkouts,read_fulfillments,write_fulfillments';

    public const BIGCOMMERCE_SCOPES = 'store_v2_orders store_v2_customers_read_only store_v2_products store_v2_information_read_only store_cart_read_only store_webhooks';

    public function __construct(private readonly CredentialResolver $credentials) {}

    // ── Shopify ──────────────────────────────────────────────────────────────

    public function shopifyAuthUrl(string $shop, string $state, string $callbackUrl): string
    {
        $creds = $this->credentials->oauth('shopify');
        if (! $creds || ! $creds->clientId()) {
            throw new RuntimeException('Shopify OAuth is not configured.');
        }

        return "https://{$shop}/admin/oauth/authorize?".http_build_query([
            'client_id' => $creds->clientId(),
            'scope' => self::SHOPIFY_SCOPES,
            'redirect_uri' => $callbackUrl,
            'state' => $state,
        ]);
    }

    /**
     * Verify the HMAC Shopify appends to OAuth/redirect query params.
     *
     * @param  array<string, mixed>  $query
     */
    public function shopifyVerifyHmac(array $query): bool
    {
        $creds = $this->credentials->oauth('shopify');
        $secret = $creds?->clientSecret();
        $hmac = (string) ($query['hmac'] ?? '');
        if (! $secret || $hmac === '') {
            return false;
        }

        unset($query['hmac'], $query['signature']);
        ksort($query);
        $message = urldecode(http_build_query($query));
        $computed = hash_hmac('sha256', $message, $secret);

        return hash_equals($computed, $hmac);
    }

    public function shopifyExchange(string $shop, string $code): ?string
    {
        $creds = $this->credentials->oauth('shopify');
        if (! $creds) {
            return null;
        }

        $resp = Http::asJson()->timeout(15)->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => $creds->clientId(),
            'client_secret' => $creds->clientSecret(),
            'code' => $code,
        ]);

        return $resp->successful() ? ($resp->json()['access_token'] ?? null) : null;
    }

    // ── WooCommerce ──────────────────────────────────────────────────────────

    public function wooAuthUrl(string $storeUrl, string $userId, string $callbackUrl, string $returnUrl): string
    {
        return rtrim($storeUrl, '/').'/wc-auth/v1/authorize?'.http_build_query([
            'app_name' => config('app.name'),
            'scope' => 'read_write',
            'user_id' => $userId,
            'return_url' => $returnUrl,
            'callback_url' => $callbackUrl,
        ]);
    }

    // ── BigCommerce ──────────────────────────────────────────────────────────

    /**
     * @return array{access_token: string, store_hash: string}|null
     */
    public function bigcommerceExchange(string $code, string $scope, string $context, string $callbackUrl): ?array
    {
        $creds = $this->credentials->oauth('bigcommerce');
        if (! $creds) {
            return null;
        }

        $resp = Http::asJson()->timeout(15)->post('https://login.bigcommerce.com/oauth2/token', [
            'client_id' => $creds->clientId(),
            'client_secret' => $creds->clientSecret(),
            'code' => $code,
            'scope' => $scope,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $callbackUrl,
            'context' => $context,
        ]);

        if (! $resp->successful() || empty($resp->json()['access_token'])) {
            return null;
        }

        return [
            'access_token' => $resp->json()['access_token'],
            'store_hash' => str_replace('stores/', '', (string) ($resp->json()['context'] ?? $context)),
        ];
    }
}
