<?php

namespace Tests\Feature\Api\V1;

use App\Models\WebhookEndpoint;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutboundWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/webhooks')->assertStatus(401);
    }

    public function test_wrong_scope_returns_403(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::CONTACTS_READ])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/webhooks')->assertStatus(403);
    }

    public function test_list_webhooks_returns_200(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        WebhookEndpoint::create([
            'user_id' => $user->id,
            'url' => 'https://example.com/hook',
            'events' => ['contact.created'],
            'secret' => WebhookEndpoint::generateSecret(),
            'enabled' => true,
        ]);

        $res = $this->withToken($token)
            ->getJson('/api/v1/webhooks')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
    }

    public function test_register_webhook_returns_201_with_secret(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::WEBHOOKS_WRITE])->plainTextToken;

        $res = $this->withToken($token)
            ->postJson('/api/v1/webhooks', [
                'url' => 'https://example.com/hook',
                'events' => ['contact.created', 'campaign.completed'],
            ])
            ->assertStatus(201);

        $this->assertStringStartsWith('whsec_', $res->json('secret'));
        $this->assertDatabaseHas('webhook_endpoints', ['url' => 'https://example.com/hook', 'user_id' => $user->id]);
    }

    public function test_registering_the_same_url_again_updates_the_one_endpoint_and_gives_a_new_secret(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        $first = $this->withToken($token)->postJson('/api/v1/webhooks', ['url' => 'https://example.com/hook', 'events' => ['message.received']])->assertStatus(201);
        $second = $this->withToken($token)->postJson('/api/v1/webhooks', ['url' => 'https://example.com/hook', 'events' => ['message.sent', 'message.failed']])->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertNotSame($first->json('secret'), $second->json('secret'));
        $this->assertSame(1, WebhookEndpoint::where('user_id', $user->id)->count());
        $row = WebhookEndpoint::find($first->json('id'));
        $this->assertSame($second->json('secret'), $row->secret);
        $this->assertSame(['message.sent', 'message.failed'], $row->events);
    }

    public function test_registering_again_removes_older_duplicates_of_the_same_url(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;
        foreach (range(1, 3) as $_) {
            WebhookEndpoint::create(['user_id' => $user->id, 'url' => 'https://example.com/hook', 'events' => [], 'secret' => WebhookEndpoint::generateSecret(), 'enabled' => true]);
        }
        WebhookEndpoint::create(['user_id' => $user->id, 'url' => 'https://example.com/other', 'events' => [], 'secret' => WebhookEndpoint::generateSecret(), 'enabled' => true]);

        $this->withToken($token)->postJson('/api/v1/webhooks', ['url' => 'https://example.com/hook'])->assertOk();

        $this->assertSame(1, WebhookEndpoint::where('user_id', $user->id)->where('url', 'https://example.com/hook')->count());
        $this->assertSame(1, WebhookEndpoint::where('user_id', $user->id)->where('url', 'https://example.com/other')->count());
    }

    public function test_the_same_url_for_another_user_is_a_separate_endpoint(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        ['user' => $other] = $this->createWorkspaceContext();
        $a = $user->createToken('t', ['*'])->plainTextToken;
        $this->withToken($a)->postJson('/api/v1/webhooks', ['url' => 'https://example.com/hook'])->assertStatus(201);
        $this->app['auth']->forgetGuards();
        $b = $other->createToken('t', ['*'])->plainTextToken;

        $this->withToken($b)->postJson('/api/v1/webhooks', ['url' => 'https://example.com/hook'])->assertStatus(201);

        $this->assertSame(2, WebhookEndpoint::where('url', 'https://example.com/hook')->count());
    }

    public function test_register_webhook_invalid_url_returns_422(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/webhooks', ['url' => 'not-a-url'])
            ->assertStatus(422);
    }

    public function test_register_webhook_invalid_event_returns_422(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/webhooks', [
                'url' => 'https://example.com/hook',
                'events' => ['invalid.event'],
            ])
            ->assertStatus(422);
    }

    public function test_delete_webhook_returns_ok(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        $ep = WebhookEndpoint::create([
            'user_id' => $user->id,
            'url' => 'https://example.com/hook',
            'events' => [],
            'secret' => WebhookEndpoint::generateSecret(),
            'enabled' => true,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/v1/webhooks/{$ep->id}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $ep->id]);
    }

    public function test_delete_other_users_webhook_returns_404(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        ['user' => $otherUser] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        $ep = WebhookEndpoint::create([
            'user_id' => $otherUser->id,
            'url' => 'https://example.com/hook',
            'events' => [],
            'secret' => WebhookEndpoint::generateSecret(),
            'enabled' => true,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/v1/webhooks/{$ep->id}")
            ->assertStatus(404);
    }
}
