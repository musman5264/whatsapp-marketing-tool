<?php

namespace Tests\Feature\Realtime;

use App\Models\SystemSetting;
use App\Providers\ReverbSettingsServiceProvider;
use App\Services\RealtimeConfig;
use App\Services\ReverbRuntime;
use App\Services\ReverbConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReverbSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'reverb-secret-value-123456789012';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['broadcasting.default' => 'log']);
        // Starting Reverb is not part of these tests: the runtime always reports it running.
        $this->app->instance(ReverbRuntime::class, new class extends ReverbRuntime {
            public function healthy(): bool
            {
                return true;
            }

            public function launch(): ?string
            {
                return null;
            }

            public function stop(): ?string
            {
                return null;
            }

            public function publicReachable(): ?bool
            {
                return null;
            }
        });
    }

    /** Replaces the Reverb connection test with a fixed answer (null = reachable). */
    private function fakeTester(?string $answer): void
    {
        $this->app->instance(ReverbConnectionTester::class, new class ($answer) extends ReverbConnectionTester {
            public function __construct(private ?string $answer) {}

            public function check(array $creds): ?string
            {
                return $this->answer;
            }
        });
    }

    private function saveCompleteReverb(): void
    {
        SystemSetting::set('reverb_app_id', '123456', false, 'reverb');
        SystemSetting::set('reverb_app_key', 'rkeyabc123', false, 'reverb');
        SystemSetting::set('reverb_app_secret', self::SECRET, true, 'reverb');
        SystemSetting::set('reverb_host', 'ws.example.test', false, 'reverb');
        SystemSetting::set('reverb_port', '443', false, 'reverb');
        SystemSetting::set('reverb_scheme', 'https', false, 'reverb');
    }

    private function admin(): \App\Models\AdminUser
    {
        return $this->createSuperAdmin();
    }

    #[Test]
    public function an_admin_without_the_settings_permission_cannot_open_the_page(): void
    {
        $noPermission = \App\Models\AdminUser::factory()->create(['status' => \App\Models\AdminUser::STATUS_ACTIVE]);

        // Pages redirect to the admin dashboard with "Access denied"; API calls get 403.
        $this->actingAs($noPermission, 'admin')->get(route('admin.reverb-settings.index'))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($noPermission, 'admin')->putJson(route('admin.reverb-settings.update'), [])->assertForbidden();
    }

    #[Test]
    public function the_page_defaults_host_and_port_from_the_site_and_never_sends_the_secret(): void
    {
        config(['app.url' => 'https://wa.example.test']);
        SystemSetting::set('reverb_app_secret', self::SECRET, true, 'reverb');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reverb-settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ReverbSettings/Index')
                ->where('settings.reverb_host', 'wa.example.test')
                ->where('settings.reverb_port', '443')
                ->where('settings.reverb_scheme', 'https')
                ->where('settings.reverb_app_secret', '••••••••')
                ->where('configured', false)
                ->where('active', false));
    }

    #[Test]
    public function generate_returns_new_credentials_and_saves_nothing(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->postJson(route('admin.reverb-settings.generate'))
            ->assertOk();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $response->json('reverb_app_id'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/', $response->json('reverb_app_key'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $response->json('reverb_app_secret'));
        $this->assertSame(0, SystemSetting::where('key', 'like', 'reverb_%')->count());
    }

    #[Test]
    public function saved_secret_is_encrypted_at_rest_and_the_mask_keeps_it(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->put(route('admin.reverb-settings.update'), [
            'reverb_app_id' => '123456',
            'reverb_app_key' => 'rkeyabc123',
            'reverb_app_secret' => self::SECRET,
            'reverb_host' => 'ws.example.test',
            'reverb_port' => 443,
            'reverb_scheme' => 'https',
        ])->assertRedirect();

        $raw = DB::table('system_settings')->where('key', 'reverb_app_secret')->value('value');
        $this->assertNotSame(self::SECRET, $raw);
        $this->assertSame(self::SECRET, SystemSetting::get('reverb_app_secret'));

        // Saving again with the masked placeholder must keep the stored secret.
        $this->actingAs($admin, 'admin')->put(route('admin.reverb-settings.update'), [
            'reverb_app_id' => '123456',
            'reverb_app_key' => 'rkeyabc123',
            'reverb_app_secret' => '••••••••',
            'reverb_host' => 'ws.example.test',
            'reverb_port' => 443,
            'reverb_scheme' => 'https',
        ])->assertRedirect();

        $this->assertSame(self::SECRET, SystemSetting::get('reverb_app_secret'));
    }

    #[Test]
    public function an_invalid_host_or_port_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'admin')->put(route('admin.reverb-settings.update'), [
            'reverb_host' => 'bad host;rm',
            'reverb_port' => 70000,
        ])->assertSessionHasErrors(['reverb_host', 'reverb_port']);

        $this->assertSame(0, SystemSetting::where('key', 'reverb_host')->count());
    }

    #[Test]
    public function turning_reverb_on_needs_complete_credentials(): void
    {
        $this->fakeTester(null);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.reverb-settings.toggle'), ['enabled' => true])
            ->assertSessionHas('error');

        $this->assertFalse(RealtimeConfig::reverbActive());
    }

    #[Test]
    public function turning_reverb_on_fails_when_the_server_does_not_answer(): void
    {
        $this->saveCompleteReverb();
        $this->fakeTester('Could not reach Reverb at ws.example.test:443');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.reverb-settings.toggle'), ['enabled' => true])
            ->assertSessionHas('error');

        $this->assertFalse(RealtimeConfig::reverbActive());
        $this->assertNull(Cache::get('laravel:reverb:restart'));
    }

    #[Test]
    public function turning_reverb_on_switches_realtime_over_to_reverb(): void
    {
        $this->saveCompleteReverb();
        $this->fakeTester(null);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.reverb-settings.toggle'), ['enabled' => true])
            ->assertSessionHas('success');

        $this->assertTrue(RealtimeConfig::reverbActive());
        $rt = RealtimeConfig::resolve();
        $this->assertSame('reverb', $rt->driver);
        $this->assertSame('ws.example.test', $rt->wsHost);
        $this->assertTrue($rt->canBroadcast);
    }

    #[Test]
    public function turning_reverb_off_returns_realtime_to_the_previous_setup(): void
    {
        $this->saveCompleteReverb();
        SystemSetting::set('realtime_driver', 'reverb', false, 'reverb');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.reverb-settings.toggle'), ['enabled' => false])
            ->assertSessionHas('success');

        $this->assertSame('auto', SystemSetting::get('realtime_driver'));
        $this->assertFalse(RealtimeConfig::reverbActive());
        $this->assertNotSame('reverb', RealtimeConfig::resolve()->driver);
    }

    #[Test]
    public function the_provider_copies_saved_values_into_the_broadcaster_and_the_reverb_app(): void
    {
        $this->saveCompleteReverb();
        SystemSetting::set('realtime_driver', 'reverb', false, 'reverb');

        ReverbSettingsServiceProvider::apply();

        $this->assertSame('reverb', config('broadcasting.default'));
        $this->assertSame('rkeyabc123', config('broadcasting.connections.reverb.key'));
        $this->assertSame(self::SECRET, config('broadcasting.connections.reverb.secret'));
        $this->assertSame('ws.example.test', config('broadcasting.connections.reverb.options.host'));
        $this->assertSame('rkeyabc123', config('reverb.apps.apps.0.key'));
        $this->assertSame(self::SECRET, config('reverb.apps.apps.0.secret'));
        $this->assertSame('123456', config('reverb.apps.apps.0.app_id'));
    }

    #[Test]
    public function the_provider_loads_the_server_app_while_reverb_is_switched_off(): void
    {
        $this->saveCompleteReverb();

        ReverbSettingsServiceProvider::apply();

        // A manual start while off runs the saved app, but the broadcaster stays where it was.
        $this->assertSame('log', config('broadcasting.default'));
        $this->assertSame('rkeyabc123', config('reverb.apps.apps.0.key'));
        $this->assertSame('123456', config('reverb.apps.apps.0.app_id'));
    }

    #[Test]
    public function the_test_button_reports_missing_and_failing_and_working_setups(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->postJson(route('admin.reverb-settings.test'))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->saveCompleteReverb();
        $this->fakeTester('Could not reach Reverb');
        $this->actingAs($admin, 'admin')->postJson(route('admin.reverb-settings.test'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Could not reach Reverb');

        $this->fakeTester(null);
        $this->actingAs($admin, 'admin')->postJson(route('admin.reverb-settings.test'))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function a_failed_check_does_not_repeat_the_signed_request(): void
    {
        // No fake here: the real tester tries a closed local port and must not echo the URL.
        $this->saveCompleteReverb();
        SystemSetting::set('reverb_host', '127.0.0.1', false, 'reverb');
        SystemSetting::set('reverb_port', '1', false, 'reverb');
        SystemSetting::set('reverb_scheme', 'http', false, 'reverb');

        $response = $this->actingAs($this->admin(), 'admin')->postJson(route('admin.reverb-settings.test'))
            ->assertStatus(422);

        $message = (string) $response->json('message');
        $this->assertStringStartsWith('Could not reach Reverb at 127.0.0.1:1', $message);
        $this->assertStringNotContainsString('auth_signature', $message);
        $this->assertStringNotContainsString('auth_key', $message);
    }
}
