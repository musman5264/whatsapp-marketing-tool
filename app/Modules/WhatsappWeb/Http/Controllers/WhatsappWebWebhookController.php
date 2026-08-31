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

        // Event-level dedup. WAHA re-sends the same event several times (and the
        // WEBJS engine emits related events for one message); the atomic
        // insertOrIgnore means only the first caller proceeds.
        if ($eventId !== null
            && ! app(WebhookIdempotencyService::class)->isNewEvent('whatsapp_web', $eventId)) {
            return response()->json(['status' => 'ok']);
        }

        // Respond to WAHA immediately, THEN process — a slow response makes WAHA
        // time out and retry the webhook, which was causing duplicate messages
        // and duplicate automation runs. afterResponse() runs in this same PHP
        // process once the 200 is flushed, so no queue worker is needed.
        $sessionId = $session->id;
        $sessionName = $session->session_name;
        dispatch(function () use ($payload, $sessionId, $sessionName, $event) {
            $session = WhatsappWebSession::find($sessionId)
                ?? WhatsappWebSession::where('session_name', $sessionName)->first();
            if (! $session) {
                return;
            }
            try {
                app(WahaEventProcessor::class)->process($payload, $session);
            } catch (\Throwable $e) {
                Log::error('whatsapp_web.webhook.process_failed', [
                    'session' => $sessionName,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
                // Requeue for the cron drain to retry, but release the dedup key
                // first so the retry isn't discarded as a duplicate.
                app(WebhookIdempotencyService::class)->release('whatsapp_web', $this->eventKey($payload) ?? '');
                try {
                    ProcessWahaEventJob::dispatch($payload, $sessionId, $sessionName)->onQueue('whatsapp');
                } catch (\Throwable) {
                }
            }
        })->afterResponse();

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
