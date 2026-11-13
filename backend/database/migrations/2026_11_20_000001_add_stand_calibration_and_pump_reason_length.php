<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo stand (Sprint 6.2, Phase G): the stand's soil sensor is calibrated by the admin on the
 * Live device page, so the node remembers when each point was recorded (no timestamp = not
 * calibrated). The values themselves stay in nodes.dry_value / wet_value (same concept as raw_dry / raw_wet).
 * pump_logs.reason grows so it can hold auto_off_no_checkin (19 characters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->timestamp('calibrated_dry_at')->nullable();
            $table->timestamp('calibrated_wet_at')->nullable();
        });
        Schema::table('pump_logs', function (Blueprint $table) {
            $table->string('reason', 24)->change();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['calibrated_dry_at', 'calibrated_wet_at']);
        });
    }
};
