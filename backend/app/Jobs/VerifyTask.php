<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\TaskVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks one done task (TaskVerifier). Since Sprint 2.5 the scheduler runs
 * namaa:verify-tasks repeatedly for every waiting task; this job stays for a
 * single on-demand check.
 */
class VerifyTask implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $taskId) {}

    public function handle(TaskVerifier $verifier): void
    {
        $task = Task::find($this->taskId);

        if ($task) {
            $verifier->verify($task);
        }
    }
}
