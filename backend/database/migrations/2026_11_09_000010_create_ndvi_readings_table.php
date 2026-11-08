<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ndvi_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('park_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('mean', 4, 3);
            $table->unsignedInteger('pixels');
            $table->timestamps();

            $table->unique(['park_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ndvi_readings');
    }
};
