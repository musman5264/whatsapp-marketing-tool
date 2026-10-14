<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Models\WhatsappWebLidMapping;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `php artisan whatsapp-web:lid-map` records a LID's phone number and moves the messages that were
 * stored under the LID digits into the real contact's conversation. Without --apply it changes nothing.
 */
class LidMappingRepairTest extends TestCase
{
    use RefreshDatabase;

    private int $workspaceId;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $ctx = $this->createWorkspaceContext();
        $this->workspaceId = $ctx['workspace']->id;

        WhatsappWebSession::create([
            'workspace_id' => $this->workspaceId,
            'session_name' => 'ws-repair',
            'engine' => 'waha',
            'status' => 'active',
            'webhook_token' => Str::random(48),
        ]);

        $this->account = ChannelAccount::create([
            'workspace_id' => $this->workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-repair',
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);
    }

    private function contact(string $phone): Contact
    {
        return Contact::factory()->create(['workspace_id' => $this->workspaceId, 'phone_e164' => $phone]);
    }

    private function conversation(Contact $contact): Conversation
    {
        return Conversation::create([
            'workspace_id' => $this->workspaceId,
            'channel_account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }

    private function inbound(Conversation $conversation, string $providerId): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'Ok',
            'payload' => [],
            'provider_message_id' => $providerId,
            'sent_at' => now(),
        ]);
    }

    #[Test]
    public function dry_run_changes_nothing(): void
    {
        $placeholder = $this->contact('+120297755815945');
        $real = $this->contact('+923345266444');
        $stray = $this->inbound($this->conversation($placeholder), 'false_120297755815945@lid_ok');
        $realConversation = $this->conversation($real);

        $this->artisan('whatsapp-web:lid-map 120297755815945 +923345266444 --session=ws-repair')
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();

        $this->assertSame($stray->conversation_id, $stray->fresh()->conversation_id);
        $this->assertNotSame($realConversation->id, $stray->fresh()->conversation_id);
        $this->assertDatabaseMissing('whatsapp_web_lid_mappings', ['lid' => '120297755815945']);
    }

    #[Test]
    public function apply_moves_messages_into_the_real_conversation_and_records_the_mapping(): void
    {
        $placeholder = $this->contact('+120297755815945');
        $real = $this->contact('+923345266444');
        $stray = $this->inbound($this->conversation($placeholder), 'false_120297755815945@lid_ok');
        $realConversation = $this->conversation($real);

        $this->artisan('whatsapp-web:lid-map 120297755815945 +923345266444 --session=ws-repair --apply')
            ->assertSuccessful();

        $this->assertSame($realConversation->id, $stray->fresh()->conversation_id);
        $this->assertSame($real->id, $stray->fresh()->conversation->contact_id);
        $this->assertNotNull(Contact::find($placeholder->id), 'the stray contact is kept, not deleted');
        $this->assertDatabaseHas('whatsapp_web_lid_mappings', [
            'session_name' => 'ws-repair',
            'lid' => '120297755815945',
            'phone_e164' => '+923345266444',
        ]);
    }

    #[Test]
    public function apply_moves_the_conversation_when_the_real_contact_has_none(): void
    {
        $placeholder = $this->contact('+120297755815945');
        $real = $this->contact('+923345266444');
        $conversation = $this->conversation($placeholder);
        $stray = $this->inbound($conversation, 'false_120297755815945@lid_ok');

        $this->artisan('whatsapp-web:lid-map 120297755815945 +923345266444 --session=ws-repair --apply')
            ->assertSuccessful();

        $this->assertSame($real->id, $conversation->fresh()->contact_id);
        $this->assertSame($conversation->id, $stray->fresh()->conversation_id);
    }

    #[Test]
    public function an_unknown_session_is_refused(): void
    {
        $this->artisan('whatsapp-web:lid-map 120297755815945 +923345266444 --session=nope --apply')
            ->assertFailed();

        $this->assertDatabaseMissing('whatsapp_web_lid_mappings', ['lid' => '120297755815945']);
    }
}
