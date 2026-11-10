<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\NdviReadingResource;
use App\Models\Park;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Sentinel-2 NDVI history of one park (park page chart). */
class NdviController extends Controller
{
    /** GET /api/admin/parks/{park}/ndvi?days=90: oldest first. */
    public function index(Request $request, Park $park): AnonymousResourceCollection
    {
        Gate::authorize('view', $park);

        return self::history($request, $park);
    }

    /** Shared with the municipality route (read-only, any company). */
    public static function history(Request $request, Park $park): AnonymousResourceCollection
    {
        $days = (int) ($request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:365']])['days'] ?? 90);

        return NdviReadingResource::collection(
            $park->ndviReadings()
                ->where('date', '>=', now('Asia/Riyadh')->subDays($days)->toDateString())
                ->orderBy('date')
                ->get()
        );
    }
}
