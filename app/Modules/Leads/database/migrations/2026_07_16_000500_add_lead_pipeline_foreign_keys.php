<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hardening: the pipeline tables were created without foreign keys, so nothing
 * below the application layer stopped a lead from pointing at a stage that no
 * longer exists.
 *
 * PipelineManager::deleteStage() reassigns leads before dropping a stage, but a
 * direct DELETE, a future code path or a bad import would leave orphans that
 * quietly vanish from the board (every column filters on stage_id).
 *
 * stage_id is nullOnDelete rather than cascade — losing a stage must never
 * delete the leads in it. An orphaned lead lands back in the first column via
 * PipelineManager::adoptUnstagedLeads().
 */
return new class extends Migration
{
    public function up(): void
    {
        // Any lead already pointing at a dead stage would block the constraint.
        DB::table('leads')
            ->whereNotNull('stage_id')
            ->whereNotIn('stage_id', function ($q) {
                $q->select('id')->from('lead_pipeline_stages');
            })
            ->update(['stage_id' => null]);

        Schema::table('leads', function (Blueprint $table) {
            $table->foreign('stage_id')->references('id')->on('lead_pipeline_stages')->nullOnDelete();
        });

        // Between lead_activities being created (prior migration) and this FK,
        // nothing stopped an activity from referencing a user who was then
        // deleted. Any such orphan would make the constraint below fail on an
        // existing install, so null it first — same treatment as stage_id.
        DB::table('lead_activities')
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', function ($q) {
                $q->select('id')->from('users');
            })
            ->update(['user_id' => null]);

        Schema::table('lead_activities', function (Blueprint $table) {
            // History outlives the person who wrote it; keep the row, drop the author.
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['stage_id']);
        });

        Schema::table('lead_activities', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};
