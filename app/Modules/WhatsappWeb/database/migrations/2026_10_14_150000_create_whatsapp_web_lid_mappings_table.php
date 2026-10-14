<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phone number WAHA gave for a LID sender, kept in the database. A cache entry lasts a day
 * and is cleared by every deploy, so the mapping was lost; this table keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_web_lid_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('session_name', 120);
            $table->string('lid', 64);
            $table->string('phone_e164', 32);
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['session_name', 'lid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_web_lid_mappings');
    }
};
