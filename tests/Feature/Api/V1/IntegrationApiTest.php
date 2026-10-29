<?php

namespace Tests\Feature\Api\V1;

use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Services\External\HostResolver;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'wak_live_3f9c_super_secret';

    private const MANIFEST_URL = 'https://epos.example.com/api/whatsapp/v1/manifest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function ips(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ['93.184.216.34'];
            }
        });
    }

    private function capability(string $key = 'contact.profile', array $over = []): array
    {
        return array_merge([
            'key' => $key,
            'kind' => 'query',
            'group' => 'Account',
            'label' => ['en' => 'My profile', 'ur' => 'میری پروفائل'],
            'method' => 'GET',
            'path' => '/api/whatsapp/v1/profile',
            'params' => [['name' => 'phone', 'type' => 'phone', 'from' => 'sender_phone']],
            'outputs' => [['path' => 'name', 'type' => 'text']],
            'surprise_field' => 'ignored',
        ], $over);
    }

    private function fakeManifest(array $caps, string $version = '2026-10-24.3', int $status = 200): void
    {
        Http::swap(new HttpFactory); // a second fake() would not override the first
        Http::fake([
            'epos.example.com/api/whatsapp/v1/manifest' => $status === 200
                ? Http::response(['manifest_version' => $version, 'capabilities' => $caps])
                : Http::response(['error' => 'unauthorized'], $status),
            'epos.example.com/api/whatsapp/v1/ping' => Http::response(['ok' => true]),
        ]);
    }

    private function body(array $over = []): array
    {
        return array_merge([
            'type' => 'epos',
            'name' => 'Ali Traders',
            'base_url' => 'https://epos.example.com',
            'manifest_url' => self::MANIFEST_URL,
            'key' => self::KEY,
            'manifest_version' => '2026-10-24.3',
        ], $over);
    }

    private function token(array $abilities = [ApiAbilities::INTEGRATIONS_WRITE]): array
    {
        $ctx = $this->createWorkspaceContext();

        return [$ctx['user']->createToken('t', $abilities)->plainTextToken, $ctx];
    }

    public function test_create_returns_201_and_counts_capabilities(): void
    {
        $this->fakeManifest([$this->capability(), $this->capability('products.search')]);
        [$token, $ctx] = $this->token();

        $this->withToken($token)->postJson('/api/v1/integrations', $this->body())
            ->assertStatus(201)
            ->assertJson(['status' => 'connected', 'capabilities' => 2, 'manifest_version' => '2026-10-24.3']);

        $this->assertDatabaseHas('external_integrations', ['workspace_id' => $ctx['workspace']->id, 'type' => 'epos']);
    }

    public function test_creating_twice_updates_instead_of_duplicating(): void
    {
        $this->fakeManifest([$this->capability()]);
        [$token] = $this->token();

        $first = $this->withToken($token)->postJson('/api/v1/integrations', $this->body())->assertStatus(201);
        $second = $this->withToken($token)->postJson('/api/v1/integrations', $this->body(['base_url' => 'https://epos.example.com/']))->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, ExternalIntegration::count());
    }

    public function test_bad_key_is_rejected_and_nothing_is_saved(): void
    {
        $this->fakeManifest([], status: 401);
        [$token] = $this->token();

        $res = $this->withToken($token)->postJson('/api/v1/integrations', $this->body())
            ->assertStatus(422)
            ->assertJson(['error' => 'manifest_unreachable']);

        $this->assertStringNotContainsString(self::KEY, $res->getContent());
        $this->assertSame(0, ExternalIntegration::count());
    }

    public function test_private_base_url_is_rejected_at_create(): void
    {
        Http::fake();
        [$token] = $this->token();

        $this->withToken($token)->postJson('/api/v1/integrations', $this->body([
            'base_url' => 'https://169.254.169.254',
            'manifest_url' => 'https://169.254.169.254/manifest',
        ]))->assertStatus(422)->assertJson(['error' => 'manifest_unreachable']);

        Http::assertNothingSent();
        $this->assertSame(0, ExternalIntegration::count());
    }

    public function test_validation_errors_are_field_level(): void
    {
        [$token] = $this->token();

        $this->withToken($token)->postJson('/api/v1/integrations', ['type' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'base_url', 'manifest_url', 'key']);
    }

    public function test_token_without_ability_gets_403_and_anonymous_gets_401(): void
    {
        $this->getJson('/api/v1/integrations')->assertStatus(401);

        [$token] = $this->token([ApiAbilities::CONTACTS_READ]);
        $this->withToken($token)->getJson('/api/v1/integrations')->assertStatus(403);
    }

    public function test_key_is_encrypted_at_rest_and_never_returned(): void
    {
        $this->fakeManifest([$this->capability()]);
        [$token] = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/integrations', $this->body())->json('id');

        $stored = DB::table('external_integrations')->where('id', $id)->value('key');
        $this->assertStringNotContainsString(self::KEY, $stored);
        $this->assertSame(self::KEY, ExternalIntegration::find($id)->key);

        $list = $this->withToken($token)->getJson('/api/v1/integrations')->assertOk();
        $one = $this->withToken($token)->getJson("/api/v1/integrations/{$id}")->assertOk();
        $this->assertStringNotContainsString(self::KEY, $list->getContent());
        $this->assertStringNotContainsString(self::KEY, $one->getContent());
        $this->assertStringNotContainsString('"key"', $one->getContent());
    }

    public function test_other_workspace_cannot_see_or_change_it(): void
    {
        $this->fakeManifest([$this->capability()]);
        [$token] = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/integrations', $this->body())->json('id');
        [$otherToken] = $this->token();
        $this->app['auth']->forgetGuards(); // otherwise the first token's user is reused

        $this->withToken($otherToken)->getJson("/api/v1/integrations/{$id}")->assertNotFound();
        $this->withToken($otherToken)->putJson("/api/v1/integrations/{$id}", ['name' => 'x'])->assertNotFound();
        $this->withToken($otherToken)->deleteJson("/api/v1/integrations/{$id}")->assertNotFound();
        $this->withToken($otherToken)->postJson("/api/v1/integrations/{$id}/check")->assertNotFound();
    }

    public function test_invalid_capability_is_skipped_and_reported(): void
    {
        $this->fakeManifest([
            $this->capability(),
            $this->capability('bad.kind', ['kind' => 'wizard']),
            $this->capability('bad.path', ['path' => 'https://evil.example.com/x']),
            $this->capability('bad.param', ['params' => [['name' => 'p', 'type' => 'text', 'from' => 'telepathy']]]),
        ]);
        [$token] = $this->token();

        $res = $this->withToken($token)->postJson('/api/v1/integrations', $this->body())->assertStatus(201);

        $this->assertSame(1, $res->json('capabilities'));
        $issues = collect(ExternalIntegration::first()->manifest_issues)->pluck('key')->all();
        $this->assertEqualsCanonicalizing(['bad.kind', 'bad.path', 'bad.param'], $issues);
    }

    public function test_text_param_keeps_a_valid_max_length_and_a_bad_one_skips_the_capability(): void
    {
        $text = fn (mixed $max) => ['name' => 'message', 'type' => 'text', 'from' => 'chat_answer', 'max_length' => $max];
        $this->fakeManifest([
            $this->capability('alert.push', ['kind' => 'action', 'method' => 'POST', 'params' => [$text(1000)]]),
            $this->capability('str.max', ['params' => [$text('1500')]]),
            $this->capability('float.max', ['params' => [$text(800.0)]]),
            $this->capability('bad.max', ['params' => [$text(0)]]),
            $this->capability('bad.max2', ['params' => [$text('lots')]]),
        ]);
        [$token] = $this->token();

        $this->withToken($token)->postJson('/api/v1/integrations', $this->body())->assertStatus(201);

        $row = ExternalIntegration::first();
        $this->assertSame(1000, $row->manifest['capabilities'][0]['params'][0]['max_length']);
        $this->assertSame(1500, $row->manifest['capabilities'][1]['params'][0]['max_length']);
        $this->assertSame(800, $row->manifest['capabilities'][2]['params'][0]['max_length']);
        $this->assertEqualsCanonicalizing(['bad.max', 'bad.max2'], collect($row->manifest_issues)->pluck('key')->all());
    }

    public function test_text_param_keeps_its_enum_and_a_malformed_enum_skips_the_capability(): void
    {
        $kind = fn (mixed $enum) => ['name' => 'kind', 'type' => 'text', 'from' => 'fixed', 'optional' => true, 'enum' => $enum];
        $this->fakeManifest([
            $this->capability('alert.push', ['kind' => 'action', 'method' => 'POST', 'params' => [$kind(['order', 'complaint'])]]),
            $this->capability('bad.enum', ['params' => [$kind('order')]]),
            $this->capability('empty.enum', ['params' => [$kind([])]]),
            $this->capability('nested.enum', ['params' => [$kind([['order']])]]),
        ]);
        [$token] = $this->token();

        $this->withToken($token)->postJson('/api/v1/integrations', $this->body())->assertStatus(201);

        $row = ExternalIntegration::first();
        $this->assertSame(['order', 'complaint'], $row->manifest['capabilities'][0]['params'][0]['enum']);
        $this->assertEqualsCanonicalizing(['bad.enum', 'empty.enum', 'nested.enum'], collect($row->manifest_issues)->pluck('key')->all());
    }

    public function test_put_rotates_key_and_refetches_manifest(): void
    {
        $this->fakeManifest([$this->capability()]);
        [$token] = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/integrations', $this->body())->json('id');

        $this->fakeManifest([$this->capability(), $this->capability('business.hours')], '2026-10-25.1');
        $this->withToken($token)->putJson("/api/v1/integrations/{$id}", ['key' => 'wak_live_new_key', 'name' => 'Renamed'])
            ->assertOk()
            ->assertJson(['capabilities' => 2, 'manifest_version' => '2026-10-25.1']);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer wak_live_new_key'));
        $row = ExternalIntegration::find($id);
        $this->assertSame('wak_live_new_key', $row->key);
        $this->assertSame('Renamed', $row->name);
    }

    public function test_put_with_a_key_epos_rejects_keeps_the_old_one(): void
    {
        $this->fakeManifest([$this->capability()]);
        [$token] = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/integrations', $this->body())->json('id');

        $this->fakeManifest([], status: 401);
        $this->withToken($token)->putJson("/api/v1/integrations/{$id}", ['key' => 'wrong'])
            ->assertStatus(422)->assertJson(['error' => 'manifest_unreachable']);

        $this->assertSame(self::KEY, ExternalIntegration::find($id)->key);
    }

    public function test_delete_removes_row_key_and_manifest(): void
    {
        $this->fakeManifest([$this->capability()]);
        [$token] = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/integrations', $this->body())->json('id');

        $this->withToken($token)->deleteJson("/api/v1/integrations/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('external_integrations', ['id' => $id]);
    }

    public function test_check_reports_ping_and_manifest(): void
    {
        $this->fakeManifest([$this->capability()]);
        [$token] = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/integrations', $this->body())->json('id');

        $this->withToken($token)->postJson("/api/v1/integrations/{$id}/check")
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'connected', 'capabilities' => 1, 'ping' => true]);
    }

    public function test_three_failed_checks_mark_unreachable_with_growing_backoff(): void
    {
        $this->fakeManifest([$this->capability()]);
        [$token] = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/integrations', $this->body())->json('id');

        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response('boom', 500)]);
        foreach ([1, 2] as $attempt) {
            $this->withToken($token)->postJson("/api/v1/integrations/{$id}/check")->assertOk()->assertJson(['ok' => false]);
            $this->assertSame('connected', ExternalIntegration::find($id)->status);
        }

        $this->withToken($token)->postJson("/api/v1/integrations/{$id}/check")->assertJson(['ok' => false, 'status' => 'unreachable']);
        $row = ExternalIntegration::find($id);
        $this->assertSame('unreachable', $row->status);
        $this->assertEqualsWithDelta(60, now()->diffInSeconds($row->next_retry_at, false), 5);

        $this->withToken($token)->postJson("/api/v1/integrations/{$id}/check");
        $this->assertEqualsWithDelta(300, now()->diffInSeconds(ExternalIntegration::find($id)->next_retry_at, false), 5);

        $this->withToken($token)->postJson("/api/v1/integrations/{$id}/check");
        $this->assertEqualsWithDelta(1800, now()->diffInSeconds(ExternalIntegration::find($id)->next_retry_at, false), 5);

        $this->fakeManifest([$this->capability()]);
        $this->withToken($token)->postJson("/api/v1/integrations/{$id}/check")->assertJson(['ok' => true, 'status' => 'connected']);
        $this->assertSame(0, ExternalIntegration::find($id)->consecutive_failures);
    }

    public function test_key_never_reaches_the_logs(): void
    {
        $lines = [];
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnUsing(function (...$a) use (&$lines) {
            $lines[] = $a;
        });
        $this->fakeManifest([$this->capability()]);
        [$token] = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/integrations', $this->body())->json('id');
        $this->withToken($token)->putJson("/api/v1/integrations/{$id}", ['key' => 'wak_live_rotated'])->assertOk();
        $this->fakeManifest([], status: 401);
        $this->withToken($token)->postJson("/api/v1/integrations/{$id}/check");

        $dump = json_encode($lines);
        $this->assertStringNotContainsString(self::KEY, $dump);
        $this->assertStringNotContainsString('wak_live_rotated', $dump);
        $this->assertNotEmpty($lines, 'the audit line should exist');
    }
}
