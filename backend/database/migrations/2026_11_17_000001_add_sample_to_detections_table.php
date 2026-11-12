<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo frames played from data/samples remember which sample file they came
 * from ("litter/01.jpg@<sha1 prefix>"), so the demo cleanup can tell sample
 * frames from leftovers (old test photos). Null for camera and upload frames.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detections', function (Blueprint $table) {
            $table->string('sample', 100)->nullable()->after('origin');
        });
    }

    public function down(): void
    {
        Schema::table('detections', function (Blueprint $table) {
            $table->dropColumn('sample');
        });
    }
};
