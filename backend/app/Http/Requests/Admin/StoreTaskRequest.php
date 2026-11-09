<?php

namespace App\Http\Requests\Admin;

use App\Enums\AlertStatus;
use App\Enums\TaskPriority;
use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\Park;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/admin/tasks: from an open alert (park taken from it) or free (park_id).
 * Referenced records of another company are a validation error, never a hint
 * that they exist.
 */
class StoreTaskRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'alert_id' => ['nullable', 'integer'],
            'park_id' => ['required_without:alert_id', 'nullable', 'integer',
                Rule::exists('parks', 'id')->where('company_id', $companyId)],
            'assigned_to' => ['nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId)->where('role', UserRole::Worker->value)],
            'title' => ['required', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:1000'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('alert_id')) {
                return;
            }

            $alert = Alert::with('park')->find($this->integer('alert_id'));

            if (! $alert || $alert->park->company_id !== $this->user()->company_id) {
                $validator->errors()->add('alert_id', 'The selected alert is invalid.');

                return;
            }

            if ($alert->status !== AlertStatus::Open) {
                $validator->errors()->add('alert_id', 'The alert is already resolved.');
            } elseif (! $alert->isTaskReady()) {
                // Offline: map first, task later (decision table).
                $validator->errors()->add('alert_id', 'This alert is still being watched; a task can be created later.');
            } elseif (Task::where('alert_id', $alert->id)->active()->exists()) {
                $validator->errors()->add('alert_id', 'This alert already has an open task.');
            } elseif ($this->filled('park_id') && $this->integer('park_id') !== $alert->park_id) {
                $validator->errors()->add('park_id', 'The park does not match the alert.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function taskData(): array
    {
        return $this->safe()->only(['alert_id', 'park_id', 'assigned_to', 'title', 'note', 'priority']);
    }
}
