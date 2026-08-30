<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_configs', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32)->unique()->comment('stripe, paypal, paddle');
            $table->boolean('test_mode')->default(true);
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable()->comment('Encrypted JSON: test/live publishable_key, secret_key, webhook_secret etc.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_configs');
    }
};
