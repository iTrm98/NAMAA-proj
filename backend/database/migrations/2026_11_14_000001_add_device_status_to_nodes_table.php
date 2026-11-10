<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sprint 4, B: what a board reports about itself (signal, uptime, firmware, connection path). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->smallInteger('rssi')->nullable()->after('last_battery_pct');
            $table->unsignedInteger('uptime_s')->nullable()->after('rssi');
            $table->string('fw_version', 32)->nullable()->after('uptime_s');
            $table->string('via', 8)->nullable()->after('fw_version');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['rssi', 'uptime_s', 'fw_version', 'via']);
        });
    }
};
