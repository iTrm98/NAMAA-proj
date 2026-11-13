<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A placeholder node stands for a board that is not installed yet (Model A, Model B, Camera 1): it never asks for an installation. Set by stand:setup, not by name. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('placeholder')->default(false);
        });

        // One-time backfill of the three boards that were treated as placeholders by name until now.
        DB::table('nodes')->whereIn('name', ['Model A', 'Model B', 'Camera 1'])->where('source', 'real')->update(['placeholder' => true]);
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('placeholder');
        });
    }
};
