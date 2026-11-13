<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PumpReason;
use App\Events\NodeUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StandCalibrateRequest;
use App\Http\Requests\Admin\StandPumpRequest;
use App\Models\Node;
use App\Models\Park;
use App\Services\ActivityLogger;
use App\Services\PumpService;
use App\Services\StandLive;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The demo stand's "Live device" page (api.md, "Demo stand"). Company scoped through the stand lookup (404 without one). */
class StandController extends Controller
{
    public function __construct(private readonly StandLive $live) {}

    public function live(Request $request): JsonResponse
    {
        [$stand, $node] = $this->resolve($request);

        return $this->respond($stand, $node);
    }

    public function pump(StandPumpRequest $request, PumpService $pump): JsonResponse
    {
        [$stand, $node] = $this->resolve($request);
        $on = $request->boolean('on');
        abort_if($on && ! $this->live->online($node), 422, 'The board is offline.');

        $pump->set($node, $on, PumpReason::Manual, $request->user());

        return $this->respond($stand, $node);
    }

    public function calibrate(StandCalibrateRequest $request, ActivityLogger $logger): JsonResponse
    {
        [$stand, $node] = $this->resolve($request);
        $point = $request->validated('point');
        $raw = (int) $node->readings()->latest('created_at')->latest('id')->value('moisture_raw');
        $before = $node->only(['dry_value', 'wet_value']);

        $node->update([$point.'_value' => $raw, "calibrated_{$point}_at" => now()]);

        $logger->log('node.calibrated', $node, ['before' => $before, 'after' => $node->only(['dry_value', 'wet_value']) + ['point' => $point, 'raw' => $raw]], user: $request->user(), companyId: $stand->company_id);
        Realtime::send(new NodeUpdated($stand->company_id, ['node_id' => $node->id, 'park_id' => $stand->id, 'change' => 'calibration']));

        return $this->respond($stand, $node);
    }

    /** @return array{Park, Node} */
    private function resolve(Request $request): array
    {
        $stand = $this->live->stand($request->user()->company_id);
        $node = $stand ? $this->live->node($stand) : null;
        abort_if($node === null, 404, 'No demo stand board.');

        return [$stand, $node];
    }

    private function respond(Park $stand, Node $node): JsonResponse
    {
        return response()->json(['data' => $this->live->payload($stand, $node->refresh())]);
    }
}
