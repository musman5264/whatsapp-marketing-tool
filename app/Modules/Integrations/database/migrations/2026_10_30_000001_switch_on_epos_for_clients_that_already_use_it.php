<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * EPOS and RentOyee are now switched per client (client_settings `integration_<type>_enabled`, default off).
 * A client that already has an EPOS link keeps working: its switch is set on here and never changed again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $clientIds = DB::table('external_integrations')
            ->join('workspaces', 'workspaces.id', '=', 'external_integrations.workspace_id')
            ->whereNotNull('workspaces.client_id')
            ->distinct()
            ->pluck('workspaces.client_id');

        foreach ($clientIds as $clientId) {
            $exists = DB::table('client_settings')->where('client_id', $clientId)->where('key', 'integration_epos_enabled')->exists();
            if (! $exists) {
                DB::table('client_settings')->insert([
                    'client_id' => $clientId,
                    'key' => 'integration_epos_enabled',
                    'value' => '1',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // The switch values stay: removing them would silently turn EPOS off for those clients.
    }
};
