<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('park_id')->constrained()->cascadeOnDelete(); // denormalised for park charts
            $table->unsignedSmallInteger('moisture_raw')->nullable();
            $table->decimal('moisture_pct', 5, 1)->nullable();
            $table->decimal('flow', 6, 3)->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->decimal('humidity', 4, 1)->nullable();
            $table->boolean('pump_on')->nullable();
            $table->unsignedSmallInteger('water_level')->nullable();
            $table->string('source', 16)->default('real');
            $table->timestamp('created_at')->useCurrent(); // append-only: no updated_at

            $table->index(['park_id', 'created_at']);
            $table->index(['node_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('readings');
    }
};
