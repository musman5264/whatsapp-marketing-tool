<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * google_place_id was globally unique, so a Google business could exist as a lead
 * in exactly one workspace across the whole install. The scraper's updateOrCreate
 * keyed on that column alone, which meant a second tenant scraping the same area
 * silently reassigned the first tenant's lead to itself.
 *
 * Uniqueness belongs per workspace: the same restaurant is a legitimate lead for
 * any number of tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['google_place_id']);
            $table->unique(['workspace_id', 'google_place_id'], 'leads_workspace_place_unique');
        });
    }

    /**
     * Rolling back re-imposes global uniqueness and will fail if two workspaces
     * have since scraped the same place — which is exactly the state this
     * migration exists to allow. Deduplicate before rolling back.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique('leads_workspace_place_unique');
            $table->unique('google_place_id');
        });
    }
};
