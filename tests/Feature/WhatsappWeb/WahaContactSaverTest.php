<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Shared\Models\Contact;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\Waha\WahaClient;
use App\Modules\WhatsappWeb\Services\Waha\WahaContactSaver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WahaContactSaverTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://waha.test';

    private int $workspaceId;

    private WhatsappWebSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $ctx = $this->createWorkspaceContext();
        $this->workspaceId = $ctx['workspace']->id;
        $this->session = WhatsappWebSession::create([
            'workspace_id' => $this->workspaceId,
            'session_name' => 'ws-'.$this->workspaceId,
            'engine' => 'waha',
            'status' => 'connected',
        ]);

        WahaContactSaver::$retryDelaySeconds = 0;
    }

    private function saver(): WahaContactSaver
    {
        return new WahaContactSaver(new WahaClient(self::BASE, 'k'));
    }

    private function contact(array $attrs = []): Contact
    {
        return Contact::create(array_merge([
            'workspace_id' => $this->workspaceId,
            'phone_e164' => '+15551230000',
        ], $attrs));
    }

    private function putUrl(): string
    {
        return self::BASE.'/api/'.$this->session->session_name.'/contacts/15551230000@c.us';
    }

    #[Test]
    public function a_successful_save_is_recorded_and_sends_the_name(): void
    {
        Http::fake([$this->putUrl() => Http::response(['ok' => true], 200)]);
        $contact = $this->contact(['first_name' => 'Ali', 'last_name' => 'Raza']);

        $this->assertTrue($this->saver()->saveToPhone($this->session, $contact));

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && $r['firstName'] === 'Ali'
            && $r['lastName'] === 'Raza');
        $this->assertNotEmpty($contact->fresh()->custom_fields['saved_to_phone_at']);
    }

    #[Test]
    public function a_4xx_is_soft_failed_after_one_retry_and_recorded(): void
    {
        Http::fake([$this->putUrl() => Http::response(['error' => 'not allowed on web'], 400)]);
        $contact = $this->contact(['first_name' => 'Ali']);

        $this->assertFalse($this->saver()->saveToPhone($this->session, $contact));

        Http::assertSentCount(2);
        $cf = $contact->fresh()->custom_fields;
        $this->assertArrayHasKey('phone_save_error', $cf);
        $this->assertStringContainsString('400', $cf['phone_save_error']);
        $this->assertArrayNotHasKey('saved_to_phone_at', $cf);
    }

    #[Test]
    public function a_retry_that_succeeds_counts_as_saved(): void
    {
        Http::fake([$this->putUrl() => Http::sequence()
            ->push(['error' => 'busy'], 500)
            ->push([], 200)]);
        $contact = $this->contact(['first_name' => 'Ali']);

        $this->assertTrue($this->saver()->saveToPhone($this->session, $contact));
        $this->assertArrayHasKey('saved_to_phone_at', $contact->fresh()->custom_fields);
    }

    #[Test]
    public function it_is_attempted_once_per_contact(): void
    {
        Http::fake([$this->putUrl() => Http::response(['error' => 'nope'], 400)]);
        $contact = $this->contact(['first_name' => 'Ali']);

        $this->saver()->saveToPhone($this->session, $contact);
        $this->saver()->saveToPhone($this->session, $contact);
        $this->saver()->saveToPhone($this->session, $contact->fresh());

        Http::assertSentCount(2); // one attempt = initial request + one retry
    }

    #[Test]
    public function a_saved_contact_is_never_sent_again(): void
    {
        Http::fake([$this->putUrl() => Http::response([], 200)]);
        $contact = $this->contact(['first_name' => 'Ali']);

        $this->saver()->saveToPhone($this->session, $contact);
        $this->saver()->saveToPhone($this->session, $contact->fresh());

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_connection_exception_is_recorded_not_thrown(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection reset'));
        $contact = $this->contact(['first_name' => 'Ali']);

        $this->assertFalse($this->saver()->saveToPhone($this->session, $contact));
        $this->assertStringContainsString('exception', $contact->fresh()->custom_fields['phone_save_error']);
    }

    #[Test]
    public function name_falls_back_to_the_whatsapp_push_name_then_the_number(): void
    {
        Http::fake([$this->putUrl() => Http::response([], 200)]);

        $withPush = $this->contact(['custom_fields' => ['wa_push_name' => 'Sara Khan']]);
        $this->saver()->saveToPhone($this->session, $withPush);
        Http::assertSent(fn ($r) => $r['firstName'] === 'Sara' && $r['lastName'] === 'Khan');

        $withPush->forceDelete();
        Http::fake([$this->putUrl() => Http::response([], 200)]);
        $bare = Contact::create(['workspace_id' => $this->workspaceId, 'phone_e164' => '+15551230000']);
        $this->saver()->saveToPhone($this->session, $bare);
        Http::assertSent(fn ($r) => $r['firstName'] === '+15551230000' && $r['lastName'] === '');
    }
}
