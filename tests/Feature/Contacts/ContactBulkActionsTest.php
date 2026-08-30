<?php

namespace Tests\Feature\Contacts;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function workspaceId(): int
    {
        return (int) $this->ctx['workspace']->id;
    }

    private function makeContacts(int $count): array
    {
        return collect(range(1, $count))->map(fn ($i) => Contact::create([
            'workspace_id' => $this->workspaceId(),
            'phone_e164' => '+1415555020'.$i,
            'first_name' => "Contact{$i}",
        ]))->all();
    }

    /* ─────────────────── Tags ─────────────────── */

    public function test_bulk_add_tags_creates_missing_tags_and_attaches(): void
    {
        [$a, $b] = $this->makeContacts(2);
        ContactTag::create(['workspace_id' => $this->workspaceId(), 'name' => 'vip', 'color' => '#f00']);

        $response = $this->actingAs($this->ctx['user'])->post(route('client.contacts.bulk-tags'), [
            'uuids' => [$a->uuid, $b->uuid],
            'action' => 'add',
            'tag_names' => ['vip', 'imported'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(2, ContactTag::where('workspace_id', $this->workspaceId())->count());
        $this->assertEqualsCanonicalizing(['vip', 'imported'], $a->fresh()->tags->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['vip', 'imported'], $b->fresh()->tags->pluck('name')->all());
    }

    public function test_bulk_add_tags_is_idempotent_for_already_tagged_contacts(): void
    {
        [$a] = $this->makeContacts(1);
        $tag = ContactTag::create(['workspace_id' => $this->workspaceId(), 'name' => 'vip', 'color' => '#f00']);
        $a->tags()->attach($tag->id);

        $this->actingAs($this->ctx['user'])->post(route('client.contacts.bulk-tags'), [
            'uuids' => [$a->uuid],
            'action' => 'add',
            'tag_names' => ['vip'],
        ]);

        $this->assertCount(1, $a->fresh()->tags);
    }

    public function test_bulk_remove_tags_detaches_only_named_tags(): void
    {
        [$a] = $this->makeContacts(1);
        $vip = ContactTag::create(['workspace_id' => $this->workspaceId(), 'name' => 'vip', 'color' => '#f00']);
        $keep = ContactTag::create(['workspace_id' => $this->workspaceId(), 'name' => 'keep', 'color' => '#0f0']);
        $a->tags()->attach([$vip->id, $keep->id]);

        $this->actingAs($this->ctx['user'])->post(route('client.contacts.bulk-tags'), [
            'uuids' => [$a->uuid],
            'action' => 'remove',
            'tag_names' => ['vip'],
        ]);

        $this->assertSame(['keep'], $a->fresh()->tags->pluck('name')->all());
        $this->assertNotNull($vip->fresh(), 'Tag itself must not be deleted');
    }

    public function test_bulk_tags_ignores_contacts_from_other_workspaces(): void
    {
        $other = $this->createWorkspaceContext();
        $foreign = Contact::create([
            'workspace_id' => $other['workspace']->id,
            'phone_e164' => '+14155550999',
        ]);

        $this->actingAs($this->ctx['user'])->post(route('client.contacts.bulk-tags'), [
            'uuids' => [$foreign->uuid],
            'action' => 'add',
            'tag_names' => ['vip'],
        ])->assertRedirect();

        $this->assertCount(0, $foreign->fresh()->tags);
    }

    /* ─────────────────── Segments ─────────────────── */

    public function test_bulk_add_segments_attaches_and_updates_counts(): void
    {
        [$a, $b] = $this->makeContacts(2);
        $segment = Segment::create([
            'workspace_id' => $this->workspaceId(),
            'name' => 'Buyers',
            'type' => 'static',
        ]);

        $this->actingAs($this->ctx['user'])->post(route('client.contacts.bulk-segments'), [
            'uuids' => [$a->uuid, $b->uuid],
            'action' => 'add',
            'segment_ids' => [$segment->id],
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $segment->contacts()->pluck('contacts.id')->all());
        $this->assertSame(2, $segment->fresh()->contact_count);
    }

    public function test_bulk_remove_segments_detaches_and_updates_counts(): void
    {
        [$a, $b] = $this->makeContacts(2);
        $segment = Segment::create([
            'workspace_id' => $this->workspaceId(),
            'name' => 'Buyers',
            'type' => 'static',
            'contact_count' => 2,
        ]);
        $segment->contacts()->attach([$a->id, $b->id]);

        $this->actingAs($this->ctx['user'])->post(route('client.contacts.bulk-segments'), [
            'uuids' => [$a->uuid],
            'action' => 'remove',
            'segment_ids' => [$segment->id],
        ])->assertRedirect();

        $this->assertSame([$b->id], $segment->contacts()->pluck('contacts.id')->all());
        $this->assertSame(1, $segment->fresh()->contact_count);
    }

    public function test_bulk_segments_rejects_segment_from_other_workspace(): void
    {
        [$a] = $this->makeContacts(1);
        $other = $this->createWorkspaceContext();
        $foreignSegment = Segment::create([
            'workspace_id' => $other['workspace']->id,
            'name' => 'Foreign',
            'type' => 'static',
        ]);

        $this->actingAs($this->ctx['user'])->post(route('client.contacts.bulk-segments'), [
            'uuids' => [$a->uuid],
            'action' => 'add',
            'segment_ids' => [$foreignSegment->id],
        ])->assertSessionHasErrors('segment_ids.0');

        $this->assertSame(0, $foreignSegment->contacts()->count());
    }
}
