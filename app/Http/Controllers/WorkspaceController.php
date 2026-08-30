<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    /**
     * List workspaces the user can access (for switcher).
     */
    public function index(Request $request): Response
    {
        $workspaces = $request->user()->accessibleWorkspaces();

        return Inertia::render('client/Workspaces/Index', [
            'workspaces' => $workspaces->map(fn (Workspace $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'is_owner' => $w->owner_id === $request->user()->id,
            ]),
        ]);
    }

    /**
     * Switch current workspace (set session and update user.workspace_id).
     */
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')],
        ]);

        $workspace = Workspace::findOrFail($validated['workspace_id']);

        $this->authorize('view', $workspace);

        $request->session()->put('current_workspace_id', $workspace->id);
        $request->user()->update(['workspace_id' => $workspace->id]);

        return redirect()->intended(route('client.dashboard'));
    }

    /**
     * Create a new workspace (owner is current user).
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Workspace::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $workspace = Workspace::create([
            'name' => $validated['name'],
            'owner_id' => $request->user()->id,
            'client_id' => $request->user()->client_id,
            'default_locale' => $request->user()->locale ?? 'en',
            'currency_code' => $request->user()->display_currency,
        ]);

        $workspace->members()->attach($request->user()->id, ['role' => 'owner']);

        $request->session()->put('current_workspace_id', $workspace->id);
        $request->user()->update(['workspace_id' => $workspace->id]);

        return redirect()->route('client.dashboard')->with('success', __('Workspace created.'));
    }
}
