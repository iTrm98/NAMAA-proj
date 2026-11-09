<?php

namespace App\Http\Controllers\Api\Device;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreReadingRequest;
use App\Models\Node;
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

        $ingestor->ingest($node, $request->safe()->except('node_id'));

        return response()->json([
            // The server decides the pump; nodes without a pump always get false.
            'pump' => $node->has_pump && $node->pump_target,
            'interval_s' => Namaa::timing('interval_s'),
        ], 201);
    }
}
