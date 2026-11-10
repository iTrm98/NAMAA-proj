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
        return [
            'date' => $this->date->toDateString(),
            'mean' => $this->mean,
            'pixels' => $this->pixels,
            'too_small' => $this->tooSmall(),
            'source' => 'sentinel-2',
        ];
    }
}
