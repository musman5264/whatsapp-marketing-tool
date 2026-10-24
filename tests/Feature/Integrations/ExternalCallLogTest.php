<?php

namespace Tests\Feature\Integrations;

use App\Modules\Integrations\Models\ExternalCallLog;
use App\Modules\Integrations\Models\ExternalIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalCallLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_numbers_are_masked_wherever_they_appear(): void
    {
        $this->assertSame('+9233•••6444', ExternalCallLog::mask('+923345266444'));
        $this->assertSame('GET /p?phone=%2B9233•••6444&x=1', ExternalCallLog::mask('GET /p?phone=%2B923345266444&x=1'));
        $this->assertSame('{"mobile":"+9233•••6444","n":12500}', ExternalCallLog::mask('{"mobile":"+923345266444","n":12500}'));
        $this->assertSame('no number here 12345', ExternalCallLog::mask('no number here 12345'));
        $this->assertSame('{"mobile":"0334•••6444"}', ExternalCallLog::mask('{"mobile":"03345266444"}'));
    }

    public function test_prune_removes_old_rows_and_wipes_old_payloads_only(): void
    {
        $ctx = $this->createWorkspaceContext();
        $i = new ExternalIntegration(['workspace_id' => $ctx['workspace']->id, 'type' => 'epos', 'name' => 'x', 'base_url' => 'https://e.example.com', 'manifest_url' => 'https://e.example.com/m', 'key' => 'k']);
        $i->save();

        $make = fn (int $daysOld) => ExternalCallLog::create([
            'integration_id' => $i->id, 'capability' => 'c', 'outcome' => 'found', 'status' => '200', 'ms' => 5,
            'request' => 'GET /p', 'response' => '{"a":1}', 'created_at' => now()->subDays($daysOld),
        ]);
        $fresh = $make(0);
        $oneWeek = $make(7);
        $old = $make(31);

        ExternalCallLog::prune();

        $this->assertNotNull($fresh->fresh()->request);
        $this->assertNull($oneWeek->fresh()->request);
        $this->assertNull($oneWeek->fresh()->response);
        $this->assertNotNull($oneWeek->fresh());
        $this->assertNull(ExternalCallLog::find($old->id));
    }
}
