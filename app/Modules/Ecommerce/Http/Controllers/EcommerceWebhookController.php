<?php

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Concerns\FlushesWebhookResponse;
use App\Http\Controllers\Controller;
use App\Modules\Ecommerce\Jobs\ProcessEcommerceWebhookJob;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Services\WebhookIdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EcommerceWebhookController extends Controller
{
    use FlushesWebhookResponse;

    public function shopify(Request $request, EcommerceStore $store): JsonResponse
    {
        $this->verifyToken($request, $store);

        // Optional HMAC layer — only when the merchant supplied the app's API secret key.
        $secret = $store->credentials['api_secret_key'] ?? null;
        if ($secret) {
            $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
            if (! hash_equals($expected, $request->header('X-Shopify-Hmac-Sha256', ''))) {
                Log::warning('ecommerce.webhook.shopify.signature_mismatch', ['store' => $store->id]);
                abort(401, 'Invalid signature');
            }
        }

        $topic = $request->header('X-Shopify-Topic', '');
        $eventId = $request->header('X-Shopify-Webhook-Id') ?: hash('sha256', $request->getContent());

        return $this->ingest($store, 'shopify', $topic, $eventId, $request->all());
    }

    public function woocommerce(Request $request, EcommerceStore $store): JsonResponse
    {
        $this->verifyToken($request, $store);

        // Woo signs deliveries with the secret we set at webhook creation.
        $signature = $request->header('x-wc-webhook-signature');
        if ($signature) {
            $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $store->webhook_secret, true));
            if (! hash_equals($expected, $signature)) {
                Log::warning('ecommerce.webhook.woo.signature_mismatch', ['store' => $store->id]);
                abort(401, 'Invalid signature');
            }
        }

        $topic = $request->header('x-wc-webhook-topic', '');
        $eventId = $request->header('x-wc-webhook-id') ?: hash('sha256', $request->getContent());

        return $this->ingest($store, 'woocommerce', $topic, $eventId, $request->all());
    }

    public function bigcommerce(Request $request, EcommerceStore $store): JsonResponse
    {
        // BigCommerce can't sign payloads, but we set a custom header at hook
        // creation; accept the token from either the URL or that header.
        $token = (string) ($request->query('token') ?: $request->header('X-Webhook-Token', ''));
        if ($store->webhook_secret === null || ! hash_equals($store->webhook_secret, $token)) {
            Log::warning('ecommerce.webhook.invalid_token', ['store' => $store->id, 'ip' => $request->ip()]);
            abort(403);
        }

        // Topic = the scope; the lightweight payload is hydrated in the job.
        $topic = (string) $request->input('scope', '');
        $eventId = (string) ($request->input('hash') ?: hash('sha256', $request->getContent()));

        return $this->ingest($store, 'bigcommerce', $topic, $eventId, $request->all());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ingest(EcommerceStore $store, string $platform, string $topic, string $eventId, array $payload): JsonResponse
    {
        if ($topic === '') {
            return response()->json(['status' => 'ignored']);
        }

        $idempotency = app(WebhookIdempotencyService::class);
        if (! $idempotency->isNewEvent("ecommerce_{$platform}_{$store->id}", $topic.'_'.$eventId)) {
            return response()->json(['status' => 'duplicate']);
        }

        return $this->flushWebhookOkThen(
            fn () => ProcessEcommerceWebhookJob::dispatch($store->id, $topic, $payload)->onQueue('automation')
        );
    }

    private function verifyToken(Request $request, EcommerceStore $store): void
    {
        $token = (string) $request->query('token', '');
        if ($store->webhook_secret === null || ! hash_equals($store->webhook_secret, $token)) {
            Log::warning('ecommerce.webhook.invalid_token', ['store' => $store->id, 'ip' => $request->ip()]);
            abort(403);
        }
    }
}
