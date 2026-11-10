<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3, A2: a dry / heat-stress task has a watering target (the middle of
 * the park's range). Done below it is allowed but recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->decimal('done_moisture_pct', 5, 1)->nullable()->after('done_photo_path');
            $table->boolean('done_below_target')->nullable()->after('done_moisture_pct');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['done_moisture_pct', 'done_below_target']);
        });
    }
};
