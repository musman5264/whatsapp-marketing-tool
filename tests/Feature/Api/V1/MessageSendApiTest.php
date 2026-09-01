<?php

namespace Tests\Feature\Api\V1;

use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class MessageSendApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Replace the WhatsApp channel driver with a mock that records the Message it
     * was handed and returns a canned provider id — lets us assert account
     * resolution + row creation without faking Meta / WAHA HTTP.
     *
     * @return object{message: ?Message}  holder whose ->message is set on send()
     */
    private function fakeWhatsappDriver(string $providerId = 'wamid.TEST'): object
    {
        $holder = new class
        {
            public ?Message $message = null;
        };

        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')
            ->andReturnUsing(function (Message $message) use ($holder, $providerId) {
                $holder->message = $message;

                return $providerId;
            });

        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->with('whatsapp')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);

        return $holder;
    }

    private function cloudAccount(int $workspaceId, array $attrs = []): ChannelAccount
    {
        return ChannelAccount::create(array_merge([
            'workspace_id' => $workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'status' => 'active',
            'display_name' => 'Cloud number',
            'phone_number_id' => '111222333',
        ], $attrs));
    }

    private function wahaAccount(int $workspaceId, array $attrs = []): ChannelAccount
    {
        return ChannelAccount::create(array_merge([
            'workspace_id' => $workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'status' => 'active',
            'display_name' => 'WhatsApp (personal)',
            'phone_number_id' => 'wa-web-'.$workspaceId,
        ], $attrs));
    }

    private function whatsappContact(int $workspaceId): Contact
    {
        return Contact::factory()->create([
            'workspace_id' => $workspaceId,
            'phone_e164' => '+8801700000009',
        ]);
    }

    /**
     * Open the Cloud API 24-hour window for a contact by recording a recent
     * inbound WhatsApp message on the given conversation.
     */
    private function openWindow(Conversation $conversation): void
    {
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'hi',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    // ─── WhatsApp: account resolution & row creation ──────────────────────────

    public function test_whatsapp_send_uses_cloud_account_and_creates_message_row(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->whatsappContact($ws->id);
        $cloud = $this->cloudAccount($ws->id);
        $conversation = Conversation::create([
            'workspace_id' => $ws->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $cloud->id,
            'status' => 'open',
        ]);
        $this->openWindow($conversation);
        $holder = $this->fakeWhatsappDriver('wamid.CLOUD');

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'body' => 'Hello from API',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('provider_message_id', 'wamid.CLOUD');

        $this->assertNotNull($holder->message);
        $this->assertSame('text', $holder->message->type);
        $this->assertSame($cloud->id, $holder->message->conversation->channel_account_id);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'status' => 'sent',
            'provider_message_id' => 'wamid.CLOUD',
        ]);
    }

    public function test_whatsapp_send_falls_back_to_waha_when_no_cloud_account(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->whatsappContact($ws->id);
        $waha = $this->wahaAccount($ws->id);
        $holder = $this->fakeWhatsappDriver('wamid.WAHA');

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'body' => 'Hi via personal number',
            ])
            ->assertOk()
            ->assertJsonPath('provider_message_id', 'wamid.WAHA');

        $conversation = Conversation::where('workspace_id', $ws->id)
            ->where('contact_id', $contact->id)
            ->first();
        $this->assertSame($waha->id, $conversation->channel_account_id);
    }

    public function test_whatsapp_send_prefers_cloud_over_waha_when_both_exist(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->whatsappContact($ws->id);
        $waha = $this->wahaAccount($ws->id);
        $cloud = $this->cloudAccount($ws->id);
        $this->fakeWhatsappDriver();

        // No existing conversation, no open window — use a template (window-exempt)
        // so resolution, not the 24h guard, decides the account.
        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'template_name' => 'welcome',
            ])
            ->assertOk();

        $conversation = Conversation::where('contact_id', $contact->id)->first();
        $this->assertSame($cloud->id, $conversation->channel_account_id);
    }

    public function test_whatsapp_send_honours_explicit_channel_account_id(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->whatsappContact($ws->id);
        $this->cloudAccount($ws->id);
        $waha = $this->wahaAccount($ws->id);
        $this->fakeWhatsappDriver();

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'body' => 'Force WAHA',
                'channel_account_id' => $waha->id,
            ])
            ->assertOk();

        $conversation = Conversation::where('contact_id', $contact->id)->first();
        $this->assertSame($waha->id, $conversation->channel_account_id);
    }

    public function test_whatsapp_send_unknown_channel_account_id_returns_422(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->whatsappContact($ws->id);
        $this->cloudAccount($ws->id);
        $this->fakeWhatsappDriver();

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'body' => 'x',
                'channel_account_id' => 999999,
            ])
            ->assertStatus(422);
    }

    public function test_whatsapp_send_reuses_existing_conversation_account(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->whatsappContact($ws->id);
        $cloud = $this->cloudAccount($ws->id);
        $waha = $this->wahaAccount($ws->id);

        // Existing thread is on WAHA — even though cloud would otherwise win.
        $existing = Conversation::create([
            'workspace_id' => $ws->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $waha->id,
            'status' => 'open',
        ]);
        $this->fakeWhatsappDriver();

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'body' => 'reply on existing thread',
            ])
            ->assertOk();

        $this->assertSame(1, Conversation::where('contact_id', $contact->id)->count());
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $existing->id,
            'direction' => 'out',
        ]);
    }

    public function test_template_send_on_waha_reports_degraded_to_text(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->whatsappContact($ws->id);
        $this->wahaAccount($ws->id);
        $holder = $this->fakeWhatsappDriver();

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'template_name' => 'order_update',
                'template_vars' => ['12345', 'shipped'],
            ])
            ->assertOk()
            ->assertJsonPath('degraded_to_text', true);

        $this->assertSame('template', $holder->message->type);
        $this->assertSame('order_update', $holder->message->payload['template']['name']);
    }

    public function test_template_send_on_cloud_has_no_degraded_flag(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->whatsappContact($ws->id);
        $this->cloudAccount($ws->id);
        $this->fakeWhatsappDriver();

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'template_name' => 'order_update',
                'template_vars' => ['12345'],
            ])
            ->assertOk()
            ->assertJsonMissingPath('degraded_to_text');
    }

    public function test_whatsapp_send_failure_marks_message_failed_and_returns_422(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->whatsappContact($ws->id);
        $cloud = $this->cloudAccount($ws->id);
        $conversation = Conversation::create([
            'workspace_id' => $ws->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $cloud->id,
            'status' => 'open',
        ]);
        $this->openWindow($conversation);

        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andThrow(new \RuntimeException('WhatsApp send failed: boom'));
        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->with('whatsapp')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'body' => 'will fail',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'WhatsApp send failed: boom');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'status' => 'failed',
        ]);
    }

    public function test_cloud_send_blocked_when_24h_window_closed_and_not_template(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = $this->whatsappContact($ws->id);
        $cloud = $this->cloudAccount($ws->id);

        // Existing cloud conversation, no recent inbound -> window closed.
        Conversation::create([
            'workspace_id' => $ws->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $cloud->id,
            'status' => 'open',
        ]);
        $this->fakeWhatsappDriver();

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'body' => 'outside window',
            ])
            ->assertStatus(422)
            ->assertJsonPath('window_closed', true);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/v1/messages/send', [])->assertStatus(401);
    }

    public function test_wrong_scope_returns_403(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::CONTACTS_READ])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', ['contact_id' => 1, 'channel' => 'whatsapp'])
            ->assertStatus(403);
    }

    public function test_missing_contact_returns_422_via_validation(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        // Missing required fields
        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [])
            ->assertStatus(422);
    }

    public function test_contact_not_found_returns_404(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => 9999,
                'channel' => 'whatsapp',
                'body' => 'Hello',
            ])
            ->assertStatus(404);
    }

    public function test_no_active_channel_returns_422(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone_e164' => '+8801700000001']);

        // No channel account configured — should return 422
        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'whatsapp',
                'body' => 'Hello',
            ])
            ->assertStatus(422);
    }

    public function test_sms_send_happy_path(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone_e164' => '+8801700000002']);

        // Fake SMS driver HTTP calls
        Http::fake(['*' => Http::response(['msgid' => 'test-msg-id', 'status' => 'sent'], 200)]);

        // Provision a fake SMS config
        SmsProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'twilio',
            'credentials' => ['account_sid' => 'ACtest', 'auth_token' => 'token', 'from_number' => '+1234567890'],
            'default' => true,
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/messages/send', [
                'contact_id' => $contact->id,
                'channel' => 'sms',
                'body' => 'Test SMS',
            ])
            ->assertStatus(200)
            ->assertJsonStructure(['provider_message_id', 'status']);
    }
}
