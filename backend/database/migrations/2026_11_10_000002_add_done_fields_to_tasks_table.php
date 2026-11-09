<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2: the worker's "done" report gets its own columns, so `note` is the
 * admin's instruction. `verify_reason` explains a failed verification
 * (not_recovered | no_data). Status `cancelled` needs no change (varchar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->text('done_note')->nullable()->after('done_at');
            $table->string('done_photo_path')->nullable()->after('done_note');
            $table->string('verify_reason', 24)->nullable()->after('verified_at');
        });

        // Existing done tasks (demo seed): their note and photo were the worker's report.
        DB::table('tasks')->where('status', 'done')->whereNull('done_note')->update([
            'done_note' => DB::raw('note'),
            'done_photo_path' => DB::raw('photo_path'),
            'note' => null,
            'photo_path' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['done_note', 'done_photo_path', 'verify_reason']);
        });
    }
};
