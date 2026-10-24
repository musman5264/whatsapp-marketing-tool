<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutboundWebhookApiController extends WorkspaceScopedController
{
    private const ALLOWED_EVENTS = [
        'contact.created',
        'contact.updated',
        'message.received',
        'message.sent',
        'message.failed',
        'campaign.completed',
        'automation.run.completed',
    ];

    /**
     * GET /api/v1/webhooks
     */
    public function index(Request $request): JsonResponse
    {
        $endpoints = WebhookEndpoint::where('user_id', $request->user()->id)
            ->latest('id')
            ->get()
            ->map(fn ($ep) => $this->format($ep));

        return response()->json(['data' => $endpoints]);
    }

    /**
     * POST /api/v1/webhooks
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'in:'.implode(',', self::ALLOWED_EVENTS)],
            'description' => ['nullable', 'string', 'max:200'],
        ]);

        // One endpoint per URL: registering the same URL again must not add a second copy, because every
        // copy gets every event and a receiver that only knows the newest secret rejects the others as "bad signature".
        $secret = WebhookEndpoint::generateSecret();
        $attributes = [
            'events' => $validated['events'] ?? [],
            'secret' => $secret,
            'enabled' => true,
        ];
        $same = WebhookEndpoint::where('user_id', $request->user()->id)->where('url', $validated['url'])->orderBy('id')->get();
        $endpoint = $same->first();

        if ($endpoint) {
            $endpoint->update($attributes + ['description' => $validated['description'] ?? $endpoint->description]);
            $same->skip(1)->each->delete(); // duplicates left by earlier registrations
        } else {
            $endpoint = WebhookEndpoint::create($attributes + [
                'user_id' => $request->user()->id,
                'url' => $validated['url'],
                'description' => $validated['description'] ?? null,
            ]);
        }

        return response()->json(array_merge($this->format($endpoint), ['secret' => $secret]), $endpoint->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * DELETE /api/v1/webhooks/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = WebhookEndpoint::where('user_id', $request->user()->id)->where('id', $id)->delete();

        if (! $deleted) {
            return response()->json(['error' => 'Webhook endpoint not found.'], 404);
        }

        return response()->json(['ok' => true]);
    }

    private function format(WebhookEndpoint $ep): array
    {
        return [
            'id' => $ep->id,
            'url' => $ep->url,
            'events' => $ep->events ?? [],
            'enabled' => $ep->enabled,
            'description' => $ep->description,
            'created_at' => $ep->created_at->toIso8601String(),
        ];
    }
}
