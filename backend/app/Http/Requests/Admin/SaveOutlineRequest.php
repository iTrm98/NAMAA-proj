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
        if (! $this->has('polygon')) {
            return [
                'boundary' => ['nullable', 'array', 'required_without:green_zone'],
                'green_zone' => ['nullable', 'array', 'required_without:boundary'],
                'imagery' => ['required', Rule::in(['esri', 'maptiler'])],
                'checked' => ['required', 'boolean'],
            ];
        }

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
            if ($this->isV2()) {
                $this->validateV2($validator);

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

    /** The v2 body (Sprint 6.2): { boundary?, green_zone?, imagery, checked }. */
    public function isV2(): bool
    {
        return ! $this->has('polygon');
    }

    private function validateV2(Validator $validator): void
    {
        foreach (['boundary' => ['Polygon'], 'green_zone' => ['Polygon', 'MultiPolygon']] as $key => $types) {
            $geometry = $this->input($key);
            if ($geometry === null) {
                continue;
            }
            if (! in_array($geometry['type'] ?? null, $types, true) || ! is_array($geometry['coordinates'] ?? null)) {
                $validator->errors()->add($key, "The $key must be a GeoJSON ".implode(' or ', $types).'.');

                continue;
            }
            $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];
            if ($polygons === []) {
                $validator->errors()->add($key, "The $key has no rings.");

                continue;
            }
            foreach ($polygons as $polygon) {
                if (! is_array($polygon) || $polygon === []) {
                    $validator->errors()->add($key, "The $key has an empty polygon.");

                    return;
                }
                foreach ($polygon as $ring) {
                    if (($error = $this->ringError($ring)) !== null) {
                        $validator->errors()->add($key, $error);

                        return;
                    }
                }
            }
        }
    }

    private function ringError(mixed $ring): ?string
    {
        if (! is_array($ring) || count($ring) < 4 || count($ring) > 1000) {
            return 'A ring needs at least 4 positions (the first repeated at the end).';
        }
        foreach ($ring as $p) {
            if (! is_array($p) || count($p) < 2 || ! is_numeric($p[0]) || ! is_numeric($p[1])
                || abs((float) $p[0]) > 180 || abs((float) $p[1]) > 90) {
                return 'A position must be [lng, lat] within range (lng -180..180, lat -90..90).';
            }
        }
        $ring = array_values(array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $ring));
        if ($ring[0] !== $ring[count($ring) - 1]) {
            return 'A ring must be closed (the first position repeated at the end).';
        }
        if (ParkOutline::points($ring) < 3) {
            return 'A ring needs at least 3 different points.';
        }
        if (ParkOutline::selfIntersects($ring)) {
            return 'A ring crosses itself. Move the points so that no two edges cross.';
        }

        return null;
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
