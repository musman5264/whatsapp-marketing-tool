<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Nullable: unauthenticated (bad-token) requests are still logged.
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // No FK: deleting a token must NOT cascade-delete its history.
            // Set to null by a Sanctum "deleting" event; token_name is kept.
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('token_name')->nullable();

            $table->string('method', 10);
            $table->string('path', 2048);
            $table->string('route_name')->nullable();
            $table->json('query')->nullable();

            $table->smallInteger('status');
            $table->unsignedInteger('duration_ms');

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();

            $table->json('request_headers')->nullable();
            $table->mediumText('request_body')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->unsignedInteger('response_size_bytes')->nullable();
            $table->string('error_class')->nullable();

            // Immutable rows — created_at only, no updated_at.
            $table->timestamp('created_at')->nullable();

            $table->index(['client_id', 'created_at']);
            $table->index(['token_id', 'created_at']);
            $table->index(['client_id', 'status', 'created_at']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
