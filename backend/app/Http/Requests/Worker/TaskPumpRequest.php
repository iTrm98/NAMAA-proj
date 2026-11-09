<?php

namespace App\Http\Requests\Worker;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/worker/tasks/{task}/pump { on }: only the assigned worker (else
 * 404), only while the task is in progress (else 403).
 */
class TaskPumpRequest extends FormRequest
{
    public function authorize(): Response
    {
        /** @var Task $task */
        $task = $this->route('task');
        $response = Gate::inspect('work', $task);

        if ($response->denied()) {
            return $response;
        }

        return $task->status === TaskStatus::InProgress
            ? Response::allow()
            : Response::deny('Start the task before switching the pump.');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['on' => ['required', 'boolean']];
    }
}
