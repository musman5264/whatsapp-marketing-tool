<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('conversation_id');
            // created | assigned | transferred | unassigned | status_changed |
            // handover | label_added | label_removed
            $table->string('type', 32);
            // Acting agent; null = system, automation or the contact themself
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_activities');
    }
};
