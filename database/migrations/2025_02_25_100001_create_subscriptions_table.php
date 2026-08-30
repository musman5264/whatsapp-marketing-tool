<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('billing_cycle', 16)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('gateway', 32);
            $table->string('gateway_subscription_id')->nullable()->index();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
