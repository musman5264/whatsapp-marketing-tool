<?php

namespace App\Modules\WhatsappWeb\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\WhatsappWeb\Jobs\ProcessWahaEventJob;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\WahaEventProcessor;
use App\Services\WebhookIdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/whatsapp-web/{token} — inbound events from the WAHA engine.
 * Public route; authenticity is the per-session token plus an optional HMAC
 * signature (WAHA `X-Webhook-Hmac`, keyed by the configured webhook secret).
 */
class WhatsappWebWebhookController extends Controller
{


    public function receive(Request $request, string $token): JsonResponse
    {
        $session = WhatsappWebSession::findByWebhookToken($token);
        if (! $session) {
            Log::warning('whatsapp_web.webhook.unknown_token', [
                'ip' => $request->ip(),
                'token_hash' => hash('sha256', $token),
            ]);
            abort(403, 'Invalid webhook token');
        }

        // Authenticity is primarily the 48-char random token in the URL path
        // (unguessable, per-session). If a webhook secret is configured AND the
        // engine actually sent a signature, verify it; otherwise fall through —
        // not every engine/build supports webhook HMAC, and rejecting unsigned
        // requests would silently drop all inbound messages.
        $secret = CredentialResolver::system()->whatsappWeb()?->webhookSecret();
        $signature = (string) $request->header('X-Webhook-Hmac', '');
        if ($secret && $signature !== '') {
            $this->verifyHmac($request, $secret, $signature);
        } elseif ($secret) {
            Log::info('whatsapp_web.webhook.unsigned_but_token_ok', ['session' => $session->session_name]);
        }

        $payload = $request->all();
        $event = (string) ($payload['event'] ?? '');
        $eventId = $this->eventKey($payload);

        if ($eventId !== null
            && ! app(WebhookIdempotencyService::class)->isNewEvent('whatsapp_web', $eventId)) {
            return response()->json(['status' => 'ok']);
        }

        // Process INLINE so the message hits the inbox in ~1s — shared hosting
        // has no persistent queue worker, and WAHA tolerates a 1-2s response.
        // If anything throws, queue it for the cron drain to retry.
        try {
            app(WahaEventProcessor::class)->process($payload, $session);
        } catch (\Throwable $e) {
            Log::error('whatsapp_web.webhook.inline_failed_queued', [
                'session' => $session->session_name,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
            try {
                ProcessWahaEventJob::dispatch($payload, $session->id, $session->session_name)->onQueue('whatsapp');
            } catch (\Throwable) {
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /** @param array<string,mixed> $payload */
    private function eventKey(array $payload): ?string
    {
        $event = (string) ($payload['event'] ?? '');
        $p = $payload['payload'] ?? [];

        $id = $p['id']['_serialized'] ?? $p['id'] ?? null;
        if (is_string($id) && $id !== '') {
            // ack transitions must each be processed, so fold the ack level in.
            $suffix = $event === 'message.ack' ? ':'.($p['ack'] ?? '') : '';

            return $event.':'.$id.$suffix;
        }

        if ($event === 'session.status') {
            return $event.':'.($p['status'] ?? '').':'.($payload['session'] ?? '');
        }

        return null; // fail-open: let the job decide
    }

    private function verifyHmac(Request $request, string $secret, string $received): void
    {
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $received)) {
            Log::warning('whatsapp_web.webhook.signature_mismatch', [
                'ip' => $request->ip(),
                'received' => substr($received, 0, 16).'…',
            ]);
            abort(401, 'Invalid signature');
        }
    }
}
