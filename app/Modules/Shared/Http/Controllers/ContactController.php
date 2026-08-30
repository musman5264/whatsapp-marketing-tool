<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\ContactService;
use App\Services\StorageManager;
use App\Support\Demo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function __construct(
        private ContactService $contactService,
        private StorageManager $storageManager,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $contacts = Contact::where('workspace_id', $workspaceId)
            ->with('tags')
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('first_name', 'like', '%'.$request->search.'%')
                    ->orWhere('last_name', 'like', '%'.$request->search.'%')
                    ->orWhere('phone_e164', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            }))
            ->when($request->tag, fn ($q) => $q->whereHas('tags', fn ($q) => $q->where('name', $request->tag)))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $tags = ContactTag::where('workspace_id', $workspaceId)->orderBy('name')->get();
        $segments = Segment::where('workspace_id', $workspaceId)->where('type', 'static')->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts,
            'tags' => $tags,
            'segments' => $segments,
            'filters' => $request->only('search', 'tag'),
        ]);
    }

    public function bulkImport(Request $request): Response
    {
        return Inertia::render('Contacts/BulkImport', $this->bulkImportProps($request));
    }

    /**
     * @return array{tags: Collection, segments: Collection}
     */
    private function bulkImportProps(Request $request): array
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        return [
            'tags' => ContactTag::where('workspace_id', $workspaceId)->orderBy('name')->get(),
            'segments' => Segment::where('workspace_id', $workspaceId)
                ->where('type', 'static')
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    public function show(Request $request, Contact $contact): Response
    {
        $this->authoriseContact($request, $contact);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $contact->load(['tags', 'segments', 'conversations' => fn ($q) => $q->with(['messages' => fn ($q) => $q->latest('sent_at')->limit(5)])->latest('last_message_at')->limit(10)]);

        $staticSegments = Segment::where('workspace_id', $workspaceId)->where('type', 'static')->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Contacts/Show', [
            'contact' => $contact,
            'staticSegments' => $staticSegments,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $validated = $request->validate([
            'phone_e164' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:191'],
            'first_name' => ['nullable', 'string', 'max:128'],
            'last_name' => ['nullable', 'string', 'max:128'],
            'country' => ['nullable', 'string', 'max:4'],
            'language' => ['nullable', 'string', 'max:8'],
            'opt_in_whatsapp' => ['boolean'],
            'opt_in_sms' => ['boolean'],
            'opt_in_email' => ['boolean'],
            'segment_ids' => ['nullable', 'array'],
            'segment_ids.*' => ['integer', Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static'))],
        ]);

        $segmentIds = $validated['segment_ids'] ?? [];
        unset($validated['segment_ids']);

        $contact = $this->contactService->upsert($workspaceId, array_merge($validated, ['source' => 'manual']));

        if ($segmentIds) {
            $contact->segments()->syncWithoutDetaching($segmentIds);
            Segment::whereIn('id', $segmentIds)->each(fn ($s) => $s->update(['contact_count' => $s->contacts()->count()]));
        }

        return back()->with('success', 'Contact saved.');
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        $this->authoriseContact($request, $contact);
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:128'],
            'last_name' => ['nullable', 'string', 'max:128'],
            'email' => ['nullable', 'email', 'max:191'],
            'country' => ['nullable', 'string', 'max:4'],
            'language' => ['nullable', 'string', 'max:8'],
            'opt_in_whatsapp' => ['boolean'],
            'opt_in_sms' => ['boolean'],
            'opt_in_email' => ['boolean'],
            'custom_fields' => ['nullable', 'array'],
            'segment_ids' => ['nullable', 'array'],
            'segment_ids.*' => ['integer', Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static'))],
        ]);

        $segmentIds = $validated['segment_ids'] ?? null;
        unset($validated['segment_ids']);

        $contact->update($validated);

        if ($segmentIds !== null) {
            $oldSegmentIds = $contact->segments()->where('type', 'static')->pluck('segments.id')->toArray();
            $contact->segments()->sync($segmentIds);
            $affectedIds = array_unique(array_merge($oldSegmentIds, $segmentIds));
            Segment::whereIn('id', $affectedIds)->each(fn ($s) => $s->update(['contact_count' => $s->contacts()->count()]));
        }

        return back()->with('success', 'Contact updated.');
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        $this->authoriseContact($request, $contact);
        $contact->delete();

        return back()->with('success', 'Contact deleted.');
    }

    public function uploadAvatar(Request $request, Contact $contact): RedirectResponse
    {
        $this->authoriseContact($request, $contact);
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
        ]);

        // Delete old stored avatar if it's not an external URL
        if ($contact->avatar && ! str_starts_with($contact->avatar, 'http')) {
            $this->storageManager->disk()->delete($contact->avatar);
        }

        $file = $request->file('avatar');
        $path = $this->storageManager->prefixedPath('contact-avatars/'.$file->hashName());
        $this->storageManager->disk()->putFileAs('contact-avatars', $file, basename($path));
        $contact->update(['avatar' => $path]);

        return back()->with('success', 'Avatar updated.');
    }

    public function deleteAvatar(Request $request, Contact $contact): RedirectResponse
    {
        $this->authoriseContact($request, $contact);

        if ($contact->avatar && ! str_starts_with($contact->avatar, 'http')) {
            $this->storageManager->disk()->delete($contact->avatar);
        }

        $contact->update(['avatar' => null]);

        return back()->with('success', 'Avatar removed.');
    }

    public function import(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');

        // Detect delimiter from the first line (comma, semicolon, or tab).
        $firstLine = fgets($handle) ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();
        rewind($handle);

        $headers = null;
        $data = [];
        $limit = 10000;

        while (($line = fgetcsv($handle, null, $delimiter)) !== false && count($data) < $limit) {
            if ($headers === null) {
                // Strip a UTF-8 BOM so the first header matches field aliases.
                $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]);
                $headers = array_map('trim', $line);

                continue;
            }
            // Pad or truncate ragged rows instead of dropping them.
            $line = array_pad(array_slice($line, 0, count($headers)), count($headers), null);
            $data[] = array_combine($headers, $line);
        }
        fclose($handle);

        if ($headers === null || empty($data)) {
            return back()->withErrors(['file' => 'The CSV file appears to be empty or has no valid rows.']);
        }

        $stats = $this->contactService->bulkImport($workspaceId, $data);

        return back()->with('success', "Imported: {$stats['created']} created, {$stats['updated']} updated, {$stats['skipped']} skipped.");
    }

    /**
     * Chunked JSON import used by the CSV import wizard. Rows arrive already
     * mapped to canonical field names; returns per-chunk stats for progress UI.
     * Optional tag_names / segment_ids are applied to every imported contact.
     */
    public function importRows(Request $request): JsonResponse
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*' => ['array'],
            'tag_names' => ['nullable', 'array', 'max:20'],
            'tag_names.*' => ['string', 'max:64'],
            'segment_ids' => ['nullable', 'array', 'max:20'],
            'segment_ids.*' => [
                'integer',
                Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static')),
            ],
        ]);

        return response()->json($this->contactService->bulkImport(
            $workspaceId,
            $validated['rows'],
            tagNames: $validated['tag_names'] ?? [],
            segmentIds: $validated['segment_ids'] ?? [],
        ));
    }

    public function bulkStore(Request $request): Response
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        $validated = $request->validate([
            'rows' => ['required', 'array', 'max:500'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.phone_e164' => ['nullable', 'string', 'max:20'],
            'rows.*.tag_id' => [
                'nullable',
                'integer',
                Rule::exists('contact_tags', 'id')->where('workspace_id', $workspaceId),
            ],
            'rows.*.segment_id' => [
                'nullable',
                'integer',
                Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static')),
            ],
        ]);

        $rows = array_values(array_filter(
            $validated['rows'],
            fn (array $r) => isset($r['phone_e164']) && trim((string) $r['phone_e164']) !== ''
        ));

        if ($rows === []) {
            throw ValidationException::withMessages([
                'rows' => 'Add at least one row with a phone number in international format (e.g. +1…).',
            ]);
        }

        $stats = $this->contactService->importGridRows($workspaceId, $rows);

        $request->session()->flash(
            'success',
            "Bulk import finished: {$stats['created']} created, {$stats['updated']} updated, {$stats['skipped']} skipped."
        );

        return Inertia::render('Contacts/BulkImport', $this->bulkImportProps($request));
    }

    public function bulkTags(Request $request): RedirectResponse
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        $validated = $request->validate([
            'uuids' => ['required', 'array', 'max:500'],
            'uuids.*' => ['string', 'uuid'],
            'action' => ['required', Rule::in(['add', 'remove'])],
            'tag_names' => ['required', 'array', 'min:1', 'max:20'],
            'tag_names.*' => ['string', 'max:64'],
        ]);

        $contactIds = Contact::where('workspace_id', $workspaceId)
            ->whereIn('uuid', $validated['uuids'])
            ->pluck('id');

        $tagNames = array_values(array_unique(array_filter(array_map('trim', $validated['tag_names']))));

        if ($validated['action'] === 'add') {
            $tagIds = collect($tagNames)
                ->map(fn (string $name) => ContactTag::firstOrCreate(['workspace_id' => $workspaceId, 'name' => $name])->id);

            $existing = DB::table('contact_tag_pivot')
                ->whereIn('contact_id', $contactIds)
                ->whereIn('tag_id', $tagIds)
                ->get(['contact_id', 'tag_id'])
                ->map(fn ($p) => $p->contact_id.':'.$p->tag_id)
                ->flip();

            $inserts = [];
            foreach ($contactIds as $contactId) {
                foreach ($tagIds as $tagId) {
                    if (! isset($existing[$contactId.':'.$tagId])) {
                        $inserts[] = ['contact_id' => $contactId, 'tag_id' => $tagId];
                    }
                }
            }
            if ($inserts !== []) {
                DB::table('contact_tag_pivot')->insert($inserts);
            }
        } else {
            $tagIds = ContactTag::where('workspace_id', $workspaceId)->whereIn('name', $tagNames)->pluck('id');
            DB::table('contact_tag_pivot')
                ->whereIn('contact_id', $contactIds)
                ->whereIn('tag_id', $tagIds)
                ->delete();
        }

        return back()->with('success', "Tags updated for {$contactIds->count()} contact(s).");
    }

    public function bulkSegments(Request $request): RedirectResponse
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        $validated = $request->validate([
            'uuids' => ['required', 'array', 'max:500'],
            'uuids.*' => ['string', 'uuid'],
            'action' => ['required', Rule::in(['add', 'remove'])],
            'segment_ids' => ['required', 'array', 'min:1', 'max:20'],
            'segment_ids.*' => [
                'integer',
                Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static')),
            ],
        ]);

        $contactIds = Contact::where('workspace_id', $workspaceId)
            ->whereIn('uuid', $validated['uuids'])
            ->pluck('id');

        $segmentIds = array_values(array_unique($validated['segment_ids']));

        if ($validated['action'] === 'add') {
            $existing = DB::table('segment_contact')
                ->whereIn('contact_id', $contactIds)
                ->whereIn('segment_id', $segmentIds)
                ->get(['contact_id', 'segment_id'])
                ->map(fn ($p) => $p->contact_id.':'.$p->segment_id)
                ->flip();

            $inserts = [];
            foreach ($contactIds as $contactId) {
                foreach ($segmentIds as $segmentId) {
                    if (! isset($existing[$contactId.':'.$segmentId])) {
                        $inserts[] = ['contact_id' => $contactId, 'segment_id' => $segmentId];
                    }
                }
            }
            if ($inserts !== []) {
                DB::table('segment_contact')->insert($inserts);
            }
        } else {
            DB::table('segment_contact')
                ->whereIn('contact_id', $contactIds)
                ->whereIn('segment_id', $segmentIds)
                ->delete();
        }

        Segment::whereIn('id', $segmentIds)->each(fn ($s) => $s->update(['contact_count' => $s->contacts()->count()]));

        return back()->with('success', "Segments updated for {$contactIds->count()} contact(s).");
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'max:500'],
            'uuids.*' => ['string', 'uuid'],
        ]);

        $deleted = Contact::where('workspace_id', $workspaceId)
            ->whereIn('uuid', $validated['uuids'])
            ->delete();

        return back()->with('success', "{$deleted} contact(s) deleted.");
    }

    public function export(Request $request): HttpResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $contacts = Contact::where('workspace_id', $workspaceId)
            ->with('tags')
            ->when($request->uuids, fn ($q) => $q->whereIn('uuid', explode(',', $request->uuids)))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('first_name', 'like', '%'.$request->search.'%')
                    ->orWhere('last_name', 'like', '%'.$request->search.'%')
                    ->orWhere('phone_e164', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            }))
            ->latest()
            ->get();

        $headers = ['First Name', 'Last Name', 'Phone', 'Email', 'Tags', 'Opt-in WhatsApp', 'Opt-in SMS', 'Opt-in Email', 'Created At'];
        $rows = $contacts->map(fn ($c) => [
            Demo::name($c->first_name) ?? '',
            Demo::name($c->last_name) ?? '',
            Demo::phone($c->phone_e164) ?? '',
            Demo::email($c->email) ?? '',
            $c->tags->pluck('name')->join(', '),
            $c->opt_in_whatsapp ? 'yes' : 'no',
            $c->opt_in_sms ? 'yes' : 'no',
            $c->opt_in_email ? 'yes' : 'no',
            $c->created_at?->toDateTimeString() ?? '',
        ]);

        $csv = collect([$headers])->merge($rows)->map(fn ($row) => collect($row)->map(fn ($v) => '"'.str_replace('"', '""', $v).'"')->join(',')
        )->join("\n");

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="contacts-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    private function authoriseContact(Request $request, Contact $contact): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $contact->workspace_id === (int) $workspaceId, 403);
    }
}
