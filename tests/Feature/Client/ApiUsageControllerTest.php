<?php

namespace Tests\Feature\Client;

use App\Models\ApiRequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiUsageControllerTest extends TestCase
{
    use RefreshDatabase;

    private function seedLog(array $attrs = []): ApiRequestLog
    {
        return ApiRequestLog::create(array_merge([
            'method' => 'GET',
            'path' => 'api/v1/me',
            'status' => 200,
            'duration_ms' => 12,
            'created_at' => now(),
        ], $attrs));
    }

    public function test_for_user_scope_admin_sees_whole_client(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();
        $other = User::factory()->create(['client_id' => $client->id, 'client_role' => User::CLIENT_ROLE_STAFF]);

        $this->seedLog(['client_id' => $client->id, 'user_id' => $admin->id]);
        $this->seedLog(['client_id' => $client->id, 'user_id' => $other->id]);
        $this->seedLog(['client_id' => $client->id + 999, 'user_id' => 0]); // another client

        $this->assertSame(2, ApiRequestLog::forUser($admin->fresh())->count());
    }

    public function test_for_user_scope_staff_sees_only_own(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();
        $staff = User::factory()->create([
            'client_id' => $client->id,
            'role' => User::ROLE_CLIENT,
            'client_role' => User::CLIENT_ROLE_STAFF,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $this->seedLog(['client_id' => $client->id, 'user_id' => $admin->id]);
        $this->seedLog(['client_id' => $client->id, 'user_id' => $staff->id]);

        $this->assertSame(1, ApiRequestLog::forUser($staff->fresh())->count());
    }
}
