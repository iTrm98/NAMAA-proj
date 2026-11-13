<?php

namespace App\Http\Requests\Admin;

use App\Services\ParkSelection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** PUT /api/admin/park-setup/selection: the selected features; at most 4 simulated parks per company. */
class SaveParkSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'features' => ['present', 'array', 'max:500'],
            'features.*.type' => ['required', Rule::in(['Feature'])],
            'features.*.geometry' => ['required', 'array'],
            'features.*.geometry.type' => ['required', Rule::in(['Polygon', 'MultiPolygon'])],
            'features.*.geometry.coordinates' => ['required', 'array'],
            'features.*.properties' => ['required', 'array'],
            'features.*.properties.osm_id' => ['nullable', 'string', 'max:32'],
            'features.*.properties.slug' => ['nullable', 'string', 'max:80'],
            'features.*.properties.name' => ['nullable', 'string', 'max:160'],
            'features.*.properties.name_ar' => ['nullable', 'string', 'max:120'],
            'features.*.properties.name_en' => ['nullable', 'string', 'max:120'],
            'features.*.properties.district' => ['nullable', 'string', 'max:120'],
            'features.*.properties.monitoring_mode' => ['nullable', Rule::in(['simulated', 'real_sensors', 'satellite_only'])],
            'features.*.properties.geometry_source' => ['nullable', Rule::in(['osm', 'traced'])],
            'features.*.properties.green_zone' => ['nullable', 'array'],
            'features.*.properties.claims' => ['nullable', 'array'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $cap = (int) config('namaa.max_simulated_parks', 4);
            if (app(ParkSelection::class)->simulatedCount($this->user()->company_id, $this->input('features')) > $cap) {
                $validator->errors()->add('features', "At most {$cap} parks can be simulated per company.");
            }
        }];
    }
}
