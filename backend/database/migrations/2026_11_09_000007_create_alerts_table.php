<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('park_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24);
            $table->string('severity', 12);
            $table->string('status', 12)->default('open');
            $table->json('data')->nullable(); // signals that triggered it; the frontend renders the text
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['park_id', 'status', 'type']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
