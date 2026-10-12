<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\ContactResource;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContactApiController extends WorkspaceScopedController
{
    /**
     * GET /api/v1/contacts
     * Cursor-paginated; filterable by segment_id, tag, search, opt_in_whatsapp.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'segment_id' => ['sometimes', 'integer', 'min:1'],
            'tag' => ['sometimes', 'string', 'max:100'],
            'opt_in_whatsapp' => ['sometimes', 'boolean'],
        ]);

        $wsId = $this->workspaceId($request);

        $query = Contact::with('tags')
            ->where('workspace_id', $wsId)
            ->latest('id');

        if ($request->filled('search')) {
            $q = '%'.$request->search.'%';
            $query->where(function ($q2) use ($q) {
                $q2->where('first_name', 'like', $q)
                    ->orWhere('last_name', 'like', $q)
                    ->orWhere('email', 'like', $q)
                    ->orWhere('phone_e164', 'like', $q);
            });
        }

        if ($request->filled('segment_id')) {
            $query->whereHas('segments', fn ($s) => $s->where('segments.id', (int) $request->segment_id));
        }

        if ($request->filled('tag')) {
            $query->whereHas('tags', fn ($t) => $t->where('name', $request->tag));
        }

        if ($request->filled('opt_in_whatsapp')) {
            $query->where('opt_in_whatsapp', filter_var($request->opt_in_whatsapp, FILTER_VALIDATE_BOOLEAN));
        }

        return ContactResource::collection($query->cursorPaginate(25));
    }

    /**
     * POST /api/v1/contacts
     *
     * Upserts by phone (or email when no phone): a new contact returns 201, a
     * number that already exists returns 200 with that contact (fields sent in
     * the request overwrite the stored ones), and a soft-deleted match is restored.
     */
    public function store(Request $request, ContactService $contacts): JsonResponse
    {
        $validated = $request->validate([
            'phone_e164' => ['nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'language' => ['nullable', 'string', 'max:10'],
            'opt_in_whatsapp' => ['nullable', 'boolean'],
            'opt_in_sms' => ['nullable', 'boolean'],
            'opt_in_email' => ['nullable', 'boolean'],
            'custom_fields' => ['nullable', 'array', 'max:50'],
            'custom_fields.*' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'max:100'],
        ]);

        // No created-event here: this endpoint never fired contact.created before, and
        // firing it now would start automations and webhooks for every API import.
        $contact = $contacts->upsert($this->workspaceId($request), $validated, dispatchCreatedEvent: false);
        $contact->load('tags');

        return (new ContactResource($contact))
            ->response()
            ->setStatusCode($contact->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * GET /api/v1/contacts/{id}
     */
    public function show(Request $request, int $id): ContactResource|JsonResponse
    {
        $contact = Contact::with('tags')
            ->where('workspace_id', $this->workspaceId($request))
            ->find($id);

        if (! $contact) {
            return response()->json(['error' => 'Contact not found.'], 404);
        }

        return new ContactResource($contact);
    }

    /**
     * PATCH /api/v1/contacts/{id}
     */
    public function update(Request $request, int $id): ContactResource|JsonResponse
    {
        $contact = Contact::where('workspace_id', $this->workspaceId($request))->find($id);

        if (! $contact) {
            return response()->json(['error' => 'Contact not found.'], 404);
        }

        $validated = $request->validate([
            'phone_e164' => ['sometimes', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'email' => ['sometimes', 'email', 'max:255'],
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'country' => ['sometimes', 'string', 'size:2'],
            'language' => ['sometimes', 'string', 'max:10'],
            'opt_in_whatsapp' => ['sometimes', 'boolean'],
            'opt_in_sms' => ['sometimes', 'boolean'],
            'opt_in_email' => ['sometimes', 'boolean'],
            'custom_fields' => ['sometimes', 'array', 'max:50'],
            'custom_fields.*' => ['sometimes', 'string', 'max:1000'],
            'source' => ['sometimes', 'string', 'max:100'],
        ]);

        $contact->update($validated);
        $contact->load('tags');

        return new ContactResource($contact);
    }

    /**
     * DELETE /api/v1/contacts/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $contact = Contact::where('workspace_id', $this->workspaceId($request))->find($id);

        if (! $contact) {
            return response()->json(['error' => 'Contact not found.'], 404);
        }

        $contact->delete();

        return response()->json(['ok' => true]);
    }
}
