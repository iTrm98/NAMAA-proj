<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pump_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = system
            $table->boolean('target');
            $table->string('reason', 16);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['node_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pump_logs');
    }
};
