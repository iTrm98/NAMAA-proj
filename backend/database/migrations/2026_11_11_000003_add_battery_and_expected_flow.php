<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2.5, field-version signals (simulated parks only for now):
 * battery % per reading (cached on the node) and the expected pump flow of a
 * node (null until the real pump is measured), used by the blockage rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            $table->decimal('battery_pct', 4, 1)->nullable()->after('water_level');
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->decimal('last_battery_pct', 4, 1)->nullable()->after('last_pump_on');
            $table->decimal('expected_flow_l_min', 5, 2)->nullable()->after('wet_value');
        });
    }

    public function down(): void
    {
        Schema::table('readings', fn (Blueprint $table) => $table->dropColumn('battery_pct'));
        Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn(['last_battery_pct', 'expected_flow_l_min']));
    }
};
