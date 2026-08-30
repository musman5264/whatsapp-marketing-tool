<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // iyzico has no way to attach a price to a subscription on the fly: every checkout
        // must reference a pre-created pricing plan, which lives under a product. Both are
        // immutable once created, so we key them by a fingerprint of everything that would
        // change the plan (price, currency, cycle, mode) and reuse the reference code.
        Schema::create('iyzico_pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('billing_cycle', 10);
            $table->string('mode', 10); // test | live — sandbox and production codes are disjoint
            $table->string('fingerprint', 64)->unique();
            $table->string('product_reference_code');
            $table->string('pricing_plan_reference_code');
            $table->timestamps();

            $table->index(['mode', 'pricing_plan_reference_code'], 'iyzico_plans_mode_ref_idx');
        });

        // iyzico's checkout form is embedded HTML, not a hosted redirect, and its callback
        // carries only the token — no metadata. This table both stores the form markup for
        // the page we render and maps the token back to the user/plan it was created for.
        Schema::create('iyzico_checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token', 191)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('billing_cycle', 10);
            $table->string('pricing_plan_reference_code');
            $table->longText('checkout_form_content');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iyzico_checkout_sessions');
        Schema::dropIfExists('iyzico_pricing_plans');
    }
};
