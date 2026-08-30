<?php

namespace Tests\Feature\Deployment;

use App\Modules\Deployment\Models\DeploymentLog;
use App\Modules\Deployment\Models\DeploymentSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RollbackAndCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DeploymentSetting::put('deploy_url', 'https://deploy.test/deploy.php');
        DeploymentSetting::put('deploy_key', 'k');
    }

    #[Test]
    public function rollback_list_mode_returns_commits(): void
    {
        Http::fake([
            'deploy.test/*' => Http::response([
                'action' => 'rollback', 'mode' => 'list', 'current' => 'abc Now',
                'commits' => ['abc111 latest', 'def222 older', 'ghi333 oldest'],
            ], 200),
        ]);

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->postJson(route('admin.deployment.rollback'), ['mode' => 'list'])
            ->assertOk()
            ->assertJsonPath('commits.1', 'def222 older');

        $this->assertSame(0, DeploymentLog::count()); // list mode logs nothing
    }

    #[Test]
    public function rollback_execute_logs_a_reverted_row(): void
    {
        Http::fake([
            'deploy.test/*' => Http::response([
                'action' => 'rollback', 'ok' => true, 'before' => 'abc now', 'after' => 'def222 older',
                'commit_hash' => str_repeat('d', 40), 'previous_commit' => str_repeat('a', 40),
            ], 200),
        ]);

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->postJson(route('admin.deployment.rollback'), ['mode' => 'execute', 'commit' => 'def222'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $log = DeploymentLog::latest('id')->first();
        $this->assertSame('rollback', $log->action);
        $this->assertSame('reverted', $log->status);
    }

    #[Test]
    public function non_allowlisted_command_without_confirm_key_is_rejected(): void
    {
        Http::fake(); // must not be called

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->postJson(route('admin.deployment.run-command'), ['command' => 'rm -rf /'])
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, DeploymentLog::count());
    }

    #[Test]
    public function allowlisted_command_runs_without_confirm_key(): void
    {
        Http::fake([
            'deploy.test/*' => Http::response(['action' => 'cmd', 'ok' => true, 'output' => 'done'], 200),
        ]);

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->postJson(route('admin.deployment.run-command'), ['command' => 'php artisan optimize:clear'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('command', DeploymentLog::latest('id')->first()->action);
    }
}
