<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->string('event_id')->nullable();
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['gateway', 'event_id']);
            $table->index(['event_type', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
    }
};
