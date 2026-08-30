<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country', 2);
            $table->string('region')->nullable();
            $table->decimal('percentage', 5, 2);
            $table->boolean('inclusive')->default(false);
            $table->boolean('enabled')->default(true);
            $table->string('stripe_tax_rate_id')->nullable();
            $table->timestamps();

            $table->index(['country', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
