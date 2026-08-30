<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->enum('kind', ['percent', 'fixed'])->default('percent');
            $table->unsignedInteger('amount');
            $table->enum('duration', ['once', 'forever', 'repeating'])->default('once');
            $table->unsignedInteger('duration_in_months')->nullable();
            $table->json('applies_to_plan_ids')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->string('stripe_coupon_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
