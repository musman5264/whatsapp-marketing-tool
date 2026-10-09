<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\Waha\WahaClient;
use App\Modules\WhatsappWeb\Services\Waha\WahaLabelSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WahaLabelSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://waha.test';

    private int $workspaceId;

    private WhatsappWebSession $session;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $ctx = $this->createWorkspaceContext();
        $this->workspaceId = $ctx['workspace']->id;
        $name = 'ws-'.$this->workspaceId;

        $this->session = WhatsappWebSession::create([
            'workspace_id' => $this->workspaceId,
            'session_name' => $name,
            'engine' => 'waha',
            'status' => 'connected',
            'mirror_wa_labels' => true,
        ]);

        $account = ChannelAccount::create([
            'workspace_id' => $this->workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'phone_number_id' => $name,
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'workspace_id' => $this->workspaceId,
            'phone_e164' => '+15551230000',
            'first_name' => 'Sam',
        ]);

        $this->conversation = Conversation::create([
            'workspace_id' => $this->workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }

    private function sync(): WahaLabelSync
    {
        return new WahaLabelSync(new WahaClient(self::BASE, 'k'));
    }

    private function label(string $name, ?string $waId = null): InboxLabel
    {
        return InboxLabel::create([
            'workspace_id' => $this->workspaceId,
            'name' => $name,
            'color' => '#6366f1',
            'wa_label_id' => $waId,
        ]);
    }

    /** @return list<string> ids sent in the last PUT */
    private function putIds(): array
    {
        $ids = [];
        Http::assertSent(function ($request) use (&$ids) {
            if ($request->method() === 'PUT' && str_contains($request->url(), '/labels/chats/')) {
                $ids = array_column($request->data()['labels'], 'id');
            }

            return true;
        });

        return $ids;
    }

    private function chatUrl(): string
    {
        return self::BASE.'/api/'.$this->session->session_name.'/labels/chats/15551230000@c.us/';
    }

    #[Test]
    public function push_keeps_labels_the_user_set_on_the_phone(): void
    {
        $local = $this->label('Pricing', '1');
        $this->conversation->labels()->attach($local->id);

        Http::fake([
            $this->chatUrl() => function ($request) {
                return $request->method() === 'GET'
                    ? Http::response([['id' => '9', 'name' => 'Phone only', 'color' => 3]], 200)
                    : Http::response([], 200);
            },
        ]);

        $this->assertTrue($this->sync()->syncConversationLabels($this->conversation));

        $this->assertEqualsCanonicalizing(['9', '1'], $this->putIds());
    }

    #[Test]
    public function a_label_removed_locally_is_dropped_from_the_chat(): void
    {
        $removed = $this->label('VIP', '2');

        Http::fake([
            $this->chatUrl() => Http::sequence()
                ->push([['id' => '2'], ['id' => '9']], 200)
                ->push([], 200),
        ]);

        $this->assertTrue($this->sync()->syncConversationLabels($this->conversation, $removed));

        $this->assertSame(['9'], $this->putIds());
    }

    #[Test]
    public function no_put_is_sent_when_the_chat_already_matches(): void
    {
        $local = $this->label('Pricing', '1');
        $this->conversation->labels()->attach($local->id);

        Http::fake([$this->chatUrl() => Http::response([['id' => '1']], 200)]);

        $this->assertTrue($this->sync()->syncConversationLabels($this->conversation));

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_4xx_from_the_engine_soft_fails_and_parks_the_feature(): void
    {
        $this->label('Pricing');
        $this->conversation->labels()->attach(InboxLabel::where('workspace_id', $this->workspaceId)->first()->id);

        Http::fake([
            self::BASE.'/api/*' => Http::response(['error' => 'not a business account'], 403),
        ]);

        $this->assertFalse($this->sync()->syncConversationLabels($this->conversation));
        $this->assertTrue(Cache::get('waha_labels_unsupported:'.$this->session->session_name));
        $this->assertFalse($this->session->fresh()->meta_json['labels_supported']);

        $sentBefore = Http::recorded()->count();
        $this->assertFalse($this->sync()->syncConversationLabels($this->conversation));
        $this->assertSame($sentBefore, Http::recorded()->count(), 'parked session must not call the engine again');
    }

    #[Test]
    public function a_connection_exception_never_escapes(): void
    {
        $this->conversation->labels()->attach($this->label('Pricing')->id);

        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->assertFalse($this->sync()->syncConversationLabels($this->conversation));
    }

    #[Test]
    public function mirror_off_makes_no_engine_calls(): void
    {
        $this->session->update(['mirror_wa_labels' => false]);
        $this->conversation->labels()->attach($this->label('Pricing')->id);
        Http::fake();

        $this->assertFalse($this->sync()->syncConversationLabels($this->conversation));
        Http::assertNothingSent();
    }

    #[Test]
    public function ensure_remote_label_matches_an_existing_name_without_creating(): void
    {
        $label = $this->label('pricing');

        Http::fake([
            self::BASE.'/api/'.$this->session->session_name.'/labels' => Http::response([
                ['id' => '7', 'name' => 'Pricing', 'color' => 2],
            ], 200),
        ]);

        $this->assertSame('7', $this->sync()->ensureRemoteLabel($label, $this->session->session_name));
        $this->assertSame('7', $label->fresh()->wa_label_id);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    #[Test]
    public function ensure_remote_label_creates_when_missing(): void
    {
        $label = $this->label('Urgent');

        Http::fake([
            self::BASE.'/api/'.$this->session->session_name.'/labels' => Http::sequence()
                ->push([], 200)
                ->push(['id' => '12', 'name' => 'Urgent'], 201),
        ]);

        $this->assertSame('12', $this->sync()->ensureRemoteLabel($label, $this->session->session_name));
        $this->assertSame('12', $label->fresh()->wa_label_id);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['name'] === 'Urgent');
    }

    #[Test]
    public function chat_added_webhook_attaches_locally_without_echoing_back(): void
    {
        $label = $this->label('Pricing', '5');
        Http::fake();

        $this->sync()->handleWebhook([
            'event' => 'label.chat.added',
            'payload' => ['labelId' => '5', 'chatId' => '15551230000@c.us', 'label' => null],
        ], $this->session->session_name);

        $this->assertTrue($this->conversation->labels()->whereKey($label->id)->exists());
        Http::assertNothingSent();
    }

    #[Test]
    public function chat_deleted_webhook_detaches_locally(): void
    {
        $label = $this->label('Pricing', '5');
        $this->conversation->labels()->attach($label->id);

        $this->sync()->handleWebhook([
            'event' => 'label.chat.deleted',
            'payload' => ['labelId' => '5', 'chatId' => '15551230000@c.us'],
        ], $this->session->session_name);

        $this->assertFalse($this->conversation->labels()->exists());
    }

    #[Test]
    public function label_upsert_imports_an_unknown_label_and_maps_it(): void
    {
        $this->sync()->handleWebhook([
            'event' => 'label.upsert',
            'payload' => ['id' => '42', 'name' => 'Hot lead', 'colorHex' => '#ef4444'],
        ], $this->session->session_name);

        $this->assertDatabaseHas('inbox_labels', [
            'workspace_id' => $this->workspaceId,
            'name' => 'Hot lead',
            'wa_label_id' => '42',
            'color' => '#ef4444',
        ]);
    }

    #[Test]
    public function label_deleted_clears_the_mapping_only(): void
    {
        $label = $this->label('Pricing', '5');

        $this->sync()->handleWebhook([
            'event' => 'label.deleted',
            'payload' => ['id' => '5', 'name' => 'Pricing'],
        ], $this->session->session_name);

        $this->assertNull($label->fresh()->wa_label_id);
        $this->assertTrue(InboxLabel::whereKey($label->id)->exists());
    }

    #[Test]
    public function webhooks_are_ignored_when_mirroring_is_off(): void
    {
        $this->session->update(['mirror_wa_labels' => false]);

        $this->sync()->handleWebhook([
            'event' => 'label.upsert',
            'payload' => ['id' => '42', 'name' => 'Ignored'],
        ], $this->session->session_name);

        $this->assertDatabaseMissing('inbox_labels', ['name' => 'Ignored']);
    }
}
