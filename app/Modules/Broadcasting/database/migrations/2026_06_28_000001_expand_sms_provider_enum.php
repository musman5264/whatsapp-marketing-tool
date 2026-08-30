<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL: ALTER COLUMN to extend the ENUM with new providers
        DB::statement("
            ALTER TABLE sms_provider_configs
            MODIFY COLUMN provider ENUM(
                'twilio','nexmo','messagebird','smsbd','reve','bulksmsbd',
                'sms_dot_bd','mimsms','fast2sms','amazon_sns'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE sms_provider_configs
            MODIFY COLUMN provider ENUM(
                'twilio','nexmo','messagebird','smsbd','reve','bulksmsbd',
                'sms_dot_bd','mimsms'
            ) NOT NULL
        ");
    }
};
