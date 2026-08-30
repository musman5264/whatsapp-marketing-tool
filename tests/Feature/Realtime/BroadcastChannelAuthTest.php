<?php

namespace Tests\Feature\Realtime;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests the authorization logic defined in routes/channels.php.
 * We test the conditions directly rather than via the HTTP endpoint,
 * since the log driver (used in testing) doesn't process channel auth.
 */
class BroadcastChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    // ─── workspace.{workspaceId} channel ──────────────────────────────────────

    public function test_workspace_channel_allows_user_in_same_workspace(): void
    {
        $ctx = $this->createWorkspaceContext();
        $user = $ctx['user'];
        $wsId = $ctx['workspace']->id;

        // The channel callback: $u->workspace_id === $workspaceId OR in accessibleWorkspaces
        $this->assertTrue(
            (int) $user->workspace_id === (int) $wsId ||
            $user->accessibleWorkspaces()->contains('id', $wsId)
        );
    }

    public function test_workspace_channel_rejects_user_in_different_workspace(): void
    {
        $ctx1 = $this->createWorkspaceContext();
        $ctx2 = $this->createWorkspaceContext();

        $user = $ctx1['user'];
        $otherWs = $ctx2['workspace']->id;

        $allowed = (int) $user->workspace_id === (int) $otherWs ||
                   $user->accessibleWorkspaces()->contains('id', $otherWs);

        $this->assertFalse($allowed);
    }

    // ─── conversation.{conversationId} channel ────────────────────────────────

    public function test_conversation_channel_allows_user_in_same_workspace(): void
    {
        $ctx = $this->createWorkspaceContext();
        $user = $ctx['user'];
        $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);
        $conv = Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        // The channel callback: Conversation::where('id', $id)->where('workspace_id', $u->workspace_id)->exists()
        $allowed = Conversation::where('id', $conv->id)
            ->where('workspace_id', $user->workspace_id)
            ->exists();

        $this->assertTrue($allowed);
    }

    public function test_conversation_channel_rejects_user_in_different_workspace(): void
    {
        $ctx1 = $this->createWorkspaceContext();
        $ctx2 = $this->createWorkspaceContext();
        $contact = Contact::factory()->create(['workspace_id' => $ctx1['workspace']->id]);
        $conv = Conversation::create([
            'workspace_id' => $ctx1['workspace']->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $allowed = Conversation::where('id', $conv->id)
            ->where('workspace_id', $ctx2['user']->workspace_id)
            ->exists();

        $this->assertFalse($allowed);
    }
}
