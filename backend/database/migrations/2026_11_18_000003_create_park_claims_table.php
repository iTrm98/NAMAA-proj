<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sprint 6.2: sourced claims about a park (areas, opening year, status). Conflicting values are separate rows. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('park_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('park_id')->constrained()->cascadeOnDelete();
            $table->string('field', 32);
            $table->string('value', 255);
            $table->string('source_name', 160);
            $table->string('source_url', 500)->nullable();
            $table->date('source_date')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['park_id', 'field']);
            $table->unique(['park_id', 'field', 'value', 'source_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('park_claims');
    }
};
