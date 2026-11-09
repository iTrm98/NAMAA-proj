<?php

namespace App\Http\Requests\Admin;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Task;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** PATCH /api/admin/tasks/{task}: reassign, priority, title, note, cancel. Another company's task: 404. */
class UpdateTaskRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('task'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assigned_to' => ['sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $this->user()->company_id)->where('role', UserRole::Worker->value)],
            'priority' => ['sometimes', 'required', Rule::enum(TaskPriority::class)],
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'required', Rule::in([TaskStatus::Cancelled->value])],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Task $task */
            $task = $this->route('task');

            if ($validator->errors()->isEmpty() && ! $task->status->isActive()) {
                $validator->errors()->add('status', 'A done or cancelled task cannot be changed.');
            }
        }];
    }
}
