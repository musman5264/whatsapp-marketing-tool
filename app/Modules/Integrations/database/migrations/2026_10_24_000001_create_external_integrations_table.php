<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('name', 191);
            $table->string('base_url', 255);
            $table->string('manifest_url', 512);
            $table->text('key'); // encrypted by the model cast
            $table->string('manifest_version', 64)->nullable();
            $table->json('manifest')->nullable();
            $table->json('manifest_issues')->nullable();
            $table->timestamp('manifest_fetched_at')->nullable();
            $table->string('status', 16)->default('connected');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'type', 'base_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_integrations');
    }
};
