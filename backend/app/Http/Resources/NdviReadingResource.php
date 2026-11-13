<?php

namespace App\Http\Resources;

use App\Models\NdviReading;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NdviReading */
class NdviReadingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $v2 = ($this->method ?? 'v1') === 'v2';

        return [
            'date' => $this->date->toDateString(),
            'mean' => $this->mean,
            'pixels' => $this->pixels,
            'too_small' => $v2 ? ($this->level === 'not_measurable') : $this->tooSmall(),
            'source' => 'sentinel-2',
            'method' => $this->method ?? 'v1',
            'median' => $this->median,
            'p90' => $this->p90,
            'pure_pixels' => $this->pure_pixels,
            'clear_fraction' => $this->clear_fraction,
            'green_cover_pct' => $this->green_cover_pct,
            'level' => $this->level,
            'reasons' => $this->reasons ?? [],
        ];
    }
}
