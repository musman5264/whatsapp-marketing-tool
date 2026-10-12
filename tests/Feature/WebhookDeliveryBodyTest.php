<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhookJob;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Each webhook delivery keeps the exact body and headers that were sent, and the
 * Deliveries page shows them (the WhatsMine webhook log).
 */
class WebhookDeliveryBodyTest extends TestCase
{
    use RefreshDatabase;

    private function clientUser(): User
    {
        return User::factory()->create([
            'role'              => 'client',
            'email_verified_at' => now(),
        ]);
    }

    public function test_the_job_stores_the_exact_body_and_headers_it_sent(): void
    {
        Http::fake(['https://example.com/hook' => Http::response('ok', 200)]);

        $user     = $this->clientUser();
        $endpoint = WebhookEndpoint::factory()->create([
            'user_id' => $user->id,
            'url'     => 'https://example.com/hook',
            'secret'  => 'whsec_test_secret',
        ]);

        (new DispatchWebhookJob($endpoint, 'test.ping', ['message' => 'hello']))->handle();

        $sent = Http::recorded()[0][0];
        $sentBody = $sent->body();

        $delivery = WebhookDelivery::query()->latest('id')->firstOrFail();

        $this->assertSame($sentBody, $delivery->request_body);
        $this->assertSame('test.ping', json_decode($delivery->request_body, true)['event']);
        $this->assertSame('test.ping', $delivery->request_headers['X-Webhook-Event']);
        $this->assertStringStartsWith('t=', $delivery->request_headers['X-Webhook-Signature']);
        $this->assertStringNotContainsString('whsec_test_secret', json_encode($delivery->request_headers));
    }

    public function test_the_deliveries_page_passes_the_body_and_response_to_the_page(): void
    {
        $user     = $this->clientUser();
        $endpoint = WebhookEndpoint::factory()->create(['user_id' => $user->id]);

        WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event'               => 'message.received',
            'payload'             => ['from' => '+923001234567'],
            'request_body'        => '{"event":"message.received","data":{"text":"REPLY-TK-0001"}}',
            'request_headers'     => ['X-Webhook-Event' => 'message.received'],
            'response_status'     => 401,
            'response_body'       => 'bad signature',
            'attempts'            => 1,
        ]);

        $this->actingAs($user)
            ->get(route('client.webhooks.deliveries', $endpoint))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/Webhooks/Deliveries')
                ->where('deliveries.data.0.request_body', '{"event":"message.received","data":{"text":"REPLY-TK-0001"}}')
                ->where('deliveries.data.0.request_headers.X-Webhook-Event', 'message.received')
                ->where('deliveries.data.0.response_body', 'bad signature'));
    }
}
