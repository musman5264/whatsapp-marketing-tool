<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a generic JSON metadata bag to subscriptions.
 *
 * Gateways that have no hosted auto-renewing subscription product (e.g. Tap, which
 * requires merchant-initiated charges against a saved card) need somewhere to persist
 * the card-on-file identifiers used to bill future cycles. Stored here rather than as
 * dedicated columns so the schema stays gateway-agnostic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // e.g. Tap: { card_id, customer_id, payment_agreement_id, currency }
            $table->json('gateway_metadata')->nullable()->after('gateway_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('gateway_metadata');
        });
    }
};
