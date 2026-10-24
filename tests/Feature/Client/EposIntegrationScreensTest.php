<?php

namespace Tests\Feature\Client;

use App\Models\User;
use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Services\External\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What the automation builder and the admin status page read about linked EPOS systems.
 * The key and the host never reach a browser; status is for workspace administrators only.
 */
class EposIntegrationScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private $workspace;

    private ExternalIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $ctx = $this->createWorkspaceContext();
        $this->admin = $ctx['user'];
        $this->workspace = $ctx['workspace'];

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function ips(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        $i = new ExternalIntegration([
            'workspace_id' => $this->workspace->id, 'type' => 'epos', 'name' => 'Ali Traders',
            'base_url' => 'https://epos.secret-host.example', 'manifest_url' => 'https://epos.secret-host.example/manifest',
            'key' => 'wak_live_secret',
        ]);
        $i->manifest_version = 'v1';
        $i->manifest_fetched_at = now();
        $i->manifest = ['business' => [], 'capabilities' => [
            ['key' => 'contact.profile', 'kind' => 'query', 'group' => 'Account', 'label' => ['en' => 'My profile', 'ur' => 'میری پروفائل'], 'method' => 'GET', 'path' => '/p', 'params' => [['name' => 'phone', 'type' => 'phone', 'from' => 'sender_phone']], 'outputs' => [['path' => 'name', 'type' => 'text']], 'summary_template' => 'Hi {{name}}'],
        ]];
        $i->manifest_issues = [['key' => 'bad.one', 'reason' => 'bad key']];
        $i->save();
        $this->integration = $i;
    }

    private function staff(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_CLIENT, 'client_id' => $this->admin->client_id, 'workspace_id' => $this->workspace->id,
            'client_role' => User::CLIENT_ROLE_STAFF, 'status' => User::STATUS_ACTIVE, 'email_verified_at' => now(),
        ]);
    }

    public function test_builder_endpoint_lists_capabilities_without_key_or_host(): void
    {
        $res = $this->actingAs($this->staff())->getJson('/app/automations/epos')->assertOk();

        $res->assertJsonPath('integrations.0.id', $this->integration->id)
            ->assertJsonPath('integrations.0.name', 'Ali Traders')
            ->assertJsonPath('integrations.0.capabilities.0.key', 'contact.profile')
            ->assertJsonPath('integrations.0.capabilities.0.summary_template', 'Hi {{name}}');
        $this->assertStringNotContainsString('wak_live_secret', $res->getContent());
        $this->assertStringNotContainsString('secret-host', $res->getContent());
    }

    public function test_status_and_issues_are_for_administrators_only(): void
    {
        $this->actingAs($this->staff())->getJson('/app/automations/epos')
            ->assertJsonMissingPath('integrations.0.status')
            ->assertJsonMissingPath('integrations.0.issues');

        $this->actingAs($this->admin)->getJson('/app/automations/epos')
            ->assertJsonPath('integrations.0.status', 'connected')
            ->assertJsonPath('integrations.0.issues.0.key', 'bad.one');
    }

    public function test_builder_endpoint_never_shows_another_workspaces_link(): void
    {
        $other = $this->createWorkspaceContext()['user'];

        $this->actingAs($other)->getJson('/app/automations/epos')->assertOk()->assertJsonCount(0, 'integrations');
    }

    public function test_opening_the_builder_data_refreshes_a_stale_manifest(): void
    {
        $this->integration->forceFill(['manifest_fetched_at' => now()->subMinutes(30)])->save();
        Http::swap(new HttpFactory);
        Http::fake(['secret-host.example/manifest' => Http::response(['manifest_version' => 'v2', 'capabilities' => [
            ['key' => 'business.hours', 'kind' => 'query', 'label' => ['en' => 'Hours'], 'method' => 'GET', 'path' => '/h', 'params' => []],
        ]])]);

        $this->actingAs($this->admin)->getJson('/app/automations/epos')
            ->assertJsonPath('integrations.0.capabilities.0.key', 'business.hours');
        $this->assertSame('v2', $this->integration->fresh()->manifest_version);
    }

    public function test_a_fresh_manifest_is_not_refetched(): void
    {
        Http::swap(new HttpFactory);
        Http::fake();

        $this->actingAs($this->admin)->getJson('/app/automations/epos')->assertOk();

        Http::assertNothingSent();
    }

    public function test_status_page_is_for_administrators(): void
    {
        $this->actingAs($this->staff())->get('/app/integrations/epos')->assertForbidden();

        // Needs the page in public/build (npm run build), like the other Inertia page tests.
        $this->actingAs($this->admin)->get('/app/integrations/epos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('client/Integrations/Epos')
                ->where('integrations.0.name', 'Ali Traders')
                ->where('integrations.0.host', 'epos.secret-host.example')
                ->where('integrations.0.issues.0.key', 'bad.one')
                ->missing('integrations.0.key'));
    }

    public function test_check_now_re_reads_the_manifest_and_returns_to_the_page(): void
    {
        Http::swap(new HttpFactory);
        Http::fake([
            'secret-host.example/manifest' => Http::response(['manifest_version' => 'v3', 'capabilities' => []]),
            'secret-host.example/api/whatsapp/v1/ping' => Http::response(['ok' => true]),
        ]);

        $this->actingAs($this->admin)->post("/app/integrations/epos/{$this->integration->id}/check")->assertRedirect();

        $this->assertSame('v3', $this->integration->fresh()->manifest_version);
    }

    public function test_staff_cannot_run_check_now(): void
    {
        $this->actingAs($this->staff())->post("/app/integrations/epos/{$this->integration->id}/check")->assertForbidden();
    }
}
