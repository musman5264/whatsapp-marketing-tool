<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Personal numbers (WhatsApp Web / WAHA) have no templates or interactive
 * messages — those must degrade to a plain-text send, not fail the run.
 */
class TemplateDegradeTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'whatsapp_web', 'label' => 'WA Web', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test', 'api_key' => 'k'],
        ]);

        WhatsappWebSession::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'session_name' => 'ws-'.$this->ctx['workspace']->id,
            'engine' => 'waha', 'status' => 'active',
            'phone_e164' => '+10000000000',
            'webhook_token' => str_repeat('a', 48),
        ]);

        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp', 'provider' => 'whatsapp_web',
            'phone_number_id' => 'ws-'.$this->ctx['workspace']->id,
            'display_name' => 'wa', 'status' => 'active',
        ]);
        $contact = Contact::factory()->create([
            'workspace_id' => $this->ctx['workspace']->id, 'phone_e164' => '+15551230000',
        ]);
        $this->conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    private function sentText(): string
    {
        $body = null;
        Http::assertSent(function ($r) use (&$body) {
            if (str_contains($r->url(), '/api/sendText')) {
                $body = $r->data()['text'] ?? null;

                return true;
            }

            return false;
        });

        return (string) $body;
    }

    #[Test]
    public function a_template_message_is_sent_as_plain_text_with_variables_filled(): void
    {
        Http::fake(['*' => Http::response(['id' => 'wamid.X'], 200)]);

        WhatsappTemplate::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'welcome', 'language' => 'en', 'category' => 'MARKETING', 'status' => 'APPROVED',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hi {{1}}, welcome to {{2}}!'],
            ],
        ]);

        $msg = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out', 'channel' => 'whatsapp', 'type' => 'template',
            'body' => null, 'status' => 'queued', 'sent_by' => 'automation', 'sent_at' => now(),
            'payload' => ['template' => [
                'name' => 'welcome', 'language' => 'en',
                'components' => [['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => 'Ali'],
                    ['type' => 'text', 'text' => 'Acme'],
                ]]],
            ]],
        ]);

        $id = app(WhatsappDriver::class)->send($msg);
        $this->assertSame('wamid.X', $id);
        $this->assertSame('Hi Ali, welcome to Acme!', $this->sentText());
    }

    #[Test]
    public function quick_reply_buttons_become_a_numbered_text_list(): void
    {
        Http::fake(['*' => Http::response(['id' => 'wamid.Y'], 200)]);

        $msg = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out', 'channel' => 'whatsapp', 'type' => 'interactive',
            'body' => 'Need help?', 'status' => 'queued', 'sent_by' => 'automation', 'sent_at' => now(),
            'payload' => ['interactive' => [
                'type' => 'button',
                'body' => ['text' => 'Need help?'],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => 'b1', 'title' => 'Support']],
                    ['type' => 'reply', 'reply' => ['id' => 'b2', 'title' => 'Sales']],
                ]],
            ]],
        ]);

        app(WhatsappDriver::class)->send($msg);
        $text = $this->sentText();
        $this->assertStringContainsString('Need help?', $text);
        $this->assertStringContainsString('1. Support', $text);
        $this->assertStringContainsString('2. Sales', $text);
    }

    #[Test]
    public function a_list_message_becomes_a_numbered_menu(): void
    {
        Http::fake(['*' => Http::response(['id' => 'wamid.Z'], 200)]);

        $msg = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out', 'channel' => 'whatsapp', 'type' => 'interactive',
            'body' => 'Pick one', 'status' => 'queued', 'sent_by' => 'automation', 'sent_at' => now(),
            'payload' => ['interactive' => [
                'type' => 'list',
                'body' => ['text' => 'Pick one'],
                'action' => [
                    'button' => 'Menu',
                    'sections' => [['title' => 'Plans', 'rows' => [
                        ['id' => 'r1', 'title' => 'Basic', 'description' => '$9/mo'],
                        ['id' => 'r2', 'title' => 'Pro', 'description' => '$29/mo'],
                    ]]],
                ],
            ]],
        ]);

        app(WhatsappDriver::class)->send($msg);
        $text = $this->sentText();
        $this->assertStringContainsString('1. Basic — $9/mo', $text);
        $this->assertStringContainsString('2. Pro — $29/mo', $text);
        $this->assertStringContainsString('Reply with a number', $text);
    }

    #[Test]
    public function a_template_with_no_stored_record_falls_back_to_the_message_body(): void
    {
        Http::fake(['*' => Http::response(['id' => 'wamid.F'], 200)]);

        $msg = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out', 'channel' => 'whatsapp', 'type' => 'template',
            'body' => 'Your order is ready', 'status' => 'queued', 'sent_by' => 'automation', 'sent_at' => now(),
            'payload' => ['template' => ['name' => 'unknown_tpl', 'language' => 'en', 'components' => []]],
        ]);

        app(WhatsappDriver::class)->send($msg);
        $this->assertSame('Your order is ready', $this->sentText());
    }
}
