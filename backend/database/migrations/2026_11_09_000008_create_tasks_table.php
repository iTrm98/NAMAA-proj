<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('park_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160);
            $table->string('priority', 12)->default('normal');
            $table->string('status', 16)->default('open');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->text('note')->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('verified')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('time_to_resolve_min')->nullable();
            $table->decimal('water_used_l', 8, 2)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
