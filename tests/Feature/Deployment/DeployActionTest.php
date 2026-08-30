<?php

namespace Tests\Feature\Deployment;

use App\Modules\Deployment\Models\DeploymentLog;
use App\Modules\Deployment\Models\DeploymentSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeployActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DeploymentSetting::put('deploy_url', 'https://deploy.test/deploy.php');
        DeploymentSetting::put('deploy_key', 'k');
        DeploymentSetting::put('branch', 'main');
    }

    #[Test]
    public function successful_deploy_logs_a_success_row_and_bumps_version(): void
    {
        Http::fake([
            'deploy.test/*' => Http::response([
                'action' => 'deploy',
                'ok' => true,
                'elapsed' => '4.2s',
                'git' => ['branch' => 'main', 'commit' => 'abc123 Fix things', 'commit_hash' => str_repeat('a', 40), 'previous' => str_repeat('b', 40)],
                'results' => [['fetch' => 'OK'], ['reset' => 'OK'], ['migrate' => 'Nothing to migrate.']],
            ], 200),
        ]);

        $res = $this->actingAs($this->createSuperAdmin(), 'admin')
            ->postJson(route('admin.deployment.deploy'));

        $res->assertOk()->assertJsonPath('ok', true);

        $log = DeploymentLog::latest('id')->first();
        $this->assertSame('deploy', $log->action);
        $this->assertSame('success', $log->status);
        $this->assertSame(str_repeat('a', 40), $log->commit_hash);
        $this->assertNotNull($log->completed_at);
        $this->assertStringContainsString('fetch', $log->output);

        $this->assertSame('1.0.1', DeploymentSetting::get('app_version'));
    }

    #[Test]
    public function failed_deploy_logs_a_failed_row(): void
    {
        Http::fake([
            'deploy.test/*' => Http::response(['action' => 'deploy', 'ok' => false, 'error' => 'git reset failed'], 200),
        ]);

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->postJson(route('admin.deployment.deploy'))
            ->assertOk()
            ->assertJsonPath('ok', false);

        $log = DeploymentLog::latest('id')->first();
        $this->assertSame('failed', $log->status);
        $this->assertSame('git reset failed', $log->error_output);
        $this->assertSame('1.0.0', DeploymentSetting::get('app_version')); // not bumped
    }

    #[Test]
    public function deploy_is_blocked_when_not_configured(): void
    {
        DeploymentSetting::put('deploy_url', '');

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->postJson(route('admin.deployment.deploy'))
            ->assertOk()
            ->assertJsonPath('configured', false);

        $this->assertSame('failed', DeploymentLog::latest('id')->first()->status);
    }
}
