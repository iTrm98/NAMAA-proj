<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Admins see and change their own company's tasks; workers see and act on the
 * tasks assigned to them. Anything else answers 404 so other companies' and
 * other workers' tasks do not leak.
 */
class TaskPolicy
{
    /** Read the task (and its photo): an admin of the company or the assigned worker. */
    public function view(User $user, Task $task): Response
    {
        if ($user->isAdmin() && $user->company_id === $task->company_id) {
            return Response::allow();
        }

        return $this->assignedWorker($user, $task);
    }

    /** Edit, reassign, cancel: an admin of the company. */
    public function update(User $user, Task $task): Response
    {
        return $user->isAdmin() && $user->company_id === $task->company_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /** Start, done, pump: the assigned worker. */
    public function work(User $user, Task $task): Response
    {
        return $this->assignedWorker($user, $task);
    }

    private function assignedWorker(User $user, Task $task): Response
    {
        return $user->isWorker() && $task->assigned_to === $user->id && $user->company_id === $task->company_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
