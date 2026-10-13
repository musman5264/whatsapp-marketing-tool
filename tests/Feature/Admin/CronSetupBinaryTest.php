<?php

namespace Tests\Feature\Admin;

use App\Services\CliPhpBinary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CronSetupBinaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_cron_page_shows_the_command_line_php_binary(): void
    {
        // The cron line the admin copies must run the command-line PHP, not the web handler's binary.
        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->get(route('admin.cron-setup.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('phpBinary', CliPhpBinary::path()));
    }
}
