<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimes;
use App\Models\Detection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One camera frame checked by YOLO (api.md, Camera detections). The photo is
 * never a public URL: `image_url` is an API path the dashboard fetches with
 * its Bearer token. People in the photo are already blurred.
 *
 * @mixin Detection
 */
class DetectionResource extends JsonResource
{
    use FormatsTimes;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $prefix = $request->is('api/municipality/*') ? 'municipality' : 'admin';

        return [
            'id' => $this->id,
            'park_id' => $this->park_id,
            'node_id' => $this->node_id,
            'count' => $this->count,
            'items' => $this->items ?? [],
            'people_count' => $this->people_count,
            'model' => $this->model,
            'inference_ms' => $this->inference_ms,
            'image_w' => $this->image_w,
            'image_h' => $this->image_h,
            'origin' => $this->origin,
            // Municipality viewers fetch through their own read-only route.
            'image_url' => $this->imagePath($prefix),
            // Small variants (about 960 px / 320 px wide): the UI uses these, never the original.
            'display_url' => $this->imagePath($prefix, 'display'),
            'thumb_url' => $this->imagePath($prefix, 'thumb'),
            'captured_at' => $this->iso($this->captured_at),
        ];
    }
}
