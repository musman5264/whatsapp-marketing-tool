<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('billing_cycle', 16); // monthly, yearly
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 32)->default('active'); // active, expired, cancelled
            $table->foreignId('assigned_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_subscriptions');
    }
};
