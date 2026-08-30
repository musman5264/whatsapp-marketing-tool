<?php

namespace App\Modules\Shared\Services;

use App\Events\ContactCreated;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use App\Services\StorageManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class ContactService
{
    public function __construct(private StorageManager $storageManager) {}

    /**
     * Upsert a contact by phone (E.164) within a workspace.
     * Falls back to email lookup if phone is absent.
     *
     * @param  bool  $dispatchCreatedEvent  Set false for bulk imports/syncs to avoid
     *                                      firing contact.created automations + outbound
     *                                      webhooks for thousands of historical records.
     */
    public function upsert(int $workspaceId, array $data, bool $dispatchCreatedEvent = true): Contact
    {
        $lookup = [];

        if (! empty($data['phone_e164'])) {
            $lookup = ['workspace_id' => $workspaceId, 'phone_e164' => $data['phone_e164']];
        } elseif (! empty($data['email'])) {
            $lookup = ['workspace_id' => $workspaceId, 'email' => $data['email']];
        }

        if (empty($lookup)) {
            $contact = Contact::create(array_merge($data, ['workspace_id' => $workspaceId]));
            if ($dispatchCreatedEvent) {
                ContactCreated::dispatch($contact);
            }

            return $contact;
        }

        $exists = Contact::withTrashed()->where($lookup)->exists();
        $contact = Contact::withTrashed()->updateOrCreate($lookup, array_merge($data, ['workspace_id' => $workspaceId]));

        // Restore soft-deleted contact so it appears in normal queries again.
        if ($contact->trashed()) {
            $contact->restore();
        }

        if (! $exists && $dispatchCreatedEvent) {
            ContactCreated::dispatch($contact);
        }

        return $contact;
    }

    /**
     * Aliases for CSV/import column headers, keyed by canonical contact field.
     * Header names are normalised (lowercase, non-alphanumerics collapsed to "_")
     * before matching, so "Opt-in WhatsApp" matches "opt_in_whatsapp".
     *
     * @var array<string, list<string>>
     */
    private const IMPORT_FIELD_ALIASES = [
        'first_name' => ['first_name', 'firstname', 'first', 'fname', 'given_name'],
        'last_name' => ['last_name', 'lastname', 'last', 'lname', 'surname', 'family_name'],
        'name' => ['name', 'full_name', 'contact_name', 'contact'],
        'phone_e164' => ['phone_e164', 'phone', 'phone_number', 'mobile', 'mobile_number', 'whatsapp', 'whatsapp_number', 'tel', 'telephone', 'msisdn', 'number'],
        'email' => ['email', 'email_address', 'e_mail', 'mail'],
        'country' => ['country', 'country_code'],
        'language' => ['language', 'lang', 'locale'],
        'tags' => ['tags', 'tag', 'labels', 'label'],
        'opt_in_whatsapp' => ['opt_in_whatsapp', 'whatsapp_opt_in', 'opt_in_wa', 'wa_opt_in'],
        'opt_in_sms' => ['opt_in_sms', 'sms_opt_in'],
        'opt_in_email' => ['opt_in_email', 'email_opt_in'],
    ];

    /**
     * Bulk import from an array of rows. Row keys may be canonical field names
     * or raw CSV headers ("First Name", "Phone") — both are normalised here.
     * $tagNames / $segmentIds are applied to every imported contact on top of
     * any per-row "tags" column.
     * Returns ['created' => int, 'updated' => int, 'skipped' => int, 'errors' => array].
     *
     * @param  list<string>  $tagNames
     * @param  list<int>  $segmentIds
     */
    public function bulkImport(int $workspaceId, array $rows, string $source = 'import', array $tagNames = [], array $segmentIds = []): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        $globalTagIds = array_map(
            fn (string $name) => ContactTag::firstOrCreate(['workspace_id' => $workspaceId, 'name' => mb_substr(trim($name), 0, 64)])->id,
            array_values(array_unique(array_filter(array_map('trim', $tagNames))))
        );
        // Only static segments belonging to this workspace can be assigned.
        $globalSegmentIds = $segmentIds === [] ? [] : Segment::where('workspace_id', $workspaceId)
            ->where('type', 'static')
            ->whereIn('id', $segmentIds)
            ->pluck('id')
            ->all();

        foreach (array_values($rows) as $index => $row) {
            try {
                $data = $this->normalizeImportRow($row);
                $phone = $data['phone_e164'] ?? null;
                $email = $data['email'] ?? null;

                if (! $phone && ! $email) {
                    $stats['skipped']++;
                    $this->pushImportError($stats, $index, 'Row has no valid phone number or email address.');

                    continue;
                }

                $existing = Contact::where('workspace_id', $workspaceId)
                    ->where(function ($q) use ($phone, $email) {
                        $q->when($phone, fn ($q) => $q->orWhere('phone_e164', $phone))
                            ->when($email, fn ($q) => $q->orWhere('email', $email));
                    })
                    ->exists();

                if (! $existing) {
                    // Sensible opt-in defaults for new contacts when the CSV has no opt-in columns.
                    $data += [
                        'opt_in_whatsapp' => (bool) $phone,
                        'opt_in_sms' => (bool) $phone,
                        'opt_in_email' => (bool) $email,
                    ];
                }

                $rowTagNames = $data['tags'];
                unset($data['tags']);

                $contact = $this->upsert($workspaceId, array_merge($data, ['source' => $source]), dispatchCreatedEvent: false);

                $tagIds = $globalTagIds;
                foreach ($rowTagNames as $name) {
                    $tagIds[] = ContactTag::firstOrCreate(['workspace_id' => $workspaceId, 'name' => $name])->id;
                }
                if ($tagIds !== []) {
                    $contact->tags()->syncWithoutDetaching(array_unique($tagIds));
                }
                if ($globalSegmentIds !== []) {
                    $contact->segments()->syncWithoutDetaching($globalSegmentIds);
                }

                $existing ? $stats['updated']++ : $stats['created']++;
            } catch (\Throwable $e) {
                $stats['skipped']++;
                $this->pushImportError($stats, $index, 'Could not save this row.');
            }
        }

        foreach ($globalSegmentIds as $segmentId) {
            $segment = Segment::query()->find($segmentId);
            $segment?->update(['contact_count' => $segment->contacts()->count()]);
        }

        return $stats;
    }

    /** Keep at most 25 row-level errors so huge broken files don't bloat the response. */
    private function pushImportError(array &$stats, int $index, string $message): void
    {
        if (count($stats['errors']) < 25) {
            $stats['errors'][] = ['row' => $index + 1, 'message' => $message];
        }
    }

    /**
     * Map raw import row keys/values onto Contact fields.
     *
     * @return array{tags: list<string>}&array<string, mixed>
     */
    private function normalizeImportRow(array $row): array
    {
        $aliasMap = [];
        foreach (self::IMPORT_FIELD_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $aliasMap[$alias] = $field;
            }
        }

        $mapped = [];
        foreach ($row as $key => $value) {
            $normalKey = trim((string) preg_replace('/_+/', '_', (string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $key)))), '_');
            $field = $aliasMap[$normalKey] ?? null;
            if ($field === null || is_array($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '' && ! isset($mapped[$field])) {
                $mapped[$field] = $value;
            }
        }

        // Split a combined "Name" column when first/last are not provided separately.
        if (isset($mapped['name']) && ! isset($mapped['first_name'])) {
            $parts = preg_split('/\s+/u', $mapped['name'], 2) ?: [];
            $mapped['first_name'] = $parts[0] ?? null;
            $mapped['last_name'] ??= $parts[1] ?? null;
        }
        unset($mapped['name']);

        $data = ['tags' => []];

        if (isset($mapped['phone_e164']) && ($phone = $this->normalizeImportPhone($mapped['phone_e164'])) !== null) {
            $data['phone_e164'] = $phone;
        }
        if (isset($mapped['email'])) {
            $email = strtolower($mapped['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 191) {
                $data['email'] = $email;
            }
        }
        foreach (['first_name', 'last_name'] as $field) {
            if (! empty($mapped[$field])) {
                $data[$field] = mb_substr($mapped[$field], 0, 128);
            }
        }
        if (isset($mapped['country']) && mb_strlen($mapped['country']) <= 4) {
            $data['country'] = strtoupper($mapped['country']);
        }
        if (isset($mapped['language']) && mb_strlen($mapped['language']) <= 8) {
            $data['language'] = strtolower($mapped['language']);
        }
        foreach (['opt_in_whatsapp', 'opt_in_sms', 'opt_in_email'] as $field) {
            if (isset($mapped[$field])) {
                $data[$field] = in_array(strtolower($mapped[$field]), ['yes', 'y', 'true', '1', 'on'], true);
            }
        }
        if (isset($mapped['tags'])) {
            $data['tags'] = array_values(array_unique(array_filter(array_map(
                fn (string $t) => mb_substr(trim($t), 0, 64),
                preg_split('/[,;|]/', $mapped['tags']) ?: []
            ))));
        }

        return $data;
    }

    /**
     * Light phone cleanup towards E.164: strips formatting, converts a leading
     * "00" to "+", and prefixes "+" on bare digit strings. Returns null when the
     * result is not a plausible E.164 number.
     */
    private function normalizeImportPhone(string $raw): ?string
    {
        $phone = preg_replace('/[\s().\-]+/', '', trim($raw)) ?? '';
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }
        if ($phone !== '' && $phone[0] !== '+' && ctype_digit($phone)) {
            $phone = '+'.$phone;
        }

        return preg_match('/^\+[1-9]\d{6,14}$/', $phone) ? $phone : null;
    }

    /**
     * Import contacts from spreadsheet-style rows (E.164 phone required per row).
     *
     * @param  array<int, array{name?: string|null, phone_e164?: string|null, tag_id?: int|null, segment_id?: int|null}>  $rows
     * @return array{created: int, updated: int, skipped: int}
     */
    public function importGridRows(int $workspaceId, array $rows, string $source = 'import'): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $segmentIdsTouched = [];

        foreach ($rows as $row) {
            $phone = isset($row['phone_e164']) ? trim((string) $row['phone_e164']) : '';
            if ($phone === '' || ! str_starts_with($phone, '+')) {
                $stats['skipped']++;

                continue;
            }

            try {
                $existing = Contact::where('workspace_id', $workspaceId)
                    ->where('phone_e164', $phone)
                    ->first();

                $name = isset($row['name']) ? trim((string) $row['name']) : '';
                $firstName = null;
                $lastName = null;
                if ($name !== '') {
                    $parts = preg_split('/\s+/u', $name, 2) ?: [];
                    $firstName = $parts[0] ?? null;
                    $lastName = $parts[1] ?? null;
                }

                $contact = $this->upsert($workspaceId, [
                    'phone_e164' => $phone,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'source' => $source,
                ]);

                if ($existing) {
                    $stats['updated']++;
                } else {
                    $stats['created']++;
                }

                $tagId = isset($row['tag_id']) ? (int) $row['tag_id'] : 0;
                if ($tagId > 0 && ContactTag::where('workspace_id', $workspaceId)->whereKey($tagId)->exists()) {
                    $contact->tags()->syncWithoutDetaching([$tagId]);
                }

                $segmentId = isset($row['segment_id']) ? (int) $row['segment_id'] : 0;
                if ($segmentId > 0) {
                    $segment = Segment::where('workspace_id', $workspaceId)
                        ->whereKey($segmentId)
                        ->where('type', 'static')
                        ->first();
                    if ($segment) {
                        $contact->segments()->syncWithoutDetaching([$segment->id]);
                        $segmentIdsTouched[$segment->id] = true;
                    }
                }
            } catch (\Throwable) {
                $stats['skipped']++;
            }
        }

        foreach (array_keys($segmentIdsTouched) as $segmentId) {
            $segment = Segment::query()->find($segmentId);
            if ($segment) {
                $segment->update(['contact_count' => $segment->contacts()->count()]);
            }
        }

        return $stats;
    }

    /**
     * Sync a contact's avatar from an external URL (WhatsApp, Instagram, Messenger profile pics).
     * Only updates if the contact has no manually-uploaded avatar (non-http stored path),
     * or if force=true.
     */
    public function syncAvatarFromUrl(Contact $contact, string $url, bool $force = false): void
    {
        if (! $force && $contact->avatar && ! str_starts_with($contact->avatar, 'http')) {
            // Contact has a manually uploaded avatar — don't overwrite
            return;
        }

        // Store the external URL directly (lightweight — no download needed for display)
        if ($contact->avatar !== $url) {
            $contact->update(['avatar' => $url]);
        }
    }

    /**
     * Download an external avatar URL and store it locally on the public disk.
     * Use this when you need a permanent local copy (e.g. WhatsApp CDN URLs expire).
     */
    public function downloadAndStoreAvatar(Contact $contact, string $url): void
    {
        try {
            $response = Http::timeout(10)->get($url);
            if (! $response->successful()) {
                return;
            }

            $contentType = $response->header('Content-Type') ?? 'image/jpeg';
            $ext = match (true) {
                str_contains($contentType, 'png') => 'png',
                str_contains($contentType, 'webp') => 'webp',
                str_contains($contentType, 'gif') => 'gif',
                default => 'jpg',
            };

            // Delete old stored avatar
            if ($contact->avatar && ! str_starts_with($contact->avatar, 'http')) {
                $this->storageManager->disk()->delete($contact->avatar);
            }

            $rawPath = 'contact-avatars/'.$contact->id.'_'.time().'.'.$ext;
            $path = $this->storageManager->prefixedPath($rawPath);
            $this->storageManager->disk()->put($path, $response->body());
            $contact->update(['avatar' => $path]);
        } catch (\Throwable) {
            // Avatar sync is non-critical; silently fail
        }
    }

    /** Export contacts for a workspace as array of arrays. */
    public function export(int $workspaceId): Collection
    {
        return Contact::where('workspace_id', $workspaceId)
            ->with('tags')
            ->get()
            ->map(fn (Contact $c) => [
                'phone' => $c->phone_e164,
                'email' => $c->email,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'country' => $c->country,
                'language' => $c->language,
                'opt_in_wa' => $c->opt_in_whatsapp ? 'yes' : 'no',
                'opt_in_sms' => $c->opt_in_sms ? 'yes' : 'no',
                'opt_in_email' => $c->opt_in_email ? 'yes' : 'no',
                'tags' => $c->tags->pluck('name')->implode(','),
                'source' => $c->source,
                'created_at' => $c->created_at?->toISOString(),
            ]);
    }
}
