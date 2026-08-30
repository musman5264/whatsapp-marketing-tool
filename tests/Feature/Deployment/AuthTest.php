<?php

namespace Tests\Feature\Deployment;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_are_redirected(): void
    {
        $this->get(route('admin.deployment.index'))->assertRedirect();
        $this->postJson(route('admin.deployment.deploy'))->assertStatus(401);
    }

    #[Test]
    public function a_plain_admin_without_manage_settings_cannot_deploy(): void
    {
        // AdminUser with no roles/permissions — the permission middleware blocks it.
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);

        // JSON request → the permission middleware answers 403.
        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.deployment.deploy'))
            ->assertForbidden();

        // The page load is redirected away (not rendered).
        $this->actingAs($admin, 'admin')
            ->get(route('admin.deployment.index'))
            ->assertStatus(302);
    }

    #[Test]
    public function a_super_admin_is_authorised(): void
    {
        // Assert authorisation via a JSON endpoint (avoids the Vite manifest that
        // the Inertia page render needs, matching the other admin feature tests).
        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->getJson(route('admin.deployment.status'))
            ->assertOk()
            ->assertJsonPath('configured', false); // not configured yet, but reachable
    }
}
