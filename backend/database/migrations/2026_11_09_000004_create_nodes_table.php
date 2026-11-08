<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('park_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('name', 80);
            $table->boolean('has_pump')->default(false);
            $table->boolean('pump_target')->default(false);
            $table->unsignedSmallInteger('dry_value')->default(3200); // proposed, calibrate per board
            $table->unsignedSmallInteger('wet_value')->default(1300); // proposed, calibrate per board
            $table->timestamp('last_seen_at')->nullable()->index();

            // Cached latest values (copied from the newest reading).
            $table->decimal('last_moisture_pct', 5, 1)->nullable();
            $table->decimal('last_flow', 6, 3)->nullable();
            $table->decimal('last_temperature', 4, 1)->nullable();
            $table->decimal('last_humidity', 4, 1)->nullable();
            $table->boolean('last_pump_on')->nullable();

            $table->string('source', 16)->default('real');
            $table->timestamps();

            $table->index(['park_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
