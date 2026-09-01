<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiptsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_session_defaults_receipts_on_and_call_reject_off(): void
    {
        $s = WhatsappWebSession::create([
            'workspace_id' => 1,
            'session_name' => 'ws-1',
            'webhook_token' => str_repeat('a', 48),
        ]);

        $this->assertTrue($s->fresh()->send_receipts);
        $this->assertFalse($s->fresh()->auto_reject_calls);
        $this->assertNull($s->fresh()->call_reject_message);
    }
}
