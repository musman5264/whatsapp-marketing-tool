<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_cents');
            $table->string('currency_code', 10);
            $table->string('interval', 20)->default('month');
            $table->unsignedBigInteger('monthly_price_cents')->nullable();
            $table->unsignedBigInteger('yearly_price_cents')->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->string('stripe_monthly_id', 255)->nullable();
            $table->string('stripe_yearly_id', 255)->nullable();
            $table->string('paddle_monthly_id')->nullable();
            $table->string('paddle_yearly_id')->nullable();
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->boolean('featured')->default(false);
            $table->boolean('popular')->default(false);
            $table->boolean('white_label_enabled')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
