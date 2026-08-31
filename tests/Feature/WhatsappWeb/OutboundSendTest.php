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

class OutboundSendTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();

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

        $this->conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
        ]);
    }

    private function message(string $type, array $attrs = []): Message
    {
        return Message::create(array_merge([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => $type,
            'body' => 'hello from web',
            'status' => 'queued',
            'sent_by' => 'human',
            'sent_at' => now(),
        ], $attrs));
    }

    #[Test]
    public function text_send_hits_waha_send_text_and_returns_id(): void
    {
        Http::fake([
            'waha.test/api/sendText' => Http::response(['id' => 'true_15559998888@c.us_XYZ'], 201),
        ]);

        $id = app(WhatsappDriver::class)->send($this->message('text'));

        $this->assertSame('true_15559998888@c.us_XYZ', $id);
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/api/sendText')
                && $request['chatId'] === '15559998888@c.us'
                && $request['text'] === 'hello from web';
        });
    }

    #[Test]
    public function media_send_uses_the_link_payload(): void
    {
        Http::fake([
            'waha.test/api/sendImage' => Http::response(['id' => 'img_1'], 201),
        ]);

        $msg = $this->message('image', [
            'body' => 'a photo',
            'payload' => ['link' => 'https://cdn.test/p.jpg', 'caption' => 'a photo'],
        ]);

        $this->assertSame('img_1', app(WhatsappDriver::class)->send($msg));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendImage') && $r['file']['url'] === 'https://cdn.test/p.jpg');
    }

    #[Test]
    public function template_send_degrades_to_plain_text(): void
    {
        // A personal number has no templates — the driver sends the resolved
        // body as plain text instead of failing the run.
        Http::fake(['waha.test/api/sendText' => Http::response(['id' => 't_1'], 201)]);

        $id = app(WhatsappDriver::class)->send($this->message('template', [
            'body' => 'Welcome aboard!',
            'payload' => ['template' => ['name' => 'greeting', 'language' => 'en', 'components' => []]],
        ]));

        $this->assertSame('t_1', $id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && $r['text'] === 'Welcome aboard!');
    }
}
