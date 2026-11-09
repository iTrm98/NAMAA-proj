<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2.5: every alert has a stored priority (it can rise: heat stress,
 * offline raise) and a task-ready time (offline alerts get a task only after
 * a delay). Existing rows are backfilled from the decision table.
 */
return new class extends Migration
{
    /** Base priority per type at the time of this migration. */
    private const PRIORITIES = [
        'urgent' => ['leak', 'no_flow', 'heat_stress'],
        'high' => ['dry', 'blockage'],
        'normal' => ['over', 'litter', 'offline', 'sensor_fault'],
        'low' => ['ndvi_drop', 'low_battery'],
    ];

    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('priority', 12)->default('normal')->after('severity');
            $table->timestamp('task_ready_at')->nullable()->after('status');
            $table->index(['status', 'priority']);
        });

        foreach (self::PRIORITIES as $priority => $types) {
            DB::table('alerts')->whereIn('type', $types)->update(['priority' => $priority]);
        }

        // Every existing alert could already get a task (Sprint 2 behaviour),
        // except open offline alerts: the offline check marks them when due.
        DB::table('alerts')
            ->where(fn ($q) => $q->where('type', '!=', 'offline')->orWhere('status', '!=', 'open'))
            ->update(['task_ready_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex(['status', 'priority']);
            $table->dropColumn(['priority', 'task_ready_at']);
        });
    }
};
