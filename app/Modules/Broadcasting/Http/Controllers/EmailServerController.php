<?php

namespace App\Modules\Broadcasting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Broadcasting\Models\WorkspaceSmtpConfig;
use App\Services\Mail\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailServerController extends Controller
{
    public function __construct(
        protected MailService $mailService
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);
        $config = WorkspaceSmtpConfig::where('workspace_id', $workspaceId)->first();

        return Inertia::render('Broadcasting/EmailServer/Index', [
            'config' => $config ? [
                'id' => $config->id,
                'host' => $config->host,
                'port' => $config->port,
                'username' => $config->username,
                'password' => '', // never expose to frontend
                'encryption' => $config->encryption,
                'from_email' => $config->from_email,
                'from_name' => $config->from_name,
                'is_active' => $config->is_active,
                'summary' => $config->summary,
            ] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);

        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'encryption' => ['required', 'string', 'in:tls,ssl,none'],
            'from_email' => ['required', 'email'],
            'from_name' => ['required', 'string', 'max:255'],
        ]);

        WorkspaceSmtpConfig::where('workspace_id', $workspaceId)->delete();

        WorkspaceSmtpConfig::create(array_merge($validated, [
            'workspace_id' => $workspaceId,
            'is_active' => true,
        ]));

        return back()->with('success', __('Email server configuration saved.'));
    }

    public function update(Request $request): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);

        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['required', 'string', 'in:tls,ssl,none'],
            'from_email' => ['required', 'email'],
            'from_name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $config = WorkspaceSmtpConfig::where('workspace_id', $workspaceId)->firstOrFail();

        $data = [
            'host' => $validated['host'],
            'port' => $validated['port'],
            'username' => $validated['username'],
            'encryption' => $validated['encryption'],
            'from_email' => $validated['from_email'],
            'from_name' => $validated['from_name'],
            'is_active' => $validated['is_active'] ?? $config->is_active,
        ];

        if (! empty($validated['password'])) {
            $data['password'] = $validated['password'];
        }

        $config->update($data);

        return back()->with('success', __('Email server configuration updated.'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);
        WorkspaceSmtpConfig::where('workspace_id', $workspaceId)->delete();

        return back()->with('success', __('Email server configuration removed.'));
    }

    public function testEmail(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);

        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $smtpToUse = WorkspaceSmtpConfig::where('workspace_id', $workspaceId)->where('is_active', true)->first();

        if (! $smtpToUse) {
            return response()->json(['message' => __('No workspace SMTP configured. Please add your SMTP configuration first.')], 422);
        }

        try {
            $appName = config('app.name');
            $this->mailService->sendRaw(
                $smtpToUse,
                $validated['email'],
                __('Test email from :app', ['app' => $appName]),
                '<p>' . __('This is a test email. Your SMTP configuration is working correctly.') . '</p>'
            );

            return response()->json(['message' => __('Test email sent successfully.')]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}
