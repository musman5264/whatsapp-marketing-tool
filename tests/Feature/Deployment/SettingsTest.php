<?php

namespace Tests\Feature\Deployment;

use App\Modules\Deployment\Models\DeploymentSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function secret_values_are_encrypted_at_rest_and_decrypted_on_read(): void
    {
        DeploymentSetting::put('deploy_key', 'super-secret-123');

        $raw = DB::table('deployment_settings')->where('key', 'deploy_key')->value('value');
        $this->assertNotSame('super-secret-123', $raw);
        $this->assertNotEmpty($raw);

        $this->assertSame('super-secret-123', DeploymentSetting::get('deploy_key'));
    }

    #[Test]
    public function non_secret_values_are_stored_plaintext(): void
    {
        DeploymentSetting::put('branch', 'release');

        $raw = DB::table('deployment_settings')->where('key', 'branch')->value('value');
        $this->assertSame('release', $raw);
        $this->assertSame('release', DeploymentSetting::get('branch'));
    }

    #[Test]
    public function defaults_apply_when_unset(): void
    {
        $this->assertSame('main', DeploymentSetting::get('branch'));
        $this->assertSame('1.0.0', DeploymentSetting::get('app_version'));
        $this->assertSame('', DeploymentSetting::get('deploy_url'));
        $this->assertSame('fallback', DeploymentSetting::get('nonexistent_key', 'fallback'));
    }

    #[Test]
    public function admin_can_save_settings_and_blank_secret_keeps_existing(): void
    {
        $admin = $this->createSuperAdmin();
        DeploymentSetting::put('deploy_key', 'original-key');

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.deployment.settings.update'), [
                'repo_url' => 'https://github.com/acme/app.git',
                'branch' => 'main',
                'deploy_url' => 'https://wa.example.com/deploy.php',
                'deploy_key' => '', // blank = keep
                'auto_migrate' => true,
            ])
            ->assertOk();

        $this->assertSame('https://github.com/acme/app.git', DeploymentSetting::get('repo_url'));
        $this->assertSame('original-key', DeploymentSetting::get('deploy_key'));
        $this->assertSame('1', DeploymentSetting::get('auto_migrate'));
    }
}
