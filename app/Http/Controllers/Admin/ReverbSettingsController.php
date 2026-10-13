<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\RealtimeConfig;
use App\Services\ReverbRuntime;
use App\Services\ReverbConnectionTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin Reverb settings: auto-generate credentials, save them, test that a Reverb
 * server answers, and switch Reverb on or off as the realtime server.
 */
class ReverbSettingsController extends Controller
{
    private const KEYS = ['reverb_app_id', 'reverb_app_key', 'reverb_app_secret', 'reverb_host', 'reverb_port', 'reverb_scheme', 'reverb_listen_host', 'reverb_listen_port'];

    private const SECRET = 'reverb_app_secret';

    private const MASK = '••••••••';

    public function index(ReverbRuntime $runtime): Response
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $value = (string) (SystemSetting::get($key) ?? '');
            $settings[$key] = $key === self::SECRET && $value !== '' ? self::MASK : $value;
        }

        // Defaults from the site address until the admin saves their own values.
        $settings['reverb_host'] = $settings['reverb_host'] !== ''
            ? $settings['reverb_host']
            : (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $settings['reverb_port'] = $settings['reverb_port'] !== '' ? $settings['reverb_port'] : '443';
        $settings['reverb_scheme'] = $settings['reverb_scheme'] !== '' ? $settings['reverb_scheme'] : 'https';
        $settings['reverb_listen_host'] = $settings['reverb_listen_host'] !== '' ? $settings['reverb_listen_host'] : '0.0.0.0';
        $settings['reverb_listen_port'] = $settings['reverb_listen_port'] !== '' ? $settings['reverb_listen_port'] : '8080';

        return Inertia::render('Admin/ReverbSettings/Index', [
            'settings' => $settings,
            'configured' => RealtimeConfig::adminReverbCredentials() !== null,
            'active' => RealtimeConfig::reverbActive(),
            'runtime' => $runtime->status(),
        ]);
    }

    /** New random credentials. Nothing is saved here; the admin presses Save. */
    public function generate(): JsonResponse
    {
        return response()->json([
            'reverb_app_id' => (string) random_int(100000, 999999),
            'reverb_app_key' => Str::random(20),
            'reverb_app_secret' => Str::random(40),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reverb_app_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'reverb_app_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
            'reverb_app_secret' => ['nullable', 'string', 'max:255'],
            'reverb_host' => ['nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/'],
            'reverb_port' => ['nullable', 'integer', 'between:1,65535'],
            'reverb_scheme' => ['nullable', 'string', 'in:http,https'],
            'reverb_listen_host' => ['nullable', 'string', 'in:0.0.0.0,127.0.0.1'],
            'reverb_listen_port' => ['nullable', 'integer', 'between:1,65535'],
        ]);

        foreach ($validated as $key => $value) {
            if ($value === null) {
                continue;
            }
            // The masked placeholder means "keep the saved secret".
            if ($key === self::SECRET && preg_match('/^•+$/u', (string) $value)) {
                continue;
            }
            SystemSetting::set($key, (string) $value, $key === self::SECRET, 'reverb');
        }

        // Credentials changed while Reverb is on: the running Reverb server must reload them.
        if (RealtimeConfig::reverbActive()) {
            $this->signalRestart();
        }

        return back()->with('success', 'Reverb settings saved.');
    }

    /** Switch Reverb on or off as the realtime server. On needs a passing connection test. */
    public function toggle(Request $request, ReverbConnectionTester $tester): RedirectResponse
    {
        $enabled = $request->boolean('enabled');

        if (! $enabled) {
            SystemSetting::set('realtime_driver', 'auto', false, 'reverb');

            return back()->with('success', 'Reverb is off. Realtime uses Pusher (or the .env settings).');
        }

        $creds = RealtimeConfig::adminReverbCredentials();
        if ($creds === null) {
            return back()->with('error', 'Fill in the app ID, key, secret and host, then save before turning Reverb on.');
        }

        $reason = $tester->check($creds);
        if ($reason !== null) {
            return back()->with('error', 'Reverb did not answer, so it was not turned on. '.$reason);
        }

        SystemSetting::set('realtime_driver', 'reverb', false, 'reverb');
        $this->signalRestart();

        return back()->with('success', 'Reverb is now the realtime server.');
    }

    public function test(ReverbConnectionTester $tester): JsonResponse
    {
        $creds = RealtimeConfig::adminReverbCredentials();
        if ($creds === null) {
            return response()->json(['success' => false, 'message' => 'Fill in the app ID, key, secret and host, then save before turning Reverb on.'], 422);
        }

        $reason = $tester->check($creds);

        return $reason === null
            ? response()->json(['success' => true, 'message' => 'Reverb accepted the test event.'])
            : response()->json(['success' => false, 'message' => $reason], 422);
    }

    /** Live state for the status card (polled by the page). */
    public function status(ReverbRuntime $runtime): JsonResponse
    {
        return response()->json($runtime->status());
    }

    /** Start Reverb now, without waiting for the next watchdog run. */
    public function start(ReverbRuntime $runtime): RedirectResponse
    {
        $error = $runtime->launch();
        $runtime->recordManualStart($error);

        return $error === null
            ? back()->with('success', 'Reverb is running.')
            : back()->with('error', $error);
    }

    /** Ask the running Reverb server to restart; the watchdog starts it again with the saved settings. */
    public function restart(ReverbRuntime $runtime): RedirectResponse
    {
        $runtime->requestRestart();

        return back()->with('success', 'Restart requested. Reverb starts again within a minute.');
    }

    /** Asks a running Reverb server to restart (it watches this cache flag). */
    private function signalRestart(): void
    {
        app(ReverbRuntime::class)->requestRestart();
    }
}
