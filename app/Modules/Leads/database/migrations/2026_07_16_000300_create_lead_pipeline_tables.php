<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kanban pipeline + qualification scoring for scraped leads.
 *
 * Stages are per-workspace rows rather than an enum so a tenant can model their
 * own sales process. Scoring weights live alongside them for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name', 128);
            $table->string('color', 32)->default('neutral');
            $table->unsignedInteger('position')->default(0);

            // Terminal stages. Kept as two flags rather than one enum so a tenant
            // can have several won stages (e.g. "Closed" and "Retainer") if they want.
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->timestamps();

            $table->index('workspace_id');
            $table->index(['workspace_id', 'position']);
        });

        Schema::create('lead_scoring_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->unique();
            $table->json('weights')->nullable();
            $table->json('thresholds')->nullable();
            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('stage_id')->nullable()->after('workspace_id');
            $table->unsignedInteger('board_position')->default(0)->after('stage_id');
            $table->timestamp('stage_changed_at')->nullable()->after('board_position');

            $table->unsignedTinyInteger('score')->nullable()->after('review_count');
            $table->enum('score_band', ['hot', 'warm', 'cold'])->nullable()->after('score');
            $table->json('score_breakdown')->nullable()->after('score_band');
            $table->timestamp('scored_at')->nullable()->after('score_breakdown');

            $table->index(['workspace_id', 'stage_id', 'board_position'], 'leads_board_idx');
            $table->index(['workspace_id', 'score_band']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_board_idx');
            $table->dropIndex(['workspace_id', 'score_band']);
            $table->dropColumn([
                'stage_id', 'board_position', 'stage_changed_at',
                'score', 'score_band', 'score_breakdown', 'scored_at',
            ]);
        });

        Schema::dropIfExists('lead_scoring_configs');
        Schema::dropIfExists('lead_pipeline_stages');
    }
};
