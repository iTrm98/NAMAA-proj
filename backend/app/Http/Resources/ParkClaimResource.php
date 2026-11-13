<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ParkClaim */
class ParkClaimResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'park_id' => $this->park_id,
            'field' => $this->field,
            'value' => $this->value,
            'source_name' => $this->source_name,
            'source_url' => $this->source_url,
            'source_date' => $this->source_date?->toDateString(),
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
