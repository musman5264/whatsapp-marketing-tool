<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PollSendTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'whatsapp_web', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test'],
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $ctx['workspace']->id,
            'channel' => 'whatsapp', 'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-'.$ctx['workspace']->id,
            'display_name' => 'WA', 'status' => 'active',
        ]);
        $contact = Contact::factory()->create([
            'workspace_id' => $ctx['workspace']->id, 'phone_e164' => '+15551230000',
        ]);
        $this->conversation = Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    #[Test]
    public function a_poll_message_is_sent_natively_via_waha(): void
    {
        Http::fake(['waha.test/api/sendPoll' => Http::response(['id' => 'poll_x'], 201)]);

        $msg = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out', 'channel' => 'whatsapp', 'type' => 'poll',
            'body' => 'Favourite colour?', 'status' => 'queued',
            'sent_by' => 'automation', 'sent_at' => now(),
            'payload' => ['poll' => ['question' => 'Favourite colour?', 'options' => ['Red', 'Blue'], 'multiple' => false]],
        ]);

        $id = app(WhatsappDriver::class)->send($msg);

        $this->assertSame('poll_x', $id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendPoll')
            && $r['poll']['name'] === 'Favourite colour?'
            && $r['poll']['options'] === ['Red', 'Blue']);
    }
}
