<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2.5: new parks start with moisture_min 30 (alert guide). Only the
 * column default changes; existing parks keep their values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->unsignedTinyInteger('moisture_min')->default(30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->unsignedTinyInteger('moisture_min')->default(25)->change();
        });
    }
};
