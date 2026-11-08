<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar', 120);
            $table->string('name_en', 120);
            $table->string('district', 120)->nullable();
            $table->string('park_type', 20)->default('public_park');
            $table->unsignedInteger('area_m2')->nullable();
            $table->json('polygon'); // GeoJSON Polygon, [lon, lat] order (WGS84)
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedTinyInteger('moisture_min')->default(25); // proposed
            $table->unsignedTinyInteger('moisture_max')->default(70); // proposed
            $table->unsignedInteger('baseline_l_per_day')->nullable(); // fixed-schedule water use (assumption for impact)
            $table->string('status', 16)->default('offline');
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('last_reading_at')->nullable();
            $table->string('source', 16)->default('real');
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parks');
    }
};
