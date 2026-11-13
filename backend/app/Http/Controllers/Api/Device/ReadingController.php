<?php

namespace App\Http\Controllers\Api\Device;

use App\Enums\SiteType;
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

        // The demo stand: the board polls every 2 s, applies the pump state and turns the relay off
        // by itself when no reply comes for 10 s. No local rule, no automatic irrigation.
        if ($park->site_type === SiteType::DemoStand) {
            return response()->json([
                'pump' => $node->has_pump && $node->pump_target,
                'interval_s' => Namaa::timing('stand_interval_seconds'),
                'pump_max_s' => (int) config('namaa.stand.pump_max_seconds'),
            ], 201);
        }

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
