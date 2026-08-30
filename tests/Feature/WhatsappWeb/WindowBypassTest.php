<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WindowBypassTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function conversation(string $provider): Conversation
    {
        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => $provider,
            'phone_number_id' => $provider === 'whatsapp_web' ? 'ws-x' : 'PHONE_ID',
            'display_name' => 'wa',
            'status' => 'active',
        ]);

        $contact = Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id]);

        return Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ])->load('channelAccount');
    }

    #[Test]
    public function whatsapp_web_conversations_are_not_template_gated(): void
    {
        $c = $this->conversation('whatsapp_web');

        $this->assertFalse($c->requiresWhatsappTemplateWindow());
        // No inbound at all, yet the window reports open (free-form allowed).
        $this->assertTrue($c->isWhatsappWindowOpen());
    }

    #[Test]
    public function cloud_api_conversations_are_still_gated_without_recent_inbound(): void
    {
        $c = $this->conversation('meta');

        $this->assertTrue($c->requiresWhatsappTemplateWindow());
        $this->assertFalse($c->isWhatsappWindowOpen());
    }
}
