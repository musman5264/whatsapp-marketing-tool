<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\LogApiRequest;
use App\Models\ApiRequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    public function test_authenticated_request_is_logged_with_token_metadata(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();
        $token = $user->createToken('My CI token', ['*'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->assertDatabaseHas('api_request_logs', [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'token_name' => 'My CI token',
            'method' => 'GET',
            'path' => 'api/v1/me',
            'status' => 200,
        ]);
    }

    public function test_bad_token_request_is_logged_with_null_token_id(): void
    {
        $this->withToken('1|totally-invalid')->getJson('/api/v1/me')->assertStatus(401);

        $row = ApiRequestLog::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame(401, $row->status);
        $this->assertNull($row->token_id);
        $this->assertSame('api/v1/me', $row->path);
    }

    public function test_nothing_logged_when_disabled(): void
    {
        config(['api.logging.enabled' => false]);
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->assertDatabaseCount('api_request_logs', 0);
    }

    public function test_success_body_not_stored_when_sample_rate_zero(): void
    {
        config(['api.logging.success_sample_rate' => 0.0]);
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $row = ApiRequestLog::query()->latest('id')->first();
        $this->assertNull($row->response_body);
        $this->assertNull($row->request_body); // request body also not captured on sampled-out 2xx
        $this->assertNotNull($row->response_size_bytes); // size always recorded
    }

    public function test_success_body_stored_when_sample_rate_one(): void
    {
        config(['api.logging.success_sample_rate' => 1.0]);
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $row = ApiRequestLog::query()->latest('id')->first();
        $this->assertNotNull($row->response_body);
    }

    public function test_error_response_body_always_stored_and_request_redacted(): void
    {
        config(['api.logging.success_sample_rate' => 0.0]);
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('t', ['*'])->plainTextToken;

        // contacts create with a bad phone -> 422, and a sensitive field in body
        $this->withToken($token)->postJson('/api/v1/contacts', [
            'phone_e164' => 'nope',
            'password' => 'should-not-be-stored',
        ])->assertStatus(422);

        $row = ApiRequestLog::query()->latest('id')->first();
        $this->assertSame(422, $row->status);
        $this->assertNotNull($row->response_body);
        $this->assertNotNull($row->request_body);
        $this->assertStringContainsString('[redacted]', $row->request_body);
        $this->assertStringNotContainsString('should-not-be-stored', $row->request_body);
        $this->assertSame(ValidationException::class, $row->error_class);
    }

    public function test_login_route_is_not_logged(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'x@y.z', 'password' => 'secret'])
            ->assertStatus(422); // validation or bad creds — either way

        $this->assertDatabaseCount('api_request_logs', 0);
    }

    /**
     * No /api/v1 route currently returns a StreamedResponse/BinaryFileResponse
     * (all API controllers return JSON), so the middleware's streamed-body
     * branch is unreachable via integration. Exercise it directly: a streamed
     * response must be logged as the '[streamed response]' placeholder without
     * the middleware ever calling ->getContent() on the stream.
     */
    public function test_streamed_response_is_logged_as_placeholder_without_consuming_stream(): void
    {
        config(['api.logging.success_sample_rate' => 1.0]);
        ['user' => $user] = $this->createWorkspaceContext();

        $request = Request::create('/api/v1/export', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_log_started_at', hrtime(true));

        $streamRan = false;
        $response = new StreamedResponse(function () use (&$streamRan) {
            $streamRan = true;
            echo 'row1,row2';
        }, 200, ['Content-Type' => 'text/csv']);

        (new LogApiRequest)->terminate($request, $response);

        $this->assertFalse($streamRan, 'middleware must not consume the stream');

        $row = ApiRequestLog::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('[streamed response]', $row->response_body);
        $this->assertNull($row->response_size_bytes); // not computed for streams (known minor)
        $this->assertSame(200, $row->status);

        // stream still works for the client afterwards
        ob_start();
        $response->sendContent();
        $this->assertSame('row1,row2', ob_get_clean());
        $this->assertTrue($streamRan);
    }
}
