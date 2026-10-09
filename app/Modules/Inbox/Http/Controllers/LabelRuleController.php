<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inbox\Models\InboxLabelRule;
use App\Modules\Inbox\Services\LabelRuleEvaluator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LabelRuleController extends Controller
{
    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        InboxLabelRule::create(array_merge($this->validated($request, $wid), ['workspace_id' => $wid, 'hits' => 0]));

        return back()->with('success', 'Rule created.');
    }

    public function update(Request $request, InboxLabelRule $rule): RedirectResponse
    {
        abort_unless((int) $rule->workspace_id === $this->workspaceId($request), 403);
        $rule->update($this->validated($request, $rule->workspace_id));

        return back()->with('success', 'Rule updated.');
    }

    public function destroy(Request $request, InboxLabelRule $rule): RedirectResponse
    {
        abort_unless((int) $rule->workspace_id === $this->workspaceId($request), 403);
        $rule->delete();

        return back()->with('success', 'Rule deleted.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, int $wid): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'inbox_label_id' => ['required', 'integer',
                Rule::exists('inbox_labels', 'id')->where('workspace_id', $wid)],
            'channel_account_id' => ['nullable', 'integer',
                Rule::exists('channel_accounts', 'id')->where('workspace_id', $wid)],
            'match_type' => ['required', Rule::in(InboxLabelRule::MATCH_TYPES)],
            'pattern' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $type = $validated['match_type'];
        $pattern = isset($validated['pattern']) ? trim($validated['pattern']) : null;

        if (in_array($type, InboxLabelRule::PATTERN_TYPES, true)) {
            if ($pattern === null || $pattern === '') {
                throw ValidationException::withMessages(['pattern' => 'A pattern is required for this match type.']);
            }
            if ($type === InboxLabelRule::MATCH_REGEX && ! LabelRuleEvaluator::isValidRegex($pattern)) {
                throw ValidationException::withMessages(['pattern' => 'This regular expression is not valid.']);
            }
            if (in_array($type, [InboxLabelRule::MATCH_KEYWORD_ANY, InboxLabelRule::MATCH_KEYWORD_ALL], true)
                && LabelRuleEvaluator::keywords($pattern) === []) {
                throw ValidationException::withMessages(['pattern' => 'Enter at least one keyword.']);
            }
        } else {
            $pattern = null;
        }

        return [
            'name' => $validated['name'],
            'inbox_label_id' => $validated['inbox_label_id'],
            'channel_account_id' => $validated['channel_account_id'] ?? null,
            'match_type' => $type,
            'pattern' => $pattern,
            'is_active' => $validated['is_active'] ?? true,
            'priority' => $validated['priority'] ?? 100,
        ];
    }
}
