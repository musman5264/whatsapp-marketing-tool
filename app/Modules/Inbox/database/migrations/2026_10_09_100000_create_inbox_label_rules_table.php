<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_label_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_label_id')->constrained('inbox_labels')->cascadeOnDelete();
            $table->foreignId('channel_account_id')->nullable()->constrained('channel_accounts')->nullOnDelete();
            $table->string('name', 120);
            $table->string('match_type', 24);
            $table->text('pattern')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'is_active']);
        });

        Schema::table('inbox_labels', function (Blueprint $table) {
            $table->string('wa_label_id', 64)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_labels', function (Blueprint $table) {
            $table->dropColumn('wa_label_id');
        });

        Schema::dropIfExists('inbox_label_rules');
    }
};
