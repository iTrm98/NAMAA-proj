<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // null only for municipality users (read-only across companies).
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('role', 20)->default('worker')->after('company_id');
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->string('locale', 2)->default('ar')->after('phone');

            $table->index(['company_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'role']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropUnique(['phone']);
            $table->dropColumn(['role', 'phone', 'locale']);
        });
    }
};
