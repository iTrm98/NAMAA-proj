<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ndvi_readings', function (Blueprint $table) {
            $table->decimal('median', 4, 3)->nullable()->after('mean');
            $table->decimal('p90', 4, 3)->nullable()->after('median');
            $table->unsignedSmallInteger('pure_pixels')->nullable()->after('pixels');
            $table->decimal('weighted_pixels', 8, 1)->nullable()->after('pure_pixels');
            $table->decimal('clear_pixels', 8, 1)->nullable()->after('weighted_pixels');
            $table->decimal('usual_pixels', 8, 1)->nullable()->after('clear_pixels');
            $table->decimal('clear_fraction', 4, 3)->nullable()->after('usual_pixels');
            $table->decimal('green_cover_pct', 4, 1)->nullable()->after('clear_fraction');
            $table->string('measured_on', 12)->nullable()->after('green_cover_pct');
            $table->string('method', 4)->default('v1')->after('measured_on');
            $table->unsignedTinyInteger('confidence')->nullable()->after('method');
            $table->string('level', 16)->nullable()->after('confidence');
            $table->json('reasons')->nullable()->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('ndvi_readings', function (Blueprint $table) {
            $table->dropColumn(['median', 'p90', 'pure_pixels', 'weighted_pixels', 'clear_pixels', 'usual_pixels', 'clear_fraction', 'green_cover_pct', 'measured_on', 'method', 'confidence', 'level', 'reasons']);
        });
    }
};
