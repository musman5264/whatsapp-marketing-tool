<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Pusher\Pusher;

class PusherSettingsController extends Controller
{
    private const KEYS = ['pusher_app_id', 'pusher_app_key', 'pusher_app_secret', 'pusher_app_cluster'];

    public function index(): Response
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $model = SystemSetting::where('key', $key)->first();
            $settings[$key] = $model
                ? ($model->is_secret && strlen($model->attributes['value'] ?? '') > 0 ? '••••••••' : $model->value)
                : '';
        }

        $settings['pusher_enabled'] = SystemSetting::get('pusher_enabled', 'false');
        $configured = ! empty(SystemSetting::get('pusher_app_key')) && ! empty(SystemSetting::get('pusher_app_secret'));

        return Inertia::render('Admin/PusherSettings/Index', [
            'settings'   => $settings,
            'configured' => $configured,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pusher_app_id'      => ['nullable', 'string', 'max:64'],
            'pusher_app_key'     => ['nullable', 'string', 'max:128'],
            'pusher_app_secret'  => ['nullable', 'string', 'max:255'],
            'pusher_app_cluster' => ['nullable', 'string', 'max:32'],
            'pusher_enabled'     => ['nullable', 'string', 'in:true,false'],
        ]);

        $secrets = ['pusher_app_secret'];

        foreach ($validated as $key => $value) {
            if ($value === null) {
                continue;
            }
            $isSecret = in_array($key, $secrets, true);
            // Skip masked placeholder — keep existing secret
            if ($isSecret && preg_match('/^•+$/', (string) $value)) {
                continue;
            }
            SystemSetting::set($key, $value, $isSecret, 'pusher');
        }

        return back()->with('success', 'Pusher settings saved.');
    }

    public function test(Request $request)
    {
        $key     = SystemSetting::get('pusher_app_key');
        $secret  = SystemSetting::get('pusher_app_secret');
        $appId   = SystemSetting::get('pusher_app_id');
        $cluster = SystemSetting::get('pusher_app_cluster', 'mt1');

        if (! $key || ! $secret || ! $appId) {
            return response()->json(['success' => false, 'message' => 'Pusher credentials not configured.'], 422);
        }

        try {
            $pusher = new Pusher($key, $secret, $appId, [
                'cluster' => $cluster,
                'useTLS'  => true,
            ]);

            // Trigger a test event on a private channel to verify credentials
            $pusher->trigger('test-channel', 'test-event', ['message' => 'Connection test']);

            return response()->json(['success' => true, 'message' => 'Pusher connection successful.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Connection failed: '.$e->getMessage()], 422);
        }
    }
}
