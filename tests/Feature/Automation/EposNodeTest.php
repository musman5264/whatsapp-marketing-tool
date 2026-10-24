<?php

namespace Tests\Feature\Automation;

use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Integrations\Models\ExternalCallLog;
use App\Modules\Integrations\Models\ExternalIntegration;
use App\Modules\Integrations\Services\External\HostResolver;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The E-POS flow node end to end through the real AutomationEngine: identity binding,
 * found / no-data branches, safety limits. EPOS itself is faked at the HTTP layer.
 */
class EposNodeTest extends TestCase
{
    use RefreshDatabase;

    private $workspace;

    private Contact $contact;

    private ExternalIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $ctx = $this->createWorkspaceContext();
        $this->workspace = $ctx['workspace'];
        $this->contact = Contact::factory()->create([
            'workspace_id' => $this->workspace->id,
            'first_name' => 'Alice',
            'phone_e164' => '+15550000001',
        ]);
        ChannelAccount::create([
            'workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'provider' => 'whatsapp',
            'status' => 'active', 'display_name' => 'Test WA', 'phone_number_id' => '1234567890',
        ]);
        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andReturn('provider.msg.id');
        $this->app->instance(WhatsappDriver::class, $driver);

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function ips(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ['93.184.216.34'];
            }
        });

        $this->integration = $this->makeIntegration();
    }

    private function capabilities(): array
    {
        $phone = ['name' => 'phone', 'type' => 'phone', 'from' => 'sender_phone'];

        return [
            ['key' => 'contact.profile', 'kind' => 'query', 'label' => ['en' => 'Profile'], 'method' => 'GET', 'path' => '/api/whatsapp/v1/profile', 'params' => [$phone]],
            ['key' => 'contact.ledger', 'kind' => 'query', 'label' => ['en' => 'Ledger'], 'method' => 'GET', 'path' => '/api/whatsapp/v1/ledger', 'params' => [
                $phone,
                ['name' => 'period', 'type' => 'choice', 'from' => 'chat_answer', 'choices' => [['value' => 'this_month', 'label' => ['en' => 'This month']], ['value' => 'last_month', 'label' => ['en' => 'Last month']]]],
            ]],
            ['key' => 'business.hours', 'kind' => 'query', 'label' => ['en' => 'Hours'], 'method' => 'GET', 'path' => '/api/whatsapp/v1/hours', 'params' => [
                ['name' => 'branch', 'type' => 'text', 'from' => 'fixed', 'optional' => true],
            ]],
            ['key' => 'contact.ledger_pdf', 'kind' => 'action', 'label' => ['en' => 'Send ledger'], 'method' => 'POST', 'path' => '/api/whatsapp/v1/ledger/send', 'params' => [
                $phone,
                ['name' => 'period', 'type' => 'choice', 'from' => 'chat_answer', 'choices' => [['value' => 'last_month', 'label' => ['en' => 'Last month']]]],
            ]],
        ];
    }

    private function makeIntegration(array $over = []): ExternalIntegration
    {
        $i = new ExternalIntegration(array_merge([
            'workspace_id' => $this->workspace->id, 'type' => 'epos', 'name' => 'Ali Traders',
            'base_url' => 'https://epos.example.com', 'manifest_url' => 'https://epos.example.com/api/whatsapp/v1/manifest',
            'key' => 'wak_live_secret',
        ], $over));
        $i->manifest_version = 'v1';
        $i->manifest = ['business' => [], 'capabilities' => $this->capabilities()];
        $i->save();

        return $i;
    }

    private function fakeEpos(array $stubs): void
    {
        Http::swap(new HttpFactory);
        Http::fake($stubs);
    }

    private function eposJson(array $body, int $status = 200, string $version = 'v1')
    {
        return Http::response($body, $status, ['X-Epos-Manifest-Version' => $version]);
    }

    /** trigger -> epos node -> (true) "YES ..." / (false) "NO ..." replies */
    private function runFlow(array $nodeData, ?string $yes = null, ?string $no = null, array $context = []): AutomationRun
    {
        $automation = Automation::create([
            'workspace_id' => $this->workspace->id, 'name' => 'Test', 'status' => 'active', 'trigger_type' => 'contact.created',
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => 'epos', 'position' => ['x' => 0, 'y' => 100], 'data' => $nodeData + ['integration_id' => $this->integration->id]],
                ['id' => 'y', 'type' => 'send_whatsapp', 'position' => ['x' => 0, 'y' => 200], 'data' => ['body' => $yes ?? 'YES']],
                ['id' => 'n', 'type' => 'send_whatsapp', 'position' => ['x' => 100, 'y' => 200], 'data' => ['body' => $no ?? 'NO']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1'],
                ['id' => 'e2', 'source' => 'n1', 'target' => 'y', 'sourceHandle' => 'true'],
                ['id' => 'e3', 'source' => 'n1', 'target' => 'n', 'sourceHandle' => 'false'],
            ],
        ]);
        $run = AutomationRun::create([
            'automation_id' => $automation->id, 'contact_id' => $this->contact->id,
            'status' => 'pending', 'context' => $context, 'started_at' => now(),
        ]);
        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        return $run->fresh();
    }

    private function reply(): ?string
    {
        return Message::where('direction', 'out')->latest('id')->value('body');
    }

    public function test_found_answer_takes_the_found_branch_and_is_readable_in_the_reply(): void
    {
        $this->fakeEpos(['epos.example.com/api/whatsapp/v1/profile*' => $this->eposJson(['found' => true, 'name' => 'Ali Khan', 'balance' => 12500, 'balance_type' => 'receivable'])]);

        $run = $this->runFlow(['capability' => 'contact.profile', 'result_var' => 'epos'], 'Hi {{epos.name}}, you owe {{epos.balance}}');

        $this->assertSame('completed', $run->status);
        $this->assertSame('Hi Ali Khan, you owe 12500', $this->reply());
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer wak_live_secret')
            && $r->hasHeader('X-Request-Id')
            && str_contains($r->url(), 'phone=%2B15550000001'));
    }

    public function test_result_variable_defaults_to_epos(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => true, 'name' => 'Ali Khan'])]);

        $this->runFlow(['capability' => 'contact.profile'], '{{epos.name}}');

        $this->assertSame('Ali Khan', $this->reply());
    }

    public function test_author_cannot_override_the_customer_phone(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => true, 'name' => 'x'])]);

        $this->runFlow([
            'capability' => 'contact.profile',
            'params' => ['phone' => '+923000000000'],
            'phone' => '+923000000000',
        ]);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'phone=%2B15550000001') && ! str_contains($r->url(), '923000000000'));
    }

    public function test_a_phone_value_typed_by_a_customer_in_an_answer_never_reaches_the_phone_param(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => true])]);

        $this->runFlow(
            ['capability' => 'contact.ledger', 'params' => ['phone' => '{{answer}}', 'period' => 'this_month']],
            context: ['answer' => '+923009999999'],
        );

        Http::assertSent(fn ($r) => str_contains($r->url(), 'phone=%2B15550000001') && ! str_contains($r->url(), '923009999999'));
    }

    public function test_not_found_takes_the_other_branch_without_error(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => false])]);

        $run = $this->runFlow(['capability' => 'contact.profile'], 'YES', 'We do not know this number');

        $this->assertSame('completed', $run->status);
        $this->assertSame('We do not know this number', $this->reply());
    }

    #[DataProvider('noDataResponses')]
    public function test_failures_take_the_no_data_branch_and_never_show_raw_errors(callable $stub): void
    {
        $this->fakeEpos(['*' => $stub]);

        $run = $this->runFlow(['capability' => 'contact.profile'], 'YES', 'NO DATA {{epos.reason}}');

        $this->assertSame('completed', $run->status);
        $this->assertSame('NO DATA no_data', $this->reply());
    }

    public static function noDataResponses(): array
    {
        return [
            'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
            'http 500' => [fn () => HttpFactory::response(['error' => 'boom'], 500)],
            'http 401' => [fn () => HttpFactory::response(['error' => 'unauthorized'], 401)],
            'not json' => [fn () => HttpFactory::response('<html>oops</html>', 200)],
            'too large' => [fn () => HttpFactory::response(str_repeat('a', 102401), 200)],
        ];
    }

    public function test_capability_that_disappeared_takes_the_no_data_branch(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => true])]);

        $run = $this->runFlow(['capability' => 'products.search'], 'YES', 'NO');

        $this->assertSame('NO', $this->reply());
        Http::assertNothingSent();
        $log = $run->logs()->where('node_id', 'n1')->first();
        $this->assertStringContainsString('no longer available', (string) $log->message);
    }

    public function test_missing_link_takes_the_no_data_branch(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => true])]);
        $this->integration->delete();

        $run = $this->runFlow(['capability' => 'contact.profile'], 'YES', 'NO');

        $this->assertSame('NO', $this->reply());
        Http::assertNothingSent();
        $this->assertStringContainsString('not linked', (string) $run->logs()->where('node_id', 'n1')->value('message'));
    }

    public function test_unreachable_integration_makes_no_call_at_all(): void
    {
        $this->integration->forceFill(['status' => 'unreachable', 'next_retry_at' => now()->addMinutes(5)])->save();
        $this->fakeEpos(['*' => $this->eposJson(['found' => true])]);

        $this->runFlow(['capability' => 'contact.profile'], 'YES', 'NO');

        $this->assertSame('NO', $this->reply());
        Http::assertNothingSent();
    }

    public function test_private_base_url_is_refused_and_nothing_is_sent(): void
    {
        $this->integration->update(['base_url' => 'https://169.254.169.254']);
        $this->fakeEpos(['*' => $this->eposJson(['found' => true])]);

        $this->runFlow(['capability' => 'contact.profile'], 'YES', 'NO');

        $this->assertSame('NO', $this->reply());
        Http::assertNothingSent();
    }

    public function test_contact_without_a_verified_number_makes_no_call(): void
    {
        $this->contact->update(['phone_e164' => null]);
        $this->fakeEpos(['*' => $this->eposJson(['found' => true])]);

        $this->runFlow(['capability' => 'contact.profile'], 'YES', 'NO');

        $this->assertSame('NO', $this->reply());
        Http::assertNothingSent();
    }

    public function test_choice_answer_outside_the_manifest_list_is_not_sent(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => true])]);

        $this->runFlow(['capability' => 'contact.ledger', 'params' => ['period' => '{{answer}}']], 'YES', 'NO', ['answer' => 'forever; drop table']);

        $this->assertSame('NO', $this->reply());
        Http::assertNothingSent();
    }

    public function test_choice_answer_from_the_chat_is_sent(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => true, 'closing_balance' => 5])]);

        $this->runFlow(['capability' => 'contact.ledger', 'params' => ['period' => '{{answer}}']], '{{epos.closing_balance}}', context: ['answer' => 'last_month']);

        $this->assertSame('5', $this->reply());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'period=last_month'));
    }

    public function test_action_posts_with_a_stable_idempotency_key_and_reports_the_reason(): void
    {
        $this->fakeEpos(['epos.example.com/api/whatsapp/v1/ledger/send' => $this->eposJson(['found' => true, 'ok' => false, 'reason' => 'rate_limited'])]);

        $this->runFlow(['capability' => 'contact.ledger_pdf', 'params' => ['period' => 'last_month']], 'SENT', 'Not sent: {{epos.reason}}');

        $this->assertSame('Not sent: rate_limited', $this->reply());
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->hasHeader('Idempotency-Key')
            && $r['phone'] === '+15550000001'
            && $r['period'] === 'last_month');
    }

    public function test_action_success_takes_the_sent_branch(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => true, 'ok' => true, 'message_id' => 123])]);

        $this->runFlow(['capability' => 'contact.ledger_pdf', 'params' => ['period' => 'last_month']], 'Sent #{{epos.message_id}}', 'NO');

        $this->assertSame('Sent #123', $this->reply());
    }

    public function test_action_is_not_retried_after_a_timeout(): void
    {
        $attempts = 0;
        $this->fakeEpos(['*' => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28');
        }]);

        $this->runFlow(['capability' => 'contact.ledger_pdf', 'params' => ['period' => 'last_month']], 'SENT', 'NO');

        $this->assertSame('NO', $this->reply());
        $this->assertSame(1, $attempts);
    }

    public function test_person_specific_answers_are_never_cached(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => true, 'name' => 'Ali'])]);

        $this->runFlow(['capability' => 'contact.profile']);
        $this->runFlow(['capability' => 'contact.profile']);

        Http::assertSentCount(2);
    }

    public function test_shop_wide_answers_are_cached_for_a_minute(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => true, 'open_now' => true])]);

        $this->runFlow(['capability' => 'business.hours', 'params' => ['branch' => 'Main']]);
        $this->runFlow(['capability' => 'business.hours', 'params' => ['branch' => 'Main']]);
        $this->runFlow(['capability' => 'business.hours', 'params' => ['branch' => 'Other']]);

        Http::assertSentCount(2);
    }

    public function test_more_than_sixty_calls_a_minute_take_the_no_data_branch(): void
    {
        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit('epos-calls:'.$this->integration->id, 60);
        }
        $this->fakeEpos(['*' => $this->eposJson(['found' => true])]);

        $this->runFlow(['capability' => 'contact.profile'], 'YES', 'NO');

        $this->assertSame('NO', $this->reply());
        Http::assertNothingSent();
    }

    public function test_a_newer_manifest_version_header_triggers_a_manifest_refresh(): void
    {
        $this->fakeEpos([
            'epos.example.com/api/whatsapp/v1/manifest' => Http::response(['manifest_version' => 'v2', 'capabilities' => $this->capabilities()]),
            'epos.example.com/api/whatsapp/v1/profile*' => $this->eposJson(['found' => true], 200, 'v2'),
        ]);

        $this->runFlow(['capability' => 'contact.profile']);

        $this->assertSame('v2', $this->integration->fresh()->manifest_version);
    }

    public function test_repeated_failures_trip_the_breaker(): void
    {
        $this->fakeEpos(['*' => Http::response('boom', 500)]);

        foreach (range(1, 3) as $_) {
            $this->runFlow(['capability' => 'contact.profile']);
        }

        $this->assertSame('unreachable', $this->integration->fresh()->status);
    }

    public function test_every_call_is_recorded_without_payloads_when_debug_is_off(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => true, 'name' => 'Secret Customer Name'])]);

        $this->runFlow(['capability' => 'contact.profile']);

        $row = ExternalCallLog::where('integration_id', $this->integration->id)->sole();
        $this->assertSame('contact.profile', $row->capability);
        $this->assertSame('found', $row->outcome);
        $this->assertSame('200', $row->status);
        $this->assertIsInt($row->ms);
        $this->assertNull($row->request);
        $this->assertNull($row->response);
        $this->assertStringNotContainsString('5550000001', json_encode($row->toArray()));
    }

    public function test_a_no_data_call_is_recorded_with_its_reason(): void
    {
        $this->fakeEpos(['*' => fn () => throw new ConnectionException('cURL error 28')]);

        $this->runFlow(['capability' => 'contact.profile']);

        $row = ExternalCallLog::sole();
        $this->assertSame('no_data', $row->outcome);
        $this->assertSame('unreachable', $row->status);
        $this->assertStringContainsString('unreachable', $row->message);
    }

    public function test_a_call_that_never_left_is_recorded_too(): void
    {
        $this->fakeEpos(['*' => $this->eposJson(['found' => true])]);

        $this->runFlow(['capability' => 'products.search']);

        $row = ExternalCallLog::sole();
        $this->assertSame('no_data', $row->outcome);
        $this->assertStringContainsString('no longer available', $row->message);
    }

    public function test_debug_on_keeps_the_masked_request_and_response_but_never_the_key(): void
    {
        $this->integration->forceFill(['debug_until' => now()->addHour()])->save();
        $this->fakeEpos(['*' => $this->eposJson(['found' => true, 'name' => 'Ali', 'mobile' => '+15550000001', 'pad' => str_repeat('x', 5000)])]);

        $this->runFlow(['capability' => 'contact.profile']);

        $row = ExternalCallLog::sole();
        $this->assertStringContainsString('GET', $row->request);
        $this->assertStringContainsString('/api/whatsapp/v1/profile', $row->request);
        $this->assertStringContainsString('+1555•••0001', urldecode($row->request));
        $this->assertStringNotContainsString('5550000001', $row->request.$row->response);
        $this->assertStringContainsString('+1555•••0001', $row->response);
        $this->assertStringContainsString('Ali', $row->response);
        $this->assertLessThanOrEqual(2100, strlen($row->response));
        $this->assertStringNotContainsString('wak_live_secret', $row->request.$row->response.$row->message);
    }

    public function test_debug_that_has_expired_records_no_payload(): void
    {
        $this->integration->forceFill(['debug_until' => now()->subMinute()])->save();
        $this->fakeEpos(['*' => $this->eposJson(['found' => true])]);

        $this->runFlow(['capability' => 'contact.profile']);

        $this->assertNull(ExternalCallLog::sole()->request);
    }

    public function test_a_manifest_refresh_is_recorded(): void
    {
        $this->fakeEpos([
            'epos.example.com/api/whatsapp/v1/manifest' => HttpFactory::response(['manifest_version' => 'v2', 'capabilities' => $this->capabilities()]),
            'epos.example.com/api/whatsapp/v1/profile*' => $this->eposJson(['found' => true], 200, 'v2'),
        ]);

        $this->runFlow(['capability' => 'contact.profile']);

        $this->assertSame(['contact.profile', 'manifest'], ExternalCallLog::orderBy('id')->pluck('capability')->sort()->values()->all());
    }

    public function test_audit_line_has_no_phone_key_or_body(): void
    {
        $lines = [];
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnUsing(function (...$a) use (&$lines) {
            $lines[] = $a;
        });
        $this->fakeEpos(['*' => $this->eposJson(['found' => true, 'name' => 'Secret Customer Name'])]);

        $this->runFlow(['capability' => 'contact.profile']);

        $dump = json_encode($lines);
        $this->assertStringContainsString('contact.profile', $dump);
        $this->assertStringNotContainsString('5550000001', $dump);
        $this->assertStringNotContainsString('wak_live_secret', $dump);
        $this->assertStringNotContainsString('Secret Customer Name', $dump);
    }
}
