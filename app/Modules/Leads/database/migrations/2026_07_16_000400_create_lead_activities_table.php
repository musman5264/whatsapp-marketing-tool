<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up history for a lead: one row per thing that happened to it, whether
 * the system did it (stage moved, qualified) or a person did it (rang them, left
 * a note).
 *
 * Deliberately not app/Models/AuditLog — that is an admin-facing trail keyed on
 * actor_admin_id, whereas this is tenant-facing sales history a client user
 * reads and writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('lead_id');

            // Null for system-generated entries (a queued scrape has no acting user).
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('type', 32);
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            // The timeline is always "this lead, newest first".
            $table->index(['lead_id', 'occurred_at']);
            $table->index(['workspace_id', 'occurred_at']);

            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('next_follow_up_at')->nullable()->after('stage_changed_at');
            $table->timestamp('last_activity_at')->nullable()->after('next_follow_up_at');

            // Drives the "due" / "overdue" board filter.
            $table->index(['workspace_id', 'next_follow_up_at']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'next_follow_up_at']);
            $table->dropColumn(['next_follow_up_at', 'last_activity_at']);
        });

        Schema::dropIfExists('lead_activities');
    }
};
