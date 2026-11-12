<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sprint 6, B5: system task titles as a template key + park id, rendered per language by the frontend. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('title_key', 40)->nullable()->after('title');
            $table->unsignedBigInteger('title_park_id')->nullable()->after('title_key');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['title_key', 'title_park_id']);
        });
    }
};
