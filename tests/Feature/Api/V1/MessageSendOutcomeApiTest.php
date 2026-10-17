<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchWebhookJob;
use App\Models\WebhookEndpoint;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Whatsapp\Jobs\SendQueuedWhatsappMessageJob;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * T-0040: the send endpoint reports the final outcome (sent / failed) to the caller:
 * in the reply (wait), on a status endpoint, and through message.sent / message.failed webhooks.
 */
class MessageSendOutcomeApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: string, 2: Contact, 3: \App\Models\Workspace} */
    private function setUpSender(): array
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = Contact::factory()->create(['workspace_id' => $ws->id, 'phone_e164' => '+8801700000009']);
        // WhatsApp Web account: no 24-hour window rule, keeps the setup short.
        ChannelAccount::create([
            'workspace_id' => $ws->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'status' => 'active',
            'display_name' => 'WhatsApp (personal)',
            'phone_number_id' => 'wa-web-'.$ws->id,
        ]);

        return [$user, $token, $contact, $ws];
    }

    private function driverReturns(string $providerId): void
    {
        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andReturn($providerId);
        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->with('whatsapp')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);
    }

    private function driverFails(string $error): void
    {
        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andThrow(new \RuntimeException($error));
        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->with('whatsapp')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);
    }

    // ─── 1. The reply carries the outcome ─────────────────────────────────────

    public function test_send_reply_carries_sent_status_and_provider_message_id(): void
    {
        [, $token, $contact] = $this->setUpSender();
        $this->driverReturns('wamid.OUT1');

        $response = $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'body' => 'hello',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('provider_message_id', 'wamid.OUT1')
            ->assertJsonStructure(['message_id']);
    }

    public function test_send_reply_carries_the_provider_error_when_the_send_failed(): void
    {
        [, $token, $contact] = $this->setUpSender();
        $this->driverFails('WAHA session is not connected');

        $response = $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'body' => 'hello',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'WAHA session is not connected')
            ->assertJsonStructure(['message_id']);
    }

    public function test_wait_zero_returns_queued_without_waiting(): void
    {
        [, $token, $contact] = $this->setUpSender();
        $this->driverReturns('wamid.NOWAIT');

        $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'body' => 'hello', 'wait' => 0,
        ])->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonStructure(['message_id']);
    }

    public function test_reply_is_queued_when_the_worker_has_not_finished_within_the_wait(): void
    {
        [, $token, $contact] = $this->setUpSender();
        Queue::fake(); // the job never runs, as if no worker were running

        $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'body' => 'hello', 'wait' => 1,
        ])->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        Queue::assertPushed(SendQueuedWhatsappMessageJob::class);
    }

    public function test_wait_above_the_maximum_is_rejected(): void
    {
        [, $token, $contact] = $this->setUpSender();

        $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'body' => 'hello', 'wait' => 31,
        ])->assertStatus(422)->assertJsonValidationErrors('wait');
    }

    // ─── 2. Status endpoint ───────────────────────────────────────────────────

    public function test_status_endpoint_returns_sent_message(): void
    {
        [, $token, $contact] = $this->setUpSender();
        $this->driverReturns('wamid.STATUS');
        $id = $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'body' => 'hello',
        ])->json('message_id');

        $this->withToken($token)->getJson("/api/v1/messages/{$id}")
            ->assertOk()
            ->assertJsonPath('message_id', $id)
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('provider_message_id', 'wamid.STATUS')
            ->assertJsonPath('error', null);
    }

    public function test_status_endpoint_returns_failed_message_with_error(): void
    {
        [, $token, $contact] = $this->setUpSender();
        $this->driverFails('boom');
        $id = $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'body' => 'hello',
        ])->json('message_id');

        $this->withToken($token)->getJson("/api/v1/messages/{$id}")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'boom');
    }

    public function test_status_endpoint_hides_another_workspaces_message(): void
    {
        [, $token, $contact] = $this->setUpSender();
        $this->driverReturns('wamid.X');
        $id = $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'body' => 'hello',
        ])->json('message_id');

        ['user' => $other] = $this->createWorkspaceContext();
        $otherToken = $other->createToken('o', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $this->app['auth']->forgetGuards(); // the guard keeps the first request's user within one test

        $this->withToken($otherToken)->getJson("/api/v1/messages/{$id}")->assertStatus(404);
    }

    public function test_status_endpoint_needs_the_messages_scope(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('c', [ApiAbilities::CONTACTS_READ])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/messages/1')->assertStatus(403);
    }

    // ─── 3. Webhooks ──────────────────────────────────────────────────────────

    public function test_message_sent_webhook_is_dispatched_to_the_senders_endpoint(): void
    {
        [$user, $token, $contact] = $this->setUpSender();
        $this->driverReturns('wamid.HOOK');
        WebhookEndpoint::create([
            'user_id' => $user->id, 'url' => 'https://client.test/hook',
            'secret' => 'whsec_x', 'events' => ['message.sent'], 'enabled' => true,
        ]);
        Bus::fake([DispatchWebhookJob::class]);

        $id = $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'body' => 'hello',
        ])->json('message_id');

        Bus::assertDispatched(DispatchWebhookJob::class, function (DispatchWebhookJob $job) use ($id) {
            $payload = (fn () => $this->payload)->call($job);
            $event = (fn () => $this->event)->call($job);

            return $event === 'message.sent'
                && $payload['message']['id'] === $id
                && $payload['message']['status'] === 'sent'
                && $payload['message']['provider_message_id'] === 'wamid.HOOK';
        });
    }

    public function test_message_failed_webhook_is_dispatched_with_the_error(): void
    {
        [$user, $token, $contact] = $this->setUpSender();
        $this->driverFails('WAHA down');
        WebhookEndpoint::create([
            'user_id' => $user->id, 'url' => 'https://client.test/hook',
            'secret' => 'whsec_x', 'events' => ['message.failed'], 'enabled' => true,
        ]);
        Bus::fake([DispatchWebhookJob::class]);

        $id = $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'body' => 'hello',
        ])->json('message_id');

        Bus::assertDispatched(DispatchWebhookJob::class, function (DispatchWebhookJob $job) use ($id) {
            $payload = (fn () => $this->payload)->call($job);
            $event = (fn () => $this->event)->call($job);

            return $event === 'message.failed'
                && $payload['message']['id'] === $id
                && $payload['message']['status'] === 'failed'
                && $payload['message']['error'] === 'WAHA down';
        });
    }

    public function test_webhook_endpoint_accepts_the_message_failed_event(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('w', [ApiAbilities::WEBHOOKS_WRITE])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/webhooks', [
            'url' => 'https://client.test/hook', 'events' => ['message.sent', 'message.failed'],
        ])->assertStatus(201);
    }

    public function test_a_failing_webhook_dispatch_does_not_break_the_send(): void
    {
        [$user, $token, $contact] = $this->setUpSender();
        $this->driverReturns('wamid.SAFE');
        WebhookEndpoint::create([
            'user_id' => $user->id, 'url' => 'https://client.test/hook',
            'secret' => 'whsec_x', 'events' => [], 'enabled' => true,
        ]);
        $this->app->bind(\App\Services\WebhookDispatchService::class, function () {
            $svc = Mockery::mock(\App\Services\WebhookDispatchService::class);
            $svc->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue down'));

            return $svc;
        });

        $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'body' => 'hello',
        ])->assertStatus(200)->assertJsonPath('status', 'sent');
    }
}
