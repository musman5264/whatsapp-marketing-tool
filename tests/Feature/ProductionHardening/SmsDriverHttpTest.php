<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\Broadcasting\Services\Sms\Msg91Driver;
use App\Modules\Broadcasting\Services\Sms\SmsBdDriver;
use App\Modules\Broadcasting\Services\Sms\TwilioDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests SMS driver HTTP interactions with Http::fake().
 */
class SmsDriverHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_twilio_driver_sends_to_correct_url_and_returns_sid(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201),
        ]);

        $driver = new TwilioDriver('ACtest', 'token', '+15005550006');
        $result = $driver->send('+16175551234', 'Hello');

        $this->assertTrue($result->success);
        $this->assertSame('SM123', $result->messageId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.twilio.com') &&
                $request->data()['To'] === '+16175551234' &&
                $request->data()['Body'] === 'Hello';
        });
    }

    public function test_smsbd_driver_returns_real_message_id(): void
    {
        Http::fake([
            'api.smsbd.com/*' => Http::response(['Message_ID' => 'BD_MSG_789'], 200),
        ]);

        $driver = new SmsBdDriver('key123', 'SENDER');
        $result = $driver->send('+8801712345678', 'Test msg');

        $this->assertTrue($result->success);
        $this->assertSame('BD_MSG_789', $result->messageId);
    }

    public function test_msg91_driver_sends_authkey_header_and_returns_request_id(): void
    {
        Http::fake([
            'api.msg91.com/*' => Http::response(['type' => 'success', 'message' => '3763646c3058'], 200),
        ]);

        $driver = new Msg91Driver('authkey123', 'SENDER', '4', 'DLT123');
        $result = $driver->send('+919812345678', 'Hello');

        $this->assertTrue($result->success);
        $this->assertSame('3763646c3058', $result->messageId);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), 'api.msg91.com/api/v2/sendsms') &&
                $request->header('authkey')[0] === 'authkey123' &&
                $data['sender'] === 'SENDER' &&
                $data['route'] === '4' &&
                $data['sms'][0]['to'] === ['919812345678'] &&
                $data['sms'][0]['message'] === 'Hello' &&
                $data['sms'][0]['DLT_TE_ID'] === 'DLT123';
        });
    }

    public function test_msg91_driver_returns_error_on_failure(): void
    {
        Http::fake([
            'api.msg91.com/*' => Http::response(['type' => 'error', 'message' => 'Invalid authkey'], 401),
        ]);

        $driver = new Msg91Driver('bad', 'SENDER');
        $result = $driver->send('+919812345678', 'Hello');

        $this->assertFalse($result->success);
        $this->assertSame('Invalid authkey', $result->error);
    }
}
