<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4, C: test bench parks (hidden unless asked for) and the camera capture mode
 * (a browser tab can feed a camera node). Camera 1 (King Abdullah) becomes a browser
 * camera: the laptop's C920 is its camera now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('source');
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->string('capture_mode', 8)->default('url')->after('last_pump_on');
            $table->string('camera_url', 255)->nullable()->after('capture_mode');
        });

        DB::table('nodes')->where('type', 'camera')->where('name', 'Camera 1')->where('source', 'real')
            ->update(['capture_mode' => 'browser']);
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['capture_mode', 'camera_url']);
        });
        Schema::table('parks', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
