<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReactionSendTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function wahaConversation(): Conversation
    {
        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test'],
        ]);

        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-'.$this->ctx['workspace']->id,
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);

        $contact = Contact::factory()->create([
            'workspace_id' => $this->ctx['workspace']->id,
            'phone_e164' => '+15559998888',
        ]);

        return Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    private function cloudConversation(): Conversation
    {
        $waba = WhatsappBusinessAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'waba_id' => '999888777',
            'status' => 'active',
            'credentials' => ['system_user_token' => 'test-token', 'phone_number_id' => 'PHONE_ID'],
        ]);

        WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id,
            'phone_number_id' => 'PHONE_ID',
            'display_phone' => '+15551112222',
        ]);

        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'cloud',
            'phone_number_id' => 'PHONE_ID',
            'display_name' => 'WhatsApp Cloud',
            'status' => 'active',
        ]);

        $contact = Contact::factory()->create([
            'workspace_id' => $this->ctx['workspace']->id,
            'phone_e164' => '+15557778888',
        ]);

        return Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    private function inbound(Conversation $conv, ?string $providerId): Message
    {
        return Message::create([
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'the message being reacted to',
            'status' => 'delivered',
            'provider_message_id' => $providerId,
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    private function reaction(Conversation $conv, int $targetId, string $emoji = '❤️'): Message
    {
        return Message::create([
            'conversation_id' => $conv->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'reaction',
            'body' => $emoji,
            'status' => 'queued',
            'sent_by' => 'human',
            'sent_at' => now(),
            'payload' => ['target_message_id' => $targetId, 'emoji' => $emoji],
        ]);
    }

    #[Test]
    public function a_reaction_is_sent_via_waha_using_the_target_provider_id(): void
    {
        $conv = $this->wahaConversation();
        $inbound = $this->inbound($conv, 'inbound_abc');
        $reaction = $this->reaction($conv, $inbound->id);

        Http::fake(['waha.test/api/reaction' => Http::response([], 200)]);

        $id = app(WhatsappDriver::class)->send($reaction);

        $this->assertSame('', $id);
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/api/reaction')
            && $r['messageId'] === 'inbound_abc'
            && $r['reaction'] === '❤️');
    }

    #[Test]
    public function a_reaction_is_sent_via_cloud_api_with_the_meta_reaction_shape(): void
    {
        $conv = $this->cloudConversation();
        $inbound = $this->inbound($conv, 'inbound_abc');
        $reaction = $this->reaction($conv, $inbound->id);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        $id = app(WhatsappDriver::class)->send($reaction);

        $this->assertSame('wamid.OUT', $id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/messages')
            && data_get($r->data(), 'type') === 'reaction'
            && data_get($r->data(), 'reaction.message_id') === 'inbound_abc'
            && data_get($r->data(), 'reaction.emoji') === '❤️');
    }

    #[Test]
    public function a_reaction_targeting_a_message_without_a_provider_id_throws(): void
    {
        $conv = $this->wahaConversation();
        $inbound = $this->inbound($conv, null);
        $reaction = $this->reaction($conv, $inbound->id);

        Http::fake(['waha.test/*' => Http::response([], 200)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot react');

        app(WhatsappDriver::class)->send($reaction);
    }
}
