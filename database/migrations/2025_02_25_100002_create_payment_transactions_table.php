<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // coupon_id is a plain column (no FK) — coupons table is created in a later migration.
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('coupon_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 32);
            $table->string('gateway_transaction_id')->nullable()->index();
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->string('currency_code', 10)->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_reason')->nullable();
            $table->unsignedInteger('refunded_cents')->nullable();
            $table->string('invoice_path')->nullable();
            $table->unsignedInteger('tax_amount_cents')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['gateway', 'gateway_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
