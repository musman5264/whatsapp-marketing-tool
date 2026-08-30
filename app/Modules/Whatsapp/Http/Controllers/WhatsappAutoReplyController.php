<?php

namespace App\Modules\Whatsapp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Whatsapp\Models\WhatsappAutoReply;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsappAutoReplyController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $rules = WhatsappAutoReply::where('workspace_id', $workspaceId)->orderBy('priority')->get();

        return Inertia::render('Whatsapp/AutoReplies/Index', ['rules' => $rules]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $validated = $request->validate([
            'trigger_type' => ['required', 'in:keyword,welcome,away,out_of_hours'],
            'match_mode' => ['required', 'in:exact,contains,regex'],
            'keywords' => ['nullable', 'array'],
            'schedule_json' => ['nullable', 'array'],
            'response_kind' => ['required', 'in:text,template,media,flow'],
            'payload_json' => ['required', 'array'],
            'enabled' => ['boolean'],
            'priority' => ['integer', 'min:0'],
        ]);

        $this->validateRegexKeywords($validated);

        WhatsappAutoReply::create(array_merge($validated, ['workspace_id' => $workspaceId]));

        return back()->with('success', 'Auto-reply rule created.');
    }

    public function update(Request $request, WhatsappAutoReply $autoReply): RedirectResponse
    {
        $this->authorise($request, $autoReply);
        $validated = $request->validate([
            'trigger_type'  => ['sometimes', 'in:keyword,welcome,away,out_of_hours'],
            'match_mode'    => ['sometimes', 'in:exact,contains,regex'],
            'keywords'      => ['nullable', 'array'],
            'schedule_json' => ['nullable', 'array'],
            'response_kind' => ['sometimes', 'in:text,template,media,flow'],
            'payload_json'  => ['sometimes', 'array'],
            'enabled'       => ['boolean'],
            'priority'      => ['integer', 'min:0'],
        ]);
        $this->validateRegexKeywords($validated);

        $autoReply->update($validated);

        return back()->with('success', 'Auto-reply rule updated.');
    }

    public function destroy(Request $request, WhatsappAutoReply $autoReply): RedirectResponse
    {
        $this->authorise($request, $autoReply);
        $autoReply->delete();

        return back()->with('success', 'Auto-reply rule deleted.');
    }

    /** Reject any keyword that is an invalid PCRE pattern when match_mode is regex. */
    private function validateRegexKeywords(array $validated): void
    {
        if (($validated['match_mode'] ?? null) !== 'regex') {
            return;
        }
        foreach ($validated['keywords'] ?? [] as $kw) {
            if (! WhatsappAutoReply::isValidRegex((string) $kw)) {
                throw ValidationException::withMessages([
                    'keywords' => "Invalid regular expression pattern: {$kw}",
                ]);
            }
        }
    }

    private function authorise(Request $request, WhatsappAutoReply $rule): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $rule->workspace_id === (int) $workspaceId, 403);
    }
}
