<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable(); // secrets (deploy_key, github_token) stored encrypted by the model
            $table->timestamps();
        });

        Schema::create('deployment_logs', function (Blueprint $table) {
            $table->id();
            $table->string('commit_hash', 40)->nullable();
            $table->string('commit_short', 12)->nullable();
            $table->string('branch', 100)->nullable();
            $table->text('commit_message')->nullable();
            $table->string('commit_author', 191)->nullable();
            $table->timestamp('commit_date')->nullable();
            $table->enum('action', ['deploy', 'rollback', 'pull', 'command'])->default('deploy');
            $table->enum('status', ['pending', 'in_progress', 'success', 'failed', 'reverted'])->default('pending');
            $table->string('previous_commit', 40)->nullable();
            $table->longText('output')->nullable();
            $table->text('error_output')->nullable();
            $table->unsignedBigInteger('deployed_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('commit_hash');
            $table->index('status');
            $table->index('deployed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_logs');
        Schema::dropIfExists('deployment_settings');
    }
};
