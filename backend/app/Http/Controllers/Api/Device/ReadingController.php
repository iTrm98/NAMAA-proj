<?php

namespace App\Http\Controllers\Api\Device;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreReadingRequest;
use App\Models\Node;
use App\Services\PumpService;
use App\Services\ReadingIngestor;
use App\Support\Namaa;
use Illuminate\Http\JsonResponse;

class ReadingController extends Controller
{
    public function store(StoreReadingRequest $request, ReadingIngestor $ingestor): JsonResponse
    {
        /** @var Node $node */
        $node = $request->user();

        abort_unless((int) $request->validated('node_id') === $node->id, 403, 'node_id does not match the token.');

        $park = $node->park;
        $ingestor->ingest($node, $request->safe()->except('node_id'));

        return response()->json([
            // The server decides the pump; nodes without a pump always get false.
            'pump' => $node->has_pump && $node->pump_target,
            // While a relay test runs the board polls fast, so it sees the short test pump window.
            'interval_s' => PumpService::relayTestUntil($node->id) ? 5 : Namaa::timing('interval_s'),
            // What the board does by itself when the server cannot be reached (percent of the park's range).
            'local_rule' => [
                'min' => $park->moisture_min,
                'target' => (int) round(($park->moisture_min + $park->moisture_max) / 2),
                'max_run_s' => Namaa::timing('pump_max_run_seconds'),
                // So the board can turn its raw soil reading into a percent.
                'dry_value' => $node->dry_value,
                'wet_value' => $node->wet_value,
            ],
        ], 201);
    }
}
