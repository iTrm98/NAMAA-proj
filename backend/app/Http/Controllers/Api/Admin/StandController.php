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
use App\Http\Requests\Admin\StandCameraModeRequest;
use App\Http\Requests\Admin\StandTestFrameRequest;
use App\Services\CameraMode;
use App\Services\CameraOffline;
use App\Services\CameraTester;
use App\Services\PumpService;
use App\Services\StandReadiness;
use App\Services\VisionUnavailable;
use App\Services\StandLive;
use App\Services\StandGatewayLive;
use App\Services\GatewayClient;
use App\Services\GatewayUnreachable;
use App\Http\Requests\Admin\StandAutoRequest;
use App\Http\Requests\Admin\StandTestRequest;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The demo stand's "Live device" page (api.md, "Demo stand"). Company scoped through the stand lookup (404 without one). */
class StandController extends Controller
{
    public function __construct(private readonly StandLive $live, private readonly StandGatewayLive $gateway) {}

    public function live(Request $request): JsonResponse
    {
        [$stand, $node] = $this->resolve($request, benchRequired: false);

        return $this->respond($stand, $node);
    }

    /** Zone given: the Gateway runs the pump (it decides). No zone: the old Bench A switch. */
    public function pump(StandPumpRequest $request, PumpService $pump, GatewayClient $client, ActivityLogger $logger): JsonResponse
    {
        if ($zone = $request->validated('zone')) {
            [$stand, $node] = $this->resolve($request, benchRequired: false);
            $target = Node::where('park_id', $stand->id)->where('zone', $zone)->where('type', 'sensor')->first();
            abort_if($target === null, 404, 'No such zone.');
            $on = $request->boolean('on');
            try {
                $client->command($zone, $on, $request->validated('seconds'));
            } catch (GatewayUnreachable) {
                return response()->json(['error' => 'gateway_unreachable'], 502);
            }
            $logger->log('pump.commanded', $target, ['after' => ['zone' => $zone, 'on' => $on, 'seconds' => $request->validated('seconds')]], user: $request->user(), companyId: $stand->company_id);

            return $this->respond($stand, $node);
        }

        [$stand, $node] = $this->resolve($request);
        $on = $request->boolean('on');
        abort_if($on && ! $this->live->online($node), 422, 'The board is offline.');

        $pump->set($node, $on, PumpReason::Manual, $request->user());

        return $this->respond($stand, $node);
    }

    public function auto(StandAutoRequest $request, GatewayClient $client, ActivityLogger $logger): JsonResponse
    {
        [$stand, $node] = $this->resolve($request, benchRequired: false);
        try {
            $client->auto($request->boolean('on'));
        } catch (GatewayUnreachable) {
            return response()->json(['error' => 'gateway_unreachable'], 502);
        }
        $logger->log('gateway.auto_commanded', $stand, ['after' => ['auto' => $request->boolean('on')]], user: $request->user(), companyId: $stand->company_id);

        return $this->respond($stand, $node);
    }

    /** One part of the pre-demo check run on request: a 3 s pump, one camera frame, or a litter forward. */
    public function test(StandTestRequest $request, StandReadiness $readiness): JsonResponse
    {
        [$stand] = $this->resolve($request, benchRequired: false);

        return response()->json(['data' => $readiness->test($stand, $request->validated('key'))]);
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

    public function cameraMode(StandCameraModeRequest $request): JsonResponse
    {
        [$stand, $node] = $this->resolve($request, benchRequired: false);
        CameraMode::set($request->validated('mode'));

        return $this->respond($stand, $node);
    }

    /** Test mode only: one frame through the litter AI at the live threshold. Creates no detection, alert or task. */
    public function testFrame(StandTestFrameRequest $request, CameraTester $tester): JsonResponse
    {
        [$stand] = $this->resolve($request, benchRequired: false);
        abort_unless(CameraMode::isTest(), 409, 'The camera is not in Test mode.');
        $camera = $this->camera($stand);
        $threshold = (float) ($request->validated('threshold') ?? $camera->litter_threshold ?? config('namaa.camera.litter_threshold'));

        try {
            return response()->json(['data' => $tester->frame($camera, $threshold)]);
        } catch (CameraOffline) {
            return response()->json(['message' => 'The camera did not answer.', 'camera_offline' => true], 503);
        } catch (VisionUnavailable) {
            // Never show a frame that could not be checked for people.
            return response()->json(['message' => 'The vision service did not answer.', 'vision_offline' => true], 503);
        }
    }

    /** Test mode only: save the newest blurred test frame with its boxes to storage/app/private/test-snapshots. */
    public function testSnapshot(Request $request, CameraTester $tester): JsonResponse
    {
        $this->resolve($request, benchRequired: false);
        abort_unless(CameraMode::isTest(), 409, 'The camera is not in Test mode.');
        $path = $tester->snapshot();
        abort_if($path === null, 422, 'No test frame yet.');

        return response()->json(['data' => ['path' => $path]], 201);
    }

    public function readiness(Request $request, StandReadiness $readiness): JsonResponse
    {
        [$stand] = $this->resolve($request, benchRequired: false);

        return response()->json(['data' => $readiness->check($stand)]);
    }

    private function camera(Park $stand): Node
    {
        $camera = Node::where('park_id', $stand->id)->where('type', 'camera')->orderBy('id')->first();
        abort_if($camera === null, 404, 'No stand camera.');

        return $camera;
    }

    /** @return array{Park, ?Node} */
    private function resolve(Request $request, bool $benchRequired = true): array
    {
        $stand = $this->live->stand($request->user()->company_id);
        abort_if($stand === null, 404, 'No demo stand.');
        $node = $this->live->node($stand);
        abort_if($node === null && ($benchRequired || ! $this->gateway->hasZones($stand)), 404, 'No demo stand board.');

        return [$stand, $node];
    }

    private function respond(Park $stand, ?Node $node): JsonResponse
    {
        return response()->json(['data' => $this->gateway->envelope($stand, $node?->refresh())]);
    }
}
