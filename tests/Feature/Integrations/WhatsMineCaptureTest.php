<?php

namespace Tests\Feature\Integrations;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * TEMPORARY WhatsMine capture endpoint (T-0006). No database is used, so no RefreshDatabase.
 */
class WhatsMineCaptureTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wm-capture-'.uniqid();

        config([
            'whatsmine_capture.enabled' => true,
            'whatsmine_capture.token' => 'tok_test_capture',
            'whatsmine_capture.dir' => $this->dir,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** Absolute URL so the test does not depend on APP_URL. */
    private function captureUrl(string $token): string
    {
        return 'http://localhost/webhooks/whatsmine-capture/'.$token;
    }

    public function test_disabled_capture_returns_404_and_writes_nothing(): void
    {
        config(['whatsmine_capture.enabled' => false]);

        $this->postJson($this->captureUrl('tok_test_capture'), ['event' => 'message.received'])
            ->assertNotFound();

        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_wrong_token_returns_404_and_writes_nothing(): void
    {
        $this->postJson($this->captureUrl('not-the-token'), ['event' => 'message.received'])
            ->assertNotFound();

        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_empty_configured_token_never_matches(): void
    {
        config(['whatsmine_capture.token' => '']);

        $this->postJson($this->captureUrl(''), ['event' => 'message.received'])
            ->assertNotFound();

        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_valid_delivery_is_saved_exactly_as_received_without_credentials(): void
    {
        $body = '{"event":"message.received","data":{"from":"+923001234567","text":"REPLY-TK-0001\nLine two"}}';

        $this->call(
            'POST',
            $this->captureUrl('tok_test_capture'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE_256' => 'sha256=abc',
                'HTTP_AUTHORIZATION' => 'Bearer must-not-be-saved',
            ],
            $body,
        )->assertOk()->assertJson(['ok' => true]);

        $files = glob($this->dir.DIRECTORY_SEPARATOR.'*.json');
        $this->assertCount(1, $files);

        $saved = json_decode(file_get_contents($files[0]), true);

        $this->assertSame($body, base64_decode($saved['raw_body_base64']));
        $this->assertSame($body, $saved['raw_body_text']);
        $this->assertSame(['sha256=abc'], $saved['headers']['x-signature-256']);
        $this->assertArrayNotHasKey('authorization', $saved['headers']);
        $this->assertSame('POST', $saved['method']);
    }
}
