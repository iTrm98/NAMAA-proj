<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3, phase C: camera frames checked by YOLO (POST /waste) and the
 * litter rules. A detection keeps the vision result; an alert remembers the
 * frame that raised it (shown in the UI, never pruned).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detections', function (Blueprint $table) {
            // [{label, confidence, box: [x1, y1, x2, y2]}] in pixels of the stored photo.
            $table->json('items')->nullable()->after('boxes');
            $table->unsignedSmallInteger('people_count')->default(0)->after('items');
            $table->string('model', 60)->nullable()->after('people_count');
            $table->unsignedInteger('inference_ms')->nullable()->after('model');
            $table->unsignedSmallInteger('image_w')->nullable()->after('inference_ms');
            $table->unsignedSmallInteger('image_h')->nullable()->after('image_w');
            // camera (captured from CAMERA_URL) | upload (demo page: file or laptop webcam).
            $table->string('origin', 16)->default('camera')->after('image_h');
            $table->index(['node_id', 'captured_at']);
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->foreignId('detection_id')->nullable()->after('node_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('detection_id');
        });

        Schema::table('detections', function (Blueprint $table) {
            $table->dropIndex(['node_id', 'captured_at']);
            $table->dropColumn(['items', 'people_count', 'model', 'inference_ms', 'image_w', 'image_h', 'origin']);
        });
    }
};
