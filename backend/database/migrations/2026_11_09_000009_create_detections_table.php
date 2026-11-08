<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('park_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            $table->string('photo_path');
            $table->unsignedSmallInteger('count')->default(0);
            $table->json('boxes')->nullable(); // [[x, y, w, h, confidence], ...] in photo pixels
            $table->timestamp('captured_at');

            $table->index(['park_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detections');
    }
};
