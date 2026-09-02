<?php

namespace Tests\Feature\Client;

use App\Models\ApiRequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiUsageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The client/Api/Usage.jsx page is delivered by a later task; skip the
        // Vite manifest lookup and the Inertia page-file existence check so the
        // controller's Inertia response can still be asserted here.
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

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

    public function test_index_renders_for_client_admin_and_scopes_rows(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();
        $this->seedLog(['client_id' => $client->id, 'user_id' => $admin->id, 'path' => 'api/v1/contacts']);
        $this->seedLog(['client_id' => $client->id + 999, 'user_id' => 0, 'path' => 'api/v1/other']);

        $this->actingAs($admin)
            ->get(route('client.api-usage.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('client/Api/Usage')
                ->has('logs.data', 1)
                ->where('logs.data.0.path', 'api/v1/contacts')
            );
    }

    public function test_index_filters_by_status_bucket_and_method(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();
        $this->seedLog(['client_id' => $client->id, 'user_id' => $admin->id, 'status' => 200, 'method' => 'GET']);
        $this->seedLog(['client_id' => $client->id, 'user_id' => $admin->id, 'status' => 422, 'method' => 'POST']);
        $this->seedLog(['client_id' => $client->id, 'user_id' => $admin->id, 'status' => 500, 'method' => 'POST']);

        $this->actingAs($admin)
            ->get(route('client.api-usage.index', ['status' => '4xx']))
            ->assertInertia(fn ($p) => $p->has('logs.data', 1)->where('logs.data.0.status', 422));

        $this->actingAs($admin)
            ->get(route('client.api-usage.index', ['method' => 'POST']))
            ->assertInertia(fn ($p) => $p->has('logs.data', 2));
    }

    public function test_show_404s_for_row_outside_scope(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();
        $foreign = $this->seedLog(['client_id' => $client->id + 999, 'user_id' => 0]);

        $this->actingAs($admin)
            ->get(route('client.api-usage.show', $foreign->id))
            ->assertNotFound();
    }

    public function test_index_ignores_unparseable_date_filter_instead_of_500(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();
        $this->seedLog(['client_id' => $client->id, 'user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('client.api-usage.index', ['from' => 'not-a-date', 'to' => '???']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('logs.data', 1));
    }

    public function test_index_applies_valid_date_range_filter(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();
        $this->seedLog(['client_id' => $client->id, 'user_id' => $admin->id, 'created_at' => now()->subDays(10)]);
        $this->seedLog(['client_id' => $client->id, 'user_id' => $admin->id, 'created_at' => now()->subDays(1)]);

        $this->actingAs($admin)
            ->get(route('client.api-usage.index', ['from' => now()->subDays(3)->toDateString()]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('logs.data', 1));
    }

    public function test_stats_endpoint_returns_shape(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();
        $this->seedLog(['client_id' => $client->id, 'user_id' => $admin->id, 'status' => 200, 'created_at' => now()->subHour()]);
        $this->seedLog(['client_id' => $client->id, 'user_id' => $admin->id, 'status' => 500, 'created_at' => now()->subHour()]);

        $this->actingAs($admin)
            ->getJson(route('client.api-usage.stats'))
            ->assertOk()
            ->assertJsonStructure(['calls_24h', 'error_rate', 'p95_ms', 'top_paths']);
    }

    public function test_index_requires_auth(): void
    {
        $this->get(route('client.api-usage.index'))->assertRedirect();
    }
}
