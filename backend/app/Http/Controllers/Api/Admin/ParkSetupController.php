<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveParkSelectionRequest;
use App\Http\Resources\OsmExportResource;
use App\Http\Resources\ParkSelectionResource;
use App\Services\ActivityLogger;
use App\Services\OsmParks;
use App\Services\ParkSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Park setup: the OSM export and the selection file (data/parks). Admin only. */
class ParkSetupController extends Controller
{
    public function osm(): OsmExportResource|JsonResponse
    {
        $file = OsmParks::newestExport();
        if ($file === null) {
            return response()->json(['message' => 'No OSM export yet.', 'hint' => 'Run: docker compose exec backend php artisan namaa:parks:fetch-osm'], 404);
        }
        $collection = json_decode((string) file_get_contents($file), true) ?: ['type' => 'FeatureCollection', 'features' => []];
        $summary = OsmParks::summary($collection);

        return new OsmExportResource([
            'file' => basename($file),
            'fetched_at' => $collection['fetched_at'] ?? date('c', (int) filemtime($file)),
            'features' => $summary['features'],
            'ge_1ha' => $summary['ge_1ha'],
            'geojson' => $collection,
        ]);
    }

    public function selection(Request $request, ParkSelection $selection): ParkSelectionResource
    {
        return $this->selectionResource($request, $selection, $selection->read());
    }

    public function saveSelection(SaveParkSelectionRequest $request, ParkSelection $selection, ActivityLogger $logger): ParkSelectionResource
    {
        $collection = $selection->write($request->validated('features'));
        $logger->log('park_setup.selection_saved', null, ['after' => ['features' => count($collection['features'])]]);

        return $this->selectionResource($request, $selection, $collection);
    }

    /** @param array<string, mixed> $collection */
    private function selectionResource(Request $request, ParkSelection $selection, array $collection): ParkSelectionResource
    {
        return new ParkSelectionResource($collection + [
            'simulated' => $selection->simulatedCount($request->user()->company_id, $collection['features']),
            'cap' => (int) config('namaa.max_simulated_parks', 4),
        ]);
    }
}
