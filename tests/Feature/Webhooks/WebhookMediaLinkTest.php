<?php

namespace Tests\Feature\Webhooks;

use App\Events\CampaignCompleted;
use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Jobs\DispatchWebhookJob;
use App\Listeners\DispatchOutboundWebhookListener;
use App\Models\Media;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Services\WahaMediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Task T-0030: the message.received webhook for a WhatsApp Web photo carries a signed link to the
 * stored file. The link is valid for one hour and is built again on every attempt. The stored
 * delivery payload never keeps it; the bytes that were sent (request_body) do.
 */
class WebhookMediaLinkTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://receiver.test/hook';

    private ?string $mediaRoot = null;

    private User $user;

    private Workspace $workspace;

    private Conversation $conversation;

    private WebhookEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        // The real 'local' disk, pointed at a throwaway folder. WhatsApp Web media is named
        // message-media/{messageId}.jpg, so the real storage root could hold live files with the same names.
        $this->mediaRoot = storage_path('framework/testing/webhook-media-'.uniqid());
        config(['filesystems.disks.local.root' => $this->mediaRoot]);
        Storage::purge('local');

        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response('ok', 200)]);

        $this->user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->user->update(['workspace_id' => $this->workspace->id]);

        // WhatsApp Web account: the placeholder and the signed link only apply to these.
        $account = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'display_name' => 'WhatsApp (personal)',
            'status' => 'active',
        ]);
        $contact = Contact::factory()->create([
            'workspace_id' => $this->workspace->id,
            'phone_e164' => '+923001234567',
        ]);
        $this->conversation = Conversation::create([
            'workspace_id' => $this->workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $this->endpoint = WebhookEndpoint::factory()->create([
            'user_id' => $this->user->id,
            'url' => self::ENDPOINT,
            'secret' => 'whsec_test_secret',
            'events' => ['message.received', 'contact.created', 'campaign.completed'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if ($this->mediaRoot !== null) {
            File::deleteDirectory($this->mediaRoot);
        }

        parent::tearDown();
    }

    /** A WhatsApp Web photo whose file is not stored yet. */
    private function photoMessage(?int $mediaId = null): Message
    {
        return Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'channel' => 'whatsapp',
            'type' => 'image',
            'media_id' => $mediaId,
            'payload' => [
                'id' => 'false_'.uniqid(),
                'image' => ['link' => 'http://waha.test:3000/api/files/photo.jpeg'],
            ],
            'body' => '',
            'sent_at' => now(),
        ]);
    }

    /** A WhatsApp Web photo whose file is stored the way WahaMediaStore::store() leaves it. */
    private function storedPhoto(array $extraPayload = []): Message
    {
        $message = $this->photoMessage();
        $path = $this->mediaPath($message);
        $message->update(['payload' => array_merge($message->payload, [
            'mime_type' => 'image/jpeg',
            'media_path' => $path,
        ], $extraPayload)]);
        Storage::disk('local')->put($path, 'JPEG-BYTES');

        return $message;
    }

    /**
     * A WhatsApp Web document as the normalizer stores it: the filename sits under the type key
     * (payload.document.filename), and the stored copy is named from its MIME type.
     */
    private function storedDocument(): Message
    {
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'channel' => 'whatsapp',
            'type' => 'document',
            'payload' => [
                'id' => 'false_'.uniqid(),
                'document' => [
                    'link' => 'http://waha.test:3000/api/files/invoice.pdf',
                    'filename' => 'invoice.pdf',
                ],
            ],
            'body' => '',
            'sent_at' => now(),
        ]);
        $path = 'message-media/'.$message->id.'.pdf';
        $message->update(['payload' => array_merge($message->payload, [
            'mime_type' => 'application/pdf',
            'media_path' => $path,
        ])]);
        Storage::disk('local')->put($path, '%PDF-BYTES');

        return $message;
    }

    private function mediaPath(Message $message): string
    {
        return 'message-media/'.$message->id.'.jpg';
    }

    /** The job the queue would run for this message, carrying the payload the listener builds. */
    private function jobFor(Message $message): DispatchWebhookJob
    {
        $payload = app(DispatchOutboundWebhookListener::class)->messageReceivedPayload($message);

        return new DispatchWebhookJob($this->endpoint, 'message.received', $payload);
    }

    /** Runs handle() the way the queue does, so its dependencies come from the container. */
    private function runJob(DispatchWebhookJob $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    /**
     * The JSON bodies sent to the endpoint, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sentBodies(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn ($request) => $request->url() === self::ENDPOINT)
            ->map(fn ($request) => json_decode($request->body(), true))
            ->values()
            ->all();
    }

    private function sentMedia(int $index = 0): mixed
    {
        return (($this->sentBodies()[$index] ?? [])['message']['media'] ?? null);
    }

    private function sentMediaUrl(int $index = 0): mixed
    {
        $media = $this->sentMedia($index);

        return is_array($media) ? ($media['url'] ?? null) : null;
    }

    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }

    /** Requests a link the way a browser or WAHA would: the path and query, against this app. */
    private function fetch(string $url): TestResponse
    {
        $parts = parse_url($url);

        return $this->get($parts['path'].'?'.($parts['query'] ?? ''));
    }

    public function test_a_whatsapp_photo_is_sent_with_a_signed_link_that_serves_the_file(): void
    {
        Carbon::setTestNow('2026-10-13 10:00:00');
        $message = $this->storedPhoto();

        app(DispatchOutboundWebhookListener::class)->handleMessageReceived(new MessageReceived($message));

        $this->assertCount(1, $this->sentBodies());
        $media = $this->sentMedia();
        $this->assertIsArray($media);
        $this->assertSame(['filename', 'id', 'mime_type', 'url'], $this->sortedKeys($media));
        $this->assertNull($media['id']);
        $this->assertSame('image/jpeg', $media['mime_type']);
        $this->assertNull($media['filename']);

        $url = $media['url'];
        $this->assertIsString($url);
        $this->assertStringContainsString('/private-files/message-media/'.$message->id.'.jpg?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(now()->addHour()->timestamp, (int) ($query['expires'] ?? 0));

        $response = $this->fetch($url);
        $response->assertOk();
        $this->assertSame('JPEG-BYTES', $response->streamedContent());
    }

    public function test_the_link_is_refused_after_one_hour_and_a_new_run_sends_a_working_link(): void
    {
        $job = $this->jobFor($this->storedPhoto());

        Carbon::setTestNow('2026-10-13 10:00:00');
        $this->runJob($job);
        $first = $this->sentMediaUrl(0);
        $this->assertIsString($first);

        Carbon::setTestNow('2026-10-13 10:59:00');
        $this->fetch($first)->assertOk();

        Carbon::setTestNow('2026-10-13 11:00:01');
        $this->fetch($first)->assertForbidden();

        $this->runJob($job);
        $this->assertCount(2, $this->sentBodies());
        $second = $this->sentMediaUrl(1);
        $this->assertIsString($second);
        $this->fetch($second)->assertOk();
    }

    public function test_two_runs_at_different_times_send_two_different_signed_links(): void
    {
        $job = $this->jobFor($this->storedPhoto());

        Carbon::setTestNow('2026-10-13 10:00:00');
        $this->runJob($job);
        Carbon::setTestNow('2026-10-13 10:05:00');
        $this->runJob($job);

        $this->assertCount(2, $this->sentBodies());
        $first = $this->sentMediaUrl(0);
        $second = $this->sentMediaUrl(1);
        $this->assertIsString($first);
        $this->assertIsString($second);
        $this->assertNotSame($first, $second);
        $this->fetch($second)->assertOk();
    }

    public function test_a_photo_with_no_stored_file_is_sent_with_a_null_link(): void
    {
        $message = $this->photoMessage();
        $this->assertFalse(Storage::disk('local')->exists($this->mediaPath($message)));

        app(DispatchOutboundWebhookListener::class)->handleMessageReceived(new MessageReceived($message));

        Http::assertSent(fn ($request) => $request->url() === self::ENDPOINT);
        $this->assertCount(1, $this->sentBodies());
        $media = $this->sentMedia();
        $this->assertIsArray($media);
        $this->assertArrayHasKey('url', $media);
        $this->assertNull($media['url']);
    }

    public function test_a_failing_storage_lookup_still_sends_the_webhook_and_logs_a_warning_without_the_link(): void
    {
        $message = $this->storedPhoto();
        $this->mock(WahaMediaStore::class, function (MockInterface $mock) {
            $mock->shouldReceive('existingPath')->andThrow(new RuntimeException('storage offline'));
        });

        Log::spy();

        app(DispatchOutboundWebhookListener::class)->handleMessageReceived(new MessageReceived($message));

        Http::assertSent(fn ($request) => $request->url() === self::ENDPOINT);
        $this->assertCount(1, $this->sentBodies());
        $this->assertNull($this->sentMediaUrl());

        Log::shouldHaveReceived('warning')->with(
            'webhook.media_link_failed',
            \Mockery::on(function ($context) {
                $text = json_encode($context, JSON_UNESCAPED_SLASHES);

                return is_string($text)
                    && ! str_contains($text, 'signature=')
                    && ! str_contains($text, 'expires=')
                    && ! str_contains($text, '/private-files/');
            }),
        );
    }

    public function test_the_stored_delivery_payload_has_no_signed_link_but_the_sent_body_does(): void
    {
        $this->runJob($this->jobFor($this->storedPhoto()));

        $link = $this->sentMediaUrl();
        $this->assertIsString($link);

        $delivery = WebhookDelivery::query()->latest('id')->firstOrFail();

        // request_body is the exact bytes that were sent and signed, so the link is in it.
        $this->assertSame($link, data_get(json_decode($delivery->request_body, true), 'message.media.url'));

        // The stored caller payload keeps no link.
        $this->assertNull(data_get($delivery->payload, 'message.media.url'));
        $this->assertStringNotContainsString('signature=', json_encode($delivery->payload));
        $this->assertStringNotContainsString($link, json_encode($delivery->payload, JSON_UNESCAPED_SLASHES));
    }

    public function test_the_contact_created_body_keeps_its_shape_and_has_no_media_or_link(): void
    {
        $contact = Contact::factory()->create([
            'workspace_id' => $this->workspace->id,
            'phone_e164' => '+923009876543',
            'email' => 'ali@example.test',
            'first_name' => 'Ali',
            'last_name' => 'Khan',
        ]);

        app(DispatchOutboundWebhookListener::class)->handleContactCreated(new ContactCreated($contact));

        $bodies = $this->sentBodies();
        $this->assertCount(1, $bodies);
        $this->assertSame(['contact', 'event', 'timestamp'], $this->sortedKeys($bodies[0]));
        $this->assertSame('contact.created', $bodies[0]['event']);
        $this->assertSame([
            'id' => $contact->id,
            'phone_e164' => '+923009876543',
            'email' => 'ali@example.test',
            'first_name' => 'Ali',
            'last_name' => 'Khan',
        ], $bodies[0]['contact']);
        $this->assertStringNotContainsString('signature', Http::recorded()[0][0]->body());
        $this->assertStringNotContainsString('private-files', Http::recorded()[0][0]->body());
    }

    public function test_the_campaign_completed_body_keeps_its_shape_and_has_no_media_or_link(): void
    {
        $campaign = Campaign::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Spring sale',
            'channel' => 'whatsapp',
            'status' => 'completed',
            'totals_json' => ['total' => 3, 'sent' => 3],
        ]);

        app(DispatchOutboundWebhookListener::class)->handleCampaignCompleted(new CampaignCompleted($campaign));

        $bodies = $this->sentBodies();
        $this->assertCount(1, $bodies);
        $this->assertSame(['campaign', 'event', 'timestamp'], $this->sortedKeys($bodies[0]));
        $this->assertSame('campaign.completed', $bodies[0]['event']);
        $this->assertSame([
            'id' => $campaign->id,
            'name' => 'Spring sale',
            'channel' => 'whatsapp',
            'status' => 'completed',
            'totals' => ['total' => 3, 'sent' => 3],
        ], $bodies[0]['campaign']);
        $this->assertStringNotContainsString('signature', Http::recorded()[0][0]->body());
        $this->assertStringNotContainsString('private-files', Http::recorded()[0][0]->body());
    }

    public function test_a_media_row_message_keeps_id_and_mime_type_and_has_no_filename_or_link(): void
    {
        $mediaRow = Media::factory()->create(['mime_type' => 'image/jpeg']);
        $message = $this->photoMessage($mediaRow->id);

        // Even with a file on disk under this message's name, a media-row message gets no link.
        Storage::disk('local')->put($this->mediaPath($message), 'JPEG-BYTES');

        app(DispatchOutboundWebhookListener::class)->handleMessageReceived(new MessageReceived($message));

        $this->assertCount(1, $this->sentBodies());
        $this->assertSame([
            'id' => $mediaRow->id,
            'mime_type' => 'image/jpeg',
            'filename' => null,
            'url' => null,
        ], $this->sentMedia());
    }

    public function test_a_filename_stored_on_the_message_is_sent_with_the_link(): void
    {
        $message = $this->storedPhoto(['filename' => 'holiday.jpg']);

        $this->runJob($this->jobFor($message));

        $media = $this->sentMedia();
        $this->assertIsArray($media);
        $this->assertSame('holiday.jpg', $media['filename'] ?? null);
        $this->assertIsString($media['url'] ?? null);
    }

    public function test_a_whatsapp_document_sends_the_filename_stored_under_its_type_key_with_the_link(): void
    {
        $message = $this->storedDocument();

        app(DispatchOutboundWebhookListener::class)->handleMessageReceived(new MessageReceived($message));

        $this->assertCount(1, $this->sentBodies());
        $media = $this->sentMedia();
        $this->assertIsArray($media);
        $this->assertSame('invoice.pdf', $media['filename'] ?? null);
        $this->assertIsString($media['url'] ?? null);
        $this->fetch($media['url'])->assertOk();
    }
}
