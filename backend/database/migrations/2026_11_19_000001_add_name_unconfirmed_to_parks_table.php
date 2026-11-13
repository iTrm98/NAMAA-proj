<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A park whose name is not confirmed yet (import status name_to_confirm): the UI shows a "name unconfirmed" label. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->boolean('name_unconfirmed')->default(false)->after('name_en');
        });
    }

    public function down(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->dropColumn('name_unconfirmed');
        });
    }
};
