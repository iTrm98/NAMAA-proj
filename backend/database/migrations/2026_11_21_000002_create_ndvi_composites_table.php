<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ndvi_composites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('park_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('window_days')->default(30);
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedTinyInteger('dates_count')->default(0);
            $table->decimal('value', 4, 3)->nullable();
            $table->decimal('mean', 4, 3)->nullable();
            $table->decimal('p90', 4, 3)->nullable();
            $table->decimal('green_cover_pct', 4, 1)->nullable();
            $table->decimal('clear_fraction', 4, 3)->nullable();
            $table->decimal('consistency_mad', 4, 3)->nullable();
            $table->unsignedSmallInteger('pure_pixels')->default(0);
            $table->decimal('usual_pixels', 8, 1)->nullable();
            $table->decimal('inner_width_m', 6, 1)->nullable();
            $table->decimal('baseline_median', 4, 3)->nullable();
            $table->decimal('baseline_mad', 4, 3)->nullable();
            $table->unsignedSmallInteger('baseline_n')->default(0);
            $table->string('baseline_kind', 10)->default('none');
            $table->decimal('z', 5, 2)->nullable();
            $table->decimal('rel_drop', 5, 3)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('level', 16);
            $table->json('reasons')->nullable();
            $table->string('measured_on', 12)->nullable();
            $table->json('flags')->nullable();
            $table->timestamps();

            $table->unique(['park_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ndvi_composites');
    }
};
