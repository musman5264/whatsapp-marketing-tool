<?php

namespace Tests\Feature\Contacts;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ContactImportTest extends TestCase
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

    /* ─────────────────── CSV file endpoint ─────────────────── */

    public function test_csv_import_maps_export_style_headers_to_contact_fields(): void
    {
        $csv = implode("\n", [
            'First Name,Last Name,Phone,Email,Tags,Opt-in WhatsApp,Opt-in SMS,Opt-in Email',
            'Jane,Doe,+14155550100,jane@example.com,"vip, lead",yes,no,yes',
            'John,Smith,+14155550101,john@example.com,,no,no,no',
        ]);

        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->actingAs($this->ctx['user'])
            ->post(route('client.contacts.import'), ['file' => $file]);

        $response->assertRedirect();
        $response->assertSessionHas('success', fn ($msg) => str_contains($msg, '2 created'));

        $jane = Contact::where('workspace_id', $this->workspaceId())->where('phone_e164', '+14155550100')->first();
        $this->assertNotNull($jane);
        $this->assertSame('Jane', $jane->first_name);
        $this->assertSame('Doe', $jane->last_name);
        $this->assertSame('jane@example.com', $jane->email);
        $this->assertTrue($jane->opt_in_whatsapp);
        $this->assertFalse($jane->opt_in_sms);
        $this->assertTrue($jane->opt_in_email);
        $this->assertEqualsCanonicalizing(['vip', 'lead'], $jane->tags->pluck('name')->all());

        $john = Contact::where('workspace_id', $this->workspaceId())->where('phone_e164', '+14155550101')->first();
        $this->assertNotNull($john);
        $this->assertFalse($john->opt_in_whatsapp);
    }

    public function test_csv_import_skips_rows_without_phone_or_email_instead_of_creating_blanks(): void
    {
        $csv = implode("\n", [
            'Name,Phone,Email',
            'Ghost Row,,',
            'Real Person,+14155550102,',
        ]);

        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->actingAs($this->ctx['user'])
            ->post(route('client.contacts.import'), ['file' => $file]);

        $response->assertSessionHas('success', fn ($msg) => str_contains($msg, '1 created') && str_contains($msg, '1 skipped'));

        $this->assertSame(1, Contact::where('workspace_id', $this->workspaceId())->count());
        $contact = Contact::where('workspace_id', $this->workspaceId())->first();
        $this->assertSame('Real', $contact->first_name);
        $this->assertSame('Person', $contact->last_name);
    }

    public function test_csv_import_handles_semicolon_delimiter_and_bom(): void
    {
        $csv = "\xEF\xBB\xBF".implode("\n", [
            'Phone;Email;First Name',
            '+14155550103;bom@example.com;Bommy',
        ]);

        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->actingAs($this->ctx['user'])->post(route('client.contacts.import'), ['file' => $file]);

        $contact = Contact::where('workspace_id', $this->workspaceId())->where('phone_e164', '+14155550103')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Bommy', $contact->first_name);
        $this->assertSame('bom@example.com', $contact->email);
    }

    /* ─────────────────── Chunked JSON endpoint (wizard) ─────────────────── */

    public function test_import_rows_creates_contacts_and_returns_stats(): void
    {
        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.contacts.import-rows'), [
            'rows' => [
                ['first_name' => 'Ana', 'phone_e164' => '+14155550110', 'tags' => 'newsletter'],
                ['name' => 'Bob Marley', 'email' => 'bob@example.com'],
                ['first_name' => 'NoContactInfo'],
            ],
        ]);

        $response->assertOk()
            ->assertJson(['created' => 2, 'updated' => 0, 'skipped' => 1]);

        $ana = Contact::where('workspace_id', $this->workspaceId())->where('phone_e164', '+14155550110')->first();
        $this->assertNotNull($ana);
        $this->assertTrue($ana->opt_in_whatsapp);
        $this->assertFalse($ana->opt_in_email);
        $this->assertSame(['newsletter'], $ana->tags->pluck('name')->all());

        $bob = Contact::where('workspace_id', $this->workspaceId())->where('email', 'bob@example.com')->first();
        $this->assertNotNull($bob);
        $this->assertSame('Bob', $bob->first_name);
        $this->assertSame('Marley', $bob->last_name);
        $this->assertTrue($bob->opt_in_email);
        $this->assertFalse($bob->opt_in_whatsapp);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertSame(3, $errors[0]['row']);
    }

    public function test_import_rows_updates_existing_contact_by_phone(): void
    {
        Contact::create([
            'workspace_id' => $this->workspaceId(),
            'phone_e164' => '+14155550120',
            'first_name' => 'Old',
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.contacts.import-rows'), [
            'rows' => [
                ['phone_e164' => '+1 (415) 555-0120', 'first_name' => 'New', 'last_name' => 'Name'],
            ],
        ]);

        $response->assertOk()->assertJson(['created' => 0, 'updated' => 1, 'skipped' => 0]);

        $this->assertSame(1, Contact::where('workspace_id', $this->workspaceId())->count());
        $contact = Contact::where('workspace_id', $this->workspaceId())->first();
        $this->assertSame('New', $contact->first_name);
        $this->assertSame('Name', $contact->last_name);
    }

    public function test_import_rows_normalizes_phone_formats(): void
    {
        $this->actingAs($this->ctx['user'])->postJson(route('client.contacts.import-rows'), [
            'rows' => [
                ['phone_e164' => '0014155550130'],
                ['phone_e164' => '14155550131'],
                ['phone_e164' => 'not-a-phone', 'email' => 'still@example.com'],
            ],
        ])->assertOk()->assertJson(['created' => 3]);

        $workspaceId = $this->workspaceId();
        $this->assertNotNull(Contact::where('workspace_id', $workspaceId)->where('phone_e164', '+14155550130')->first());
        $this->assertNotNull(Contact::where('workspace_id', $workspaceId)->where('phone_e164', '+14155550131')->first());

        $emailOnly = Contact::where('workspace_id', $workspaceId)->where('email', 'still@example.com')->first();
        $this->assertNotNull($emailOnly);
        $this->assertNull($emailOnly->phone_e164);
    }

    public function test_import_rows_reuses_existing_tag_instead_of_duplicating(): void
    {
        ContactTag::create(['workspace_id' => $this->workspaceId(), 'name' => 'vip', 'color' => '#ff0000']);

        $this->actingAs($this->ctx['user'])->postJson(route('client.contacts.import-rows'), [
            'rows' => [['phone_e164' => '+14155550140', 'tags' => 'vip; new']],
        ])->assertOk();

        $this->assertSame(2, ContactTag::where('workspace_id', $this->workspaceId())->count());
        $contact = Contact::where('workspace_id', $this->workspaceId())->where('phone_e164', '+14155550140')->first();
        $this->assertEqualsCanonicalizing(['vip', 'new'], $contact->tags->pluck('name')->all());
    }

    public function test_import_rows_applies_global_tags_and_segments(): void
    {
        $segment = Segment::create([
            'workspace_id' => $this->workspaceId(),
            'name' => 'Imported June',
            'type' => 'static',
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.contacts.import-rows'), [
            'rows' => [
                ['phone_e164' => '+14155550160', 'tags' => 'row-tag'],
                ['email' => 'global@example.com'],
            ],
            'tag_names' => ['june-import'],
            'segment_ids' => [$segment->id],
        ]);

        $response->assertOk()->assertJson(['created' => 2]);

        $first = Contact::where('workspace_id', $this->workspaceId())->where('phone_e164', '+14155550160')->first();
        $this->assertEqualsCanonicalizing(['june-import', 'row-tag'], $first->tags->pluck('name')->all());
        $this->assertTrue($first->segments->contains($segment->id));

        $second = Contact::where('workspace_id', $this->workspaceId())->where('email', 'global@example.com')->first();
        $this->assertSame(['june-import'], $second->tags->pluck('name')->all());
        $this->assertTrue($second->segments->contains($segment->id));

        $this->assertSame(2, $segment->fresh()->contact_count);
    }

    public function test_import_rows_rejects_segment_from_other_workspace(): void
    {
        $other = $this->createWorkspaceContext();
        $foreignSegment = Segment::create([
            'workspace_id' => $other['workspace']->id,
            'name' => 'Foreign',
            'type' => 'static',
        ]);

        $this->actingAs($this->ctx['user'])->postJson(route('client.contacts.import-rows'), [
            'rows' => [['phone_e164' => '+14155550161']],
            'segment_ids' => [$foreignSegment->id],
        ])->assertStatus(422);
    }

    public function test_import_rows_validates_chunk_size(): void
    {
        $rows = array_fill(0, 501, ['phone_e164' => '+14155550150']);

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.contacts.import-rows'), ['rows' => $rows])
            ->assertStatus(422);
    }
}
