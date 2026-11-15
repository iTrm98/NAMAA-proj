<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // Camera nodes: tray area polygon (normalized [[x, y], ...], 0..1) and own litter threshold.
            $table->json('camera_roi')->nullable();
            $table->decimal('litter_threshold', 4, 3)->nullable();
        });

        Schema::table('detections', function (Blueprint $table) {
            // Items that passed the tray area and threshold, before the 2-of-3 persistence check.
            $table->json('candidates')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('detections', fn (Blueprint $t) => $t->dropColumn('candidates'));
        Schema::table('nodes', fn (Blueprint $t) => $t->dropColumn(['camera_roi', 'litter_threshold']));
    }
};
