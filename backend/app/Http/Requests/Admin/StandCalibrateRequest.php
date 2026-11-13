<?php

namespace App\Http\Requests\Admin;

use App\Services\StandLive;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST /api/admin/stand/calibrate { point: dry|wet }: stores the board's latest raw value. */
class StandCalibrateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['point' => ['required', Rule::in(['dry', 'wet'])]];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->has('point')) {
                return;
            }
            $live = app(StandLive::class);
            $stand = $live->stand($this->user()->company_id);
            $node = $stand ? $live->node($stand) : null;
            if (! $node) {
                return; // the controller answers 404
            }
            $raw = $node->readings()->latest('created_at')->latest('id')->value('moisture_raw');

            if ($raw === null) {
                $v->errors()->add('point', 'The board has not sent a raw moisture value yet.');
            } elseif (! $live->online($node)) {
                $v->errors()->add('point', 'The board is offline: its latest raw value is stale.');
            } else {
                $dry = $this->input('point') === 'dry' ? $raw : ($node->calibrated_dry_at ? $node->dry_value : null);
                $wet = $this->input('point') === 'wet' ? $raw : ($node->calibrated_wet_at ? $node->wet_value : null);
                if ($dry !== null && $wet !== null && $dry <= $wet) {
                    $v->errors()->add('point', 'The dry value must stay above the wet value.');
                }
            }
        }];
    }
}
