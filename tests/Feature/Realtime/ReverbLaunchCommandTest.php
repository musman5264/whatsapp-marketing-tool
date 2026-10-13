<?php

namespace Tests\Feature\Realtime;

use App\Services\ReverbRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReverbLaunchCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** Replaces the process start with a fixed answer, so no shell command runs. */
    private function fakeLaunch(?string $result): void
    {
        $this->app->instance(ReverbRuntime::class, new class ($result) extends ReverbRuntime {
            public function __construct(private ?string $result) {}

            public function launch(): ?string
            {
                return $this->result;
            }

            public function listenHost(): string
            {
                return '0.0.0.0';
            }

            public function listenPort(): int
            {
                return 8080;
            }
        });
    }

    #[Test]
    public function reverb_launch_starts_reverb_in_the_background_and_exits_zero(): void
    {
        $this->fakeLaunch(null);

        $this->artisan('reverb:launch')
            ->expectsOutputToContain('Reverb is running on 0.0.0.0:8080.')
            ->assertExitCode(0);
    }

    #[Test]
    public function reverb_launch_exits_one_and_says_why_when_reverb_does_not_start(): void
    {
        $this->fakeLaunch('Save the Reverb app ID, key, secret and host in this page first.');

        $this->artisan('reverb:launch')
            ->expectsOutputToContain('Save the Reverb app ID')
            ->assertExitCode(1);
    }
}
