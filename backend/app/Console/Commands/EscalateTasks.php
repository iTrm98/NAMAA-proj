<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RepeatsWithinMinute;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\TaskService;
use App\Support\Namaa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('namaa:escalate-tasks {--for=0 : keep checking for this many seconds (the schedule uses 55)}')]
#[Description('Escalate open tasks past their due time (every 60 s, 10 s demo).')]
class EscalateTasks extends Command
{
    use RepeatsWithinMinute;

    public function handle(TaskService $tasks): int
    {
        $this->repeat(Namaa::timing('escalation_check_seconds'), function () use ($tasks) {
            // Open (not started) tasks past due; urgent ones only until flagged overdue.
            $ids = Task::where('status', TaskStatus::Open)
                ->where('due_at', '<', now())
                ->where(fn ($q) => $q->where('priority', '!=', TaskPriority::Urgent->value)->orWhereNull('overdue_at'))
                ->pluck('id');

            $escalated = 0;
            foreach ($ids as $id) {
                $escalated += (int) DB::transaction(fn () => ($task = Task::lockForUpdate()->find($id)) && $tasks->escalate($task));
            }

            if ($escalated > 0) {
                $this->info(now()->format('H:i:s')." tasks escalated: $escalated");
            }
        });

        return self::SUCCESS;
    }
}
