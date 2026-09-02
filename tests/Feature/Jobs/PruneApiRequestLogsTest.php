<?php

namespace Tests\Feature\Jobs;

use App\Jobs\PruneApiRequestLogs;
use App\Models\ApiRequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneApiRequestLogsTest extends TestCase
{
    use RefreshDatabase;

    private function log(array $attrs): ApiRequestLog
    {
        return ApiRequestLog::create(array_merge([
            'method' => 'GET', 'path' => 'api/v1/me', 'status' => 200,
            'duration_ms' => 1, 'request_body' => '{"a":1}', 'response_body' => '{"b":2}',
            'request_headers' => ['accept' => ['application/json']], 'query' => ['x' => '1'],
            'created_at' => now(),
        ], $attrs));
    }

    public function test_nulls_payloads_past_payload_retention_but_keeps_row(): void
    {
        $old = $this->log(['created_at' => now()->subDays(20)]);

        (new PruneApiRequestLogs)->handle();

        $old->refresh();
        $this->assertNull($old->request_body);
        $this->assertNull($old->response_body);
        $this->assertNull($old->request_headers);
        $this->assertNull($old->query);
        $this->assertDatabaseHas('api_request_logs', ['id' => $old->id]);
    }

    public function test_deletes_rows_past_retention(): void
    {
        $ancient = $this->log(['created_at' => now()->subDays(100)]);
        $fresh = $this->log(['created_at' => now()->subDay()]);

        (new PruneApiRequestLogs)->handle();

        $this->assertDatabaseMissing('api_request_logs', ['id' => $ancient->id]);
        $this->assertDatabaseHas('api_request_logs', ['id' => $fresh->id]);

        // A row younger than payload_retention_days keeps its payload untouched.
        $this->assertSame('{"a":1}', $fresh->fresh()->request_body);
    }

    public function test_enforces_global_row_cap_deleting_oldest(): void
    {
        config(['api.logging.max_rows' => 3]);
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[$i] = $this->log(['created_at' => now()->subMinutes(60 - $i)])->id; // 1 oldest .. 5 newest
        }

        (new PruneApiRequestLogs)->handle();

        $this->assertSame(3, ApiRequestLog::count());
        $this->assertDatabaseMissing('api_request_logs', ['id' => $ids[1]]);
        $this->assertDatabaseMissing('api_request_logs', ['id' => $ids[2]]);
        $this->assertDatabaseHas('api_request_logs', ['id' => $ids[5]]);
    }
}
