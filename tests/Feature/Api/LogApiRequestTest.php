<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LogApiRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_request_logs_table_has_expected_columns(): void
    {
        $cols = [
            'id', 'client_id', 'user_id', 'token_id', 'token_name', 'method',
            'path', 'route_name', 'query', 'status', 'duration_ms', 'ip',
            'user_agent', 'request_headers', 'request_body', 'response_body',
            'response_size_bytes', 'error_class', 'created_at',
        ];

        foreach ($cols as $col) {
            $this->assertTrue(
                Schema::hasColumn('api_request_logs', $col),
                "api_request_logs is missing column [$col]"
            );
        }

        $this->assertFalse(Schema::hasColumn('api_request_logs', 'updated_at'));
    }

    public function test_api_logging_config_defaults(): void
    {
        $this->assertTrue(config('api.logging.enabled'));
        $this->assertSame(0.1, config('api.logging.success_sample_rate'));
        $this->assertSame(16384, config('api.logging.max_body_bytes'));
        $this->assertSame(90, config('api.logging.retention_days'));
        $this->assertSame(14, config('api.logging.payload_retention_days'));
        $this->assertContains('password', config('api.logging.redact_keys'));
        $this->assertContains('authorization', config('api.logging.redact_keys'));
    }
}
