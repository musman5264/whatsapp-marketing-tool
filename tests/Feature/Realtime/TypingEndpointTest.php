<?php

namespace Tests\Feature\Realtime;

use App\Events\TypingChanged;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TypingEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_typing_endpoint_returns_ok(): void
    {
        Event::fake([TypingChanged::class]);

        $ctx = $this->createWorkspaceContext();
        $user = $ctx['user'];
        $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);
        $conv = Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('client.inbox.typing', $conv->id), [
                'is_typing' => true,
            ]);

        $response->assertOk()->assertJson(['ok' => true]);
    }

    public function test_typing_endpoint_forbidden_for_other_workspace(): void
    {
        $ctx1 = $this->createWorkspaceContext();
        $ctx2 = $this->createWorkspaceContext();
        $contact = Contact::factory()->create(['workspace_id' => $ctx1['workspace']->id]);
        $conv = Conversation::create([
            'workspace_id' => $ctx1['workspace']->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $response = $this->actingAs($ctx2['user'])
            ->postJson(route('client.inbox.typing', $conv->id), [
                'is_typing' => true,
            ]);

        $response->assertForbidden();
    }
}
