<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ndvi_grids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('park_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('pixels')->default(0);
            $table->json('geojson');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ndvi_grids');
    }
};
