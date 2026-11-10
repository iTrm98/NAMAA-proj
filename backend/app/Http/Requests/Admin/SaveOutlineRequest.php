<?php

namespace App\Http\Requests\Admin;

use App\Models\Park;
use App\Services\ParkOutline;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /api/admin/parks/{park}/outline (Sprint 3.1, D2): an outline drawn in the
 * editor. A GeoJSON Polygon with one ring ([lng, lat]; closed or not), at least
 * 3 distinct points, no self-intersection, every point within 2 km of the park's
 * marker. source = traced (default) or official (then a reference is required).
 */
class SaveOutlineRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('park'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'polygon' => ['required', 'array'],
            'polygon.type' => ['required', Rule::in(['Polygon'])],
            'polygon.coordinates' => ['required', 'array', 'size:1'],
            'polygon.coordinates.0' => ['required', 'array', 'min:3', 'max:500'],
            'polygon.coordinates.0.*' => ['required', 'array', 'size:2'],
            'polygon.coordinates.0.*.0' => ['required', 'numeric', 'between:-180,180'],
            'polygon.coordinates.0.*.1' => ['required', 'numeric', 'between:-90,90'],
            'source' => ['sometimes', Rule::in(['traced', 'official'])],
            'reference' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => $this->input('source') === 'official')],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            /** @var Park $park */
            $park = $this->route('park');
            $ring = $this->ring();

            if (ParkOutline::points($ring) < 3) {
                $validator->errors()->add('polygon', 'An outline needs at least 3 different points.');
            } elseif (ParkOutline::selfIntersects($ring)) {
                $validator->errors()->add('polygon', 'The outline crosses itself. Move the points so that no two edges cross.');
            } elseif (($far = ParkOutline::maxDistance($ring, $park->lat, $park->lng)) > ParkOutline::MAX_DISTANCE_M) {
                $validator->errors()->add('polygon', sprintf('The outline must stay within 2 km of the park (a point is %.1f km away).', $far / 1000));
            }
        }];
    }

    /** @return list<array{0: float, 1: float}> */
    public function ring(): array
    {
        return array_map(fn ($p) => [(float) $p[0], (float) $p[1]], array_values($this->input('polygon.coordinates.0', [])));
    }

    public function source(): string
    {
        return (string) ($this->validated('source') ?? 'traced');
    }
}
