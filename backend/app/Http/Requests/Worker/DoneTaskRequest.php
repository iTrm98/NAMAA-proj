<?php

namespace App\Http\Requests\Worker;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** POST /api/worker/tasks/{task}/done (multipart). Another worker's task: 404 before validation. */
class DoneTaskRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('work', $this->route('task'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
            // Photo from the phone camera, stored on the private disk (task-photos/).
            'photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ];
    }
}
