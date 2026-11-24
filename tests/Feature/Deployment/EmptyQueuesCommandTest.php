<?php

namespace Tests\Feature\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmptyQueuesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedJobs(): void
    {
        foreach (['whatsapp', 'default', 'automation'] as $queue) {
            DB::table('jobs')->insert([
                'queue' => $queue, 'payload' => '{}', 'attempts' => 0,
                'available_at' => time(), 'created_at' => time(),
            ]);
        }
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'boom', 'failed_at' => now(),
        ]);
    }

    #[Test]
    public function without_apply_it_only_counts(): void
    {
        $this->seedJobs();

        $this->artisan('queue:empty-all')
            ->expectsOutputToContain('3 waiting job(s)')
            ->expectsOutputToContain('1 failed job(s)')
            ->assertExitCode(0);

        $this->assertSame(3, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    #[Test]
    public function with_apply_it_empties_waiting_and_failed_jobs(): void
    {
        $this->seedJobs();

        $this->artisan('queue:empty-all --apply')->assertExitCode(0);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    #[Test]
    public function failed_only_leaves_waiting_jobs_alone(): void
    {
        $this->seedJobs();

        $this->artisan('queue:empty-all --failed-only --apply')->assertExitCode(0);

        $this->assertSame(3, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}
