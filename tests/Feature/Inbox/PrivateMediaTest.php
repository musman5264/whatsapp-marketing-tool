<?php

namespace Tests\Feature\Inbox;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrivateMediaTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = 'message-media/private-test-'.uniqid().'.jpg';
        Storage::disk('local')->put($this->file, 'fake-jpeg-bytes');
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->delete($this->file);
        parent::tearDown();
    }

    #[Test]
    public function a_signed_private_link_serves_the_file(): void
    {
        // WAHA gets this kind of link to send an outgoing image. It works only while the signature is valid.
        $url = Storage::disk('local')->temporaryUrl($this->file, now()->addMinutes(5));

        $this->get($url)->assertOk();
    }

    #[Test]
    public function a_private_file_without_a_signature_is_refused(): void
    {
        $this->get('/private-files/'.$this->file)->assertForbidden();
    }

    #[Test]
    public function a_private_file_is_not_on_the_public_disk(): void
    {
        $this->assertFalse(Storage::disk('public')->exists($this->file));
    }
}
