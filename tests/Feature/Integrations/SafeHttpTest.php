<?php

namespace Tests\Feature\Integrations;

use App\Modules\Integrations\Services\External\ExternalCallException;
use App\Modules\Integrations\Services\External\HostResolver;
use App\Modules\Integrations\Services\External\SafeHttp;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeHttpTest extends TestCase
{
    /** @param array<string, list<string>> $dns */
    private function resolve(array $dns): void
    {
        $this->app->instance(HostResolver::class, new class($dns) extends HostResolver
        {
            public function __construct(private array $dns) {}

            public function ips(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        });
    }

    #[DataProvider('blockedUrls')]
    public function test_private_and_metadata_targets_are_refused(string $url): void
    {
        $this->resolve(['internal.example.com' => ['10.1.2.3'], 'mixed.example.com' => ['93.184.216.34', '127.0.0.1']]);
        Http::fake();

        try {
            app(SafeHttp::class)->request('GET', $url, 'k');
            $this->fail('expected the target to be refused');
        } catch (ExternalCallException $e) {
            $this->assertSame('unsafe_target', $e->reason);
        }

        Http::assertNothingSent();
    }

    public static function blockedUrls(): array
    {
        return [
            'metadata' => ['https://169.254.169.254/latest/meta-data'],
            'loopback' => ['https://127.0.0.1/x'],
            'private 10/8' => ['https://10.0.0.5/x'],
            'private 172.16/12' => ['https://172.20.1.1/x'],
            'private 192.168/16' => ['https://192.168.1.10/x'],
            'ipv6 loopback' => ['https://[::1]/x'],
            'ipv6 unique local' => ['https://[fd00::1]/x'],
            'ipv4-mapped ipv6' => ['https://[::ffff:127.0.0.1]/x'],
            'name resolving private' => ['https://internal.example.com/x'],
            'one private among public' => ['https://mixed.example.com/x'],
            'unresolvable' => ['https://nowhere.example.com/x'],
            'plain http' => ['http://epos.example.com/x'],
        ];
    }

    public function test_sends_bearer_accept_and_request_id_and_does_not_follow_redirects(): void
    {
        $this->resolve(['epos.example.com' => ['93.184.216.34']]);
        Http::fake(['epos.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

        $res = app(SafeHttp::class)->request('GET', 'https://epos.example.com/a', 'secret-key');

        $this->assertSame(302, $res->status());
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer secret-key')
            && $r->hasHeader('Accept', 'application/json')
            && $r->hasHeader('X-Request-Id'));
    }

    public function test_oversized_body_is_refused(): void
    {
        $this->resolve(['epos.example.com' => ['93.184.216.34']]);
        Http::fake(['*' => Http::response(str_repeat('a', 2000), 200)]);

        try {
            app(SafeHttp::class)->request('GET', 'https://epos.example.com/a', 'k', null, 1000);
            $this->fail('expected too_large');
        } catch (ExternalCallException $e) {
            $this->assertSame('too_large', $e->reason);
        }
    }

    public function test_connection_failure_becomes_external_call_exception_without_the_key(): void
    {
        $this->resolve(['epos.example.com' => ['93.184.216.34']]);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timed out'));

        try {
            app(SafeHttp::class)->request('GET', 'https://epos.example.com/a', 'secret-key');
            $this->fail('expected failure');
        } catch (ExternalCallException $e) {
            $this->assertSame('unreachable', $e->reason);
            $this->assertStringNotContainsString('secret-key', $e->getMessage());
        }
    }

    public function test_dev_switch_allows_private_hosts_over_http(): void
    {
        config(['external_integrations.allow_private_hosts' => true]);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $res = app(SafeHttp::class)->request('GET', 'http://127.0.0.1:8000/ping', 'k');

        $this->assertTrue($res->ok());
    }
}
