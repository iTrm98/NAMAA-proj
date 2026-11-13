<?php

namespace App\Http\Requests\Admin;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** POST /api/admin/parks/{park}/photo: one cover image, JPEG/PNG/WebP, max 5 MB. */
class PhotoRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('park'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['photo' => ['required', 'file', 'image', 'mimes:jpeg,png,webp', 'max:5120']];
    }
}
