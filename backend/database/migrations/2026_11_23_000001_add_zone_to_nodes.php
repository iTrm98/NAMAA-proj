<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // Gateway-owned node: 'A' or 'B' (the demo stand zones). Null = a normal node.
            $table->string('zone', 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', fn (Blueprint $t) => $t->dropColumn('zone'));
    }
};
