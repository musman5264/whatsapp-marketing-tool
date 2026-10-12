<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConversationListPagingTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    private int $workspaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $ctx = $this->createWorkspaceContext();
        $this->user = $ctx['user'];
        $this->workspaceId = $ctx['workspace']->id;

        $account = ChannelAccount::create([
            'workspace_id' => $this->workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'waha',
            'display_name' => 'WA Web',
            'status' => 'active',
        ]);

        // 45 open conversations; $i = 0 is the newest.
        for ($i = 0; $i < 45; $i++) {
            Conversation::create([
                'workspace_id' => $this->workspaceId,
                'channel_account_id' => $account->id,
                'contact_id' => Contact::factory()->create(['workspace_id' => $this->workspaceId])->id,
                'status' => 'open',
                'last_message_at' => now()->startOfSecond()->subMinutes($i),
            ]);
        }
    }

    #[Test]
    public function first_page_is_the_newest_thirty(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('client.inbox.index'))
            ->assertOk();

        $response->assertJsonCount(30, 'data');
        $response->assertJsonPath('total', 45);
        $this->assertNotNull($response->json('next_page_url'));

        $times = collect($response->json('data'))->pluck('last_message_at');
        $this->assertSame($times->sortDesc()->values()->all(), $times->values()->all());
    }

    #[Test]
    public function second_page_holds_the_rest_and_has_no_next_page(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('client.inbox.index', ['page' => 2]))
            ->assertOk();

        $response->assertJsonCount(15, 'data');
        $this->assertNull($response->json('next_page_url'));
    }

    #[Test]
    public function a_normal_visit_still_renders_the_inbox_page(): void
    {
        $this->actingAs($this->user)
            ->get(route('client.inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inbox/Index')->has('conversations.data', 30));
    }
}
