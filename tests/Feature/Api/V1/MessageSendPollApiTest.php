<?php

namespace Tests\Feature\Api\V1;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** T-0045: POST /api/v1/messages/send with type=poll sends a native WAHA poll. */
class MessageSendPollApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: Contact} */
    private function setUpSender(string $provider): array
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();
        $token = $user->createToken('t', [ApiAbilities::MESSAGES_WRITE])->plainTextToken;
        IntegrationConfig::create([
            'provider' => 'whatsapp_web', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha.test'],
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $ws->id, 'phone_e164' => '+8801700000009']);
        ChannelAccount::create([
            'workspace_id' => $ws->id, 'channel' => 'whatsapp', 'provider' => $provider,
            'status' => 'active', 'display_name' => 'WA', 'phone_number_id' => 'wa-'.$ws->id,
        ]);

        return [$token, $contact];
    }

    public function test_a_poll_is_sent_natively_through_waha(): void
    {
        [$token, $contact] = $this->setUpSender('whatsapp_web');
        Http::fake(['waha.test/api/sendPoll' => Http::response(['id' => 'true_8801700000009@c.us_ABC'], 201)]);

        $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'type' => 'poll',
            'body' => 'Tea or coffee?', 'poll_options' => ['Tea', 'Coffee'], 'poll_multiple' => true,
        ])->assertOk()
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('provider_message_id', 'true_8801700000009@c.us_ABC');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendPoll')
            && $r['poll']['name'] === 'Tea or coffee?'
            && $r['poll']['options'] === ['Tea', 'Coffee']
            && $r['poll']['multipleAnswers'] === true);
    }

    public function test_a_poll_needs_a_question_and_two_to_twelve_options(): void
    {
        [$token, $contact] = $this->setUpSender('whatsapp_web');
        $base = ['contact_id' => $contact->id, 'channel' => 'whatsapp', 'type' => 'poll'];

        $this->withToken($token)->postJson('/api/v1/messages/send', $base + ['body' => 'Q?', 'poll_options' => ['Only one']])
            ->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/messages/send', $base + ['poll_options' => ['A', 'B']])
            ->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/messages/send', $base + ['body' => 'Q?', 'poll_options' => range(1, 13)])
            ->assertStatus(422);
    }

    public function test_a_cloud_api_number_refuses_a_poll(): void
    {
        [$token, $contact] = $this->setUpSender('meta');

        $this->withToken($token)->postJson('/api/v1/messages/send', [
            'contact_id' => $contact->id, 'channel' => 'whatsapp', 'type' => 'poll',
            'body' => 'Q?', 'poll_options' => ['A', 'B'],
        ])->assertStatus(422)->assertJsonPath('error', fn ($e) => str_contains($e, 'WhatsApp Web'));
    }
}
