<?php

namespace Tests\Feature\Realtime;

use App\Models\SystemSetting;
use App\Services\RealtimeConfig;
use App\Services\ReverbConnectionTester;
use App\Services\ReverbRuntime;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReverbWatchdogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['broadcasting.default' => 'log']);
    }

    /** Complete Reverb credentials, switch left as it is. */
    private function saveCredentials(): void
    {
        SystemSetting::set('reverb_app_id', '123456', false, 'reverb');
        SystemSetting::set('reverb_app_key', 'rkeyabc123', false, 'reverb');
        SystemSetting::set('reverb_app_secret', 'reverb-secret-value-123456789012', true, 'reverb');
        SystemSetting::set('reverb_host', 'ws.example.test', false, 'reverb');
    }

    /** Switches Reverb on with complete credentials (the state the watchdog acts on). */
    private function switchReverbOn(): void
    {
        $this->saveCredentials();
        SystemSetting::set('realtime_driver', 'reverb', false, 'reverb');
    }

    /** Replaces the health check and the process start with fixed answers, and counts starts. */
    private function fakeRuntime(bool $healthy, ?string $launchResult = null): object
    {
        $fake = new class ($healthy, $launchResult) extends ReverbRuntime {
            public int $launches = 0;

            public int $stops = 0;

            public function __construct(private bool $answer, private ?string $launchResult) {}

            public function healthy(): bool
            {
                return $this->answer;
            }

            public function launch(): ?string
            {
                $this->launches++;

                return $this->launchResult;
            }

            public function stop(): ?string
            {
                $this->stops++;

                return null;
            }

            public function publicReachable(): ?bool
            {
                return null;
            }
        };
        $this->app->instance(ReverbRuntime::class, $fake);

        return $fake;
    }

    #[Test]
    public function the_watchdog_does_nothing_while_reverb_is_off(): void
    {
        $fake = $this->fakeRuntime(false);

        $result = app(ReverbRuntime::class)->watchdog();

        $this->assertSame('skipped', $result['result']);
        $this->assertSame(0, $fake->launches);
    }

    #[Test]
    public function the_watchdog_leaves_a_running_server_alone(): void
    {
        $this->switchReverbOn();
        $fake = $this->fakeRuntime(true);

        $result = app(ReverbRuntime::class)->watchdog();

        $this->assertSame('running', $result['result']);
        $this->assertSame(0, $fake->launches);
        $this->assertSame('running', Cache::get(ReverbRuntime::HEARTBEAT_KEY)['result']);
    }

    #[Test]
    public function the_watchdog_starts_reverb_when_it_is_switched_on_and_not_answering(): void
    {
        $this->switchReverbOn();
        $fake = $this->fakeRuntime(false, null);

        $result = app(ReverbRuntime::class)->watchdog();

        $this->assertSame('started', $result['result']);
        $this->assertSame(1, $fake->launches);
        $this->assertSame('started', Cache::get(ReverbRuntime::HEARTBEAT_KEY)['result']);
    }

    #[Test]
    public function the_watchdog_reports_a_host_that_blocks_shell_commands(): void
    {
        $this->switchReverbOn();
        $this->fakeRuntime(false, 'This host does not allow shell commands (exec is disabled).');

        $result = app(ReverbRuntime::class)->watchdog();

        $this->assertSame('error', $result['result']);
        $this->assertStringContainsString('exec is disabled', Cache::get(ReverbRuntime::HEARTBEAT_KEY)['message']);
    }

    #[Test]
    public function the_watchdog_runs_every_minute_from_the_scheduler(): void
    {
        $events = collect(app(Schedule::class)->events());

        $entry = $events->first(fn ($event) => str_contains((string) $event->command, 'reverb:ensure-running'));

        $this->assertNotNull($entry, 'reverb:ensure-running is not in the schedule');
        $this->assertSame('* * * * *', $entry->expression);
    }

    #[Test]
    public function the_status_reports_the_state_and_never_the_secret(): void
    {
        $this->switchReverbOn();
        $this->fakeRuntime(true);

        $response = $this->actingAs($this->createSuperAdmin(), 'admin')
            ->getJson(route('admin.reverb-settings.status'))
            ->assertOk()
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('active', true)
            ->assertJsonStructure(['exec_available', 'pid', 'started_at', 'listen', 'last_watchdog', 'log_tail']);

        $this->assertStringNotContainsString('reverb-secret-value-123456789012', $response->getContent());
        $response->assertJsonStructure(['autostart', 'public_reachable']);
    }

    #[Test]
    public function start_now_starts_reverb_and_reports_the_result(): void
    {
        $this->switchReverbOn();
        $fake = $this->fakeRuntime(false, null);

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->post(route('admin.reverb-settings.start'))
            ->assertSessionHas('success');

        $this->assertSame(1, $fake->launches);
        $this->assertSame('started', Cache::get(ReverbRuntime::HEARTBEAT_KEY)['result']);
    }

    #[Test]
    public function the_watchdog_waits_when_automatic_start_is_off(): void
    {
        $this->switchReverbOn();
        SystemSetting::set('reverb_autostart', 'false', false, 'reverb');
        $fake = $this->fakeRuntime(false, null);

        $result = app(ReverbRuntime::class)->watchdog();

        $this->assertSame('waiting', $result['result']);
        $this->assertSame(0, $fake->launches);
    }

    #[Test]
    public function turning_reverb_on_starts_it_first_and_then_tests_it(): void
    {
        $this->saveCredentials();
        $fake = $this->fakeRuntime(false, null);
        $this->app->instance(ReverbConnectionTester::class, new class extends ReverbConnectionTester {
            public function check(array $creds): ?string
            {
                return null;
            }
        });

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->post(route('admin.reverb-settings.toggle'), ['enabled' => true])
            ->assertSessionHas('success');

        $this->assertSame(1, $fake->launches);
        $this->assertTrue(RealtimeConfig::reverbActive());
    }

    #[Test]
    public function turning_reverb_on_is_refused_when_it_cannot_start(): void
    {
        $this->saveCredentials();
        $this->fakeRuntime(false, 'This host does not allow shell commands (exec is disabled).');

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->post(route('admin.reverb-settings.toggle'), ['enabled' => true])
            ->assertSessionHas('error');

        $this->assertFalse(RealtimeConfig::reverbActive());
    }

    #[Test]
    public function turning_reverb_off_stops_the_process(): void
    {
        $this->switchReverbOn();
        $fake = $this->fakeRuntime(true, null);

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->post(route('admin.reverb-settings.toggle'), ['enabled' => false])
            ->assertSessionHas('success');

        $this->assertSame(1, $fake->stops);
        $this->assertFalse(RealtimeConfig::reverbActive());
    }
}
