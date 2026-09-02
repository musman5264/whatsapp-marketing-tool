<?php

namespace Tests\Unit;

use App\Support\ApiLogRedactor;
use Tests\TestCase;

class ApiLogRedactorTest extends TestCase
{
    // Redaction keys and max_body_bytes come from config/api.php; the one test
    // that needs a small truncation limit overrides it locally.

    public function test_redacts_sensitive_keys_case_insensitively_and_nested(): void
    {
        $out = ApiLogRedactor::redactArray([
            'name' => 'Rahim',
            'Password' => 'hunter2',
            'nested' => ['api_key' => 'abc', 'keep' => 'me'],
        ]);

        $this->assertSame('Rahim', $out['name']);
        $this->assertSame('[redacted]', $out['Password']);
        $this->assertSame('[redacted]', $out['nested']['api_key']);
        $this->assertSame('me', $out['nested']['keep']);
    }

    public function test_redact_array_stops_at_max_depth(): void
    {
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => ['j' => ['k' => ['l' => ['m' => 'x']]]]]]]]]]]]];
        $out = ApiLogRedactor::redactArray($deep);
        // Walk down to the depth guard; the value at depth 12 is replaced.
        $json = json_encode($out);
        $this->assertStringContainsString('[truncated: max depth]', $json);
    }

    public function test_redact_headers_masks_authorization_and_cookies(): void
    {
        $out = ApiLogRedactor::redactHeaders([
            'authorization' => ['Bearer 1|abcdefghijklmnop'],
            'cookie' => ['session=secret'],
            'x-csrf-token' => ['tok'],
            'accept' => ['application/json'],
        ]);

        $this->assertSame(['Bearer ****mnop'], $out['authorization']);
        $this->assertSame(['[redacted]'], $out['cookie']);
        $this->assertSame(['[redacted]'], $out['x-csrf-token']);
        $this->assertSame(['application/json'], $out['accept']);
    }

    public function test_redact_headers_non_bearer_authorization_fully_redacted(): void
    {
        $out = ApiLogRedactor::redactHeaders(['authorization' => ['Basic dXNlcjpwYXNz']]);
        $this->assertSame(['[redacted]'], $out['authorization']);
    }

    public function test_body_for_storage_null_and_empty_return_null(): void
    {
        $this->assertNull(ApiLogRedactor::bodyForStorage(null, 'application/json'));
        $this->assertNull(ApiLogRedactor::bodyForStorage('', 'application/json'));
    }

    public function test_body_for_storage_redacts_json(): void
    {
        $res = ApiLogRedactor::bodyForStorage('{"name":"Rahim","token":"abc"}', 'application/json');
        $this->assertStringContainsString('"name":"Rahim"', $res['body']);
        $this->assertStringContainsString('"token":"[redacted]"', $res['body']);
        $this->assertFalse($res['truncated']);
    }

    public function test_body_for_storage_non_json_is_summarised(): void
    {
        $res = ApiLogRedactor::bodyForStorage('<html>hi</html>', 'text/html');
        $this->assertSame('[non-JSON body, 15 bytes]', $res['body']);
        $this->assertSame(15, $res['full_size']);
    }

    public function test_body_for_storage_multipart_is_summarised(): void
    {
        $res = ApiLogRedactor::bodyForStorage('--boundary--', 'multipart/form-data; boundary=boundary');
        $this->assertStringStartsWith('[multipart upload,', $res['body']);
    }

    public function test_body_for_storage_truncates_large_json(): void
    {
        config(['api.logging.max_body_bytes' => 50]);
        $big = json_encode(['x' => str_repeat('a', 500)]);
        $res = ApiLogRedactor::bodyForStorage($big, 'application/json');
        $this->assertTrue($res['truncated']);
        $this->assertLessThanOrEqual(50, strlen($res['body']));
        $this->assertSame(strlen($big), $res['full_size']);
    }
}
