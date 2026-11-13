<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuggestGreenZoneRequest;
use App\Http\Resources\NdviReadingResource;
use App\Models\NdviComposite;
use App\Models\NdviReading;
use App\Models\Park;
use App\Services\NdviService;
use App\Services\VisionClient;
use App\Services\VisionUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/** Sentinel-2 NDVI of one park: history chart, map pixels, the suggested green zone. */
class NdviController extends Controller
{
    /** GET /api/admin/parks/{park}/ndvi?days=90: oldest first. */
    public function index(Request $request, Park $park): AnonymousResourceCollection
    {
        Gate::authorize('view', $park);

        return self::history($request, $park);
    }

    /** Shared with the municipality route (read-only, any company). v1 rows are left out once the park has v2 rows. */
    public static function history(Request $request, Park $park): AnonymousResourceCollection
    {
        $days = (int) ($request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:365']])['days'] ?? 90);
        $since = now('Asia/Riyadh')->subDays($days)->toDateString();
        $v2 = NdviReading::hasV2Columns();

        $readings = $park->ndviReadings()->where('date', '>=', $since)
            ->when($v2 && $park->ndviReadings()->where('method', 'v2')->exists(), fn ($q) => $q->where('method', 'v2'))
            ->orderBy('date')->get();

        $composites = Schema::hasTable('ndvi_composites')
            ? NdviComposite::where('park_id', $park->id)->whereNotNull('value')->where('date', '>=', $since)->orderBy('date')->get()
                ->map(fn (NdviComposite $c) => [
                    'date' => $c->date->toDateString(),
                    'value' => $c->value,
                    'green_cover_pct' => $c->green_cover_pct,
                    'dates_count' => $c->dates_count,
                    'level' => $c->level,
                    'confidence' => $c->confidence,
                    'reasons' => $c->reasons ?? [],
                ])->all()
            : [];

        return NdviReadingResource::collection($readings)->additional(['composites' => $composites]);
    }

    /** GET /api/admin/parks/{park}/ndvi/pixels: the real 10 m pixels as GeoJSON squares (map, zoom 15+). */
    public function pixels(Park $park, NdviService $ndvi): JsonResponse
    {
        Gate::authorize('view', $park);

        return self::pixelsResponse($park, $ndvi);
    }

    /** Shared with the municipality route. */
    public static function pixelsResponse(Park $park, NdviService $ndvi): JsonResponse
    {
        $grid = $ndvi->pixels($park);
        abort_if($grid === null, 404, 'No satellite pixels for this park yet.');

        return response()->json($grid);
    }

    /** POST /api/admin/parks/{park}/green-zone/suggest: a DRAFT green zone. Saves nothing. */
    public function suggest(SuggestGreenZoneRequest $request, Park $park, VisionClient $vision): JsonResponse
    {
        try {
            $draft = $vision->suggestZone($park->polygon);
        } catch (VisionUnavailable) {
            return response()->json(['message' => 'The satellite service is not available. Try again in a minute.'], 503);
        }

        return response()->json(['data' => $draft]);
    }
}
