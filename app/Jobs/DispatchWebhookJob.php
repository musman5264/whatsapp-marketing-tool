<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookMediaLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 5;

    private static array $backoff = [60, 300, 3600, 86400, 86400]; // 1m, 5m, 1h, 1d, 1d

    public function __construct(
        private WebhookEndpoint $endpoint,
        private string $event,
        private array $payload
    ) {}

    /**
     * The optional parameter keeps handle() callable without arguments (WebhookDeliveryBodyTest calls it that
     * way); the queue and the tests inject it through the container.
     */
    public function handle(?WebhookMediaLinks $links = null): void
    {
        $links ??= app(WebhookMediaLinks::class);

        // Resolved on every attempt, so a retry sends a fresh media link. The stored payload below stays unresolved.
        $payloadJson = json_encode(array_merge($links->resolve($this->payload), [
            'event' => $this->event,
            'timestamp' => now()->toIso8601String(),
        ]));

        $signature = $this->endpoint->signature($payloadJson);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Webhook-Event' => $this->event,
            'X-Webhook-Signature' => $signature,
            'User-Agent' => config('app.name') . ' Webhooks/1.0',
        ];

        // Store the exact bytes and headers sent, so the Deliveries page shows what the receiver got.
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $this->endpoint->id,
            'event' => $this->event,
            'payload' => $this->payload,
            'request_body' => $payloadJson,
            'request_headers' => $headers,
            'attempts' => 1,
        ]);

        try {
            // Send the exact bytes that were signed. Passing the string to post() would JSON-encode it a second time.
            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->withBody($payloadJson, 'application/json')
                ->post($this->endpoint->url);

            $delivery->update([
                'response_status' => $response->status(),
                'response_body' => substr($response->body(), 0, 2000),
                'delivered_at' => $response->successful() ? now() : null,
                'attempts' => $this->attempts(),
            ]);

            if (! $response->successful()) {
                $this->scheduleRetry($delivery);
                $this->fail("Webhook returned {$response->status()}");
            }
        } catch (\Throwable $e) {
            Log::warning('Webhook delivery failed', ['endpoint_id' => $this->endpoint->id, 'error' => $e->getMessage()]);
            $delivery->update(['attempts' => $this->attempts()]);
            $this->scheduleRetry($delivery);
            $this->fail($e);
        }
    }

    private function scheduleRetry(WebhookDelivery $delivery): void
    {
        $attempt = $this->attempts() - 1;
        $seconds = self::$backoff[$attempt] ?? self::$backoff[array_key_last(self::$backoff)];
        $delivery->update(['next_retry_at' => now()->addSeconds($seconds)]);
        $this->release($seconds);
    }

    public function backoff(): array
    {
        return self::$backoff;
    }
}
