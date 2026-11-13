<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A real board that posts to a detached node or a satellite_only park is still rejected; the last attempt is kept so the Hardware page can warn. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->timestamp('last_rejected_at')->nullable();
            $table->string('last_rejected_reason', 24)->nullable();
            $table->unsignedInteger('rejected_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['last_rejected_at', 'last_rejected_reason', 'rejected_count']);
        });
    }
};
