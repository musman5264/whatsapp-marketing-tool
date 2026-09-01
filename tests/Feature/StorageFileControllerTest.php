<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The /storage/{path} fallback route serves local public-disk files when the
 * public/storage symlink is missing (common on cPanel/LiteSpeed hosts).
 */
class StorageFileControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    #[Test]
    public function it_serves_an_existing_public_file_with_caching_headers(): void
    {
        Storage::disk('public')->put('media/pic.png', 'PNG-BYTES-HERE');

        $res = $this->get('/storage/media/pic.png');

        $res->assertOk();
        $cc = (string) $res->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=31536000', $cc);
        $this->assertStringContainsString('public', $cc);
        $this->assertStringContainsString('immutable', $cc);
        $this->assertNotEmpty($res->headers->get('ETag'));
        $this->assertStringContainsString('inline', (string) $res->headers->get('Content-Disposition'));
    }

    #[Test]
    public function it_serves_nested_branding_assets(): void
    {
        Storage::disk('public')->put('branding/logo-abc.png', 'LOGO');
        $this->get('/storage/branding/logo-abc.png')->assertOk();
    }

    #[Test]
    public function a_missing_file_is_404(): void
    {
        $this->get('/storage/media/nope.png')->assertNotFound();
    }

    #[Test]
    public function path_traversal_is_rejected(): void
    {
        Storage::disk('public')->put('media/ok.txt', 'ok');
        $this->get('/storage/../.env')->assertNotFound();
        $this->get('/storage/media/../../.env')->assertNotFound();
    }

    #[Test]
    public function a_conditional_request_with_matching_etag_returns_304(): void
    {
        Storage::disk('public')->put('media/x.jpg', 'JPEGDATA');
        $first = $this->get('/storage/media/x.jpg');
        $etag = $first->headers->get('ETag');

        $this->withHeader('If-None-Match', $etag)
            ->get('/storage/media/x.jpg')
            ->assertStatus(304);
    }

    #[Test]
    public function non_inline_types_are_sent_as_attachment(): void
    {
        Storage::disk('public')->put('docs/report.zip', 'PK...');
        $res = $this->get('/storage/docs/report.zip');
        $res->assertOk();
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
    }
}
