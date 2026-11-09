<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2.5: response times and escalation. due_at = created (or last
 * priority change) + the response time of the priority; an open task past
 * due_at is escalated one level, an urgent one is flagged overdue.
 * Existing active tasks get a due time from now with the normal timings.
 */
return new class extends Migration
{
    private const RESPONSE = ['urgent' => 3600, 'high' => 21600, 'normal' => 172800, 'low' => 604800];

    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->after('status');
            $table->timestamp('escalated_at')->nullable()->after('due_at');
            $table->unsignedSmallInteger('escalation_count')->default(0)->after('escalated_at');
            $table->timestamp('overdue_at')->nullable()->after('escalation_count');
            $table->index(['status', 'due_at']);
        });

        foreach (self::RESPONSE as $priority => $seconds) {
            DB::table('tasks')->whereIn('status', ['open', 'in_progress'])->where('priority', $priority)
                ->update(['due_at' => now()->addSeconds($seconds)]);
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_at']);
            $table->dropColumn(['due_at', 'escalated_at', 'escalation_count', 'overdue_at']);
        });
    }
};
