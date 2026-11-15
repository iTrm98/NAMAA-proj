<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\NodeUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BrowserFrameRequest;
use App\Http\Requests\Admin\CalibrateNodeRequest;
use App\Http\Requests\Admin\CameraTestRequest;
use App\Http\Requests\Admin\RegenerateTokenRequest;
use App\Http\Requests\Admin\RelayTestRequest;
use App\Http\Requests\Admin\UpdateCameraRequest;
use App\Http\Resources\DetectionResource;
use App\Http\Resources\NodeHardwareResource;
use App\Models\Node;
use App\Services\ActivityLogger;
use App\Services\Calibration;
use App\Services\CameraPipeline;
use App\Services\NodeTokens;
use App\Services\PumpService;
use App\Services\VisionUnavailable;
use App\Support\Realtime;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Http;

/** The hardware page (api.md, "Hardware"): live node fields, calibration, relay test, token, camera. */
class NodeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $nodes = Node::whereHas('park', fn ($q) => $q->where('company_id', $request->user()->company_id)
            ->unless($request->boolean('include_test'), fn ($p) => $p->withoutTest()))
            ->unless($request->boolean('placeholders'), fn ($q) => $q->visibleOnHardware())
            ->with(['park', 'latestReading'])->orderBy('id')->get();

        return NodeHardwareResource::collection($nodes);
    }

    public function calibrate(CalibrateNodeRequest $request, Node $node, ActivityLogger $logger, Calibration $calibration): JsonResponse
    {
        $point = $request->validated('point');
        $field = $point.'_value';
        $raw = $node->latestReading->moisture_raw;
        $before = $node->only(['dry_value', 'wet_value']);

        $node->update([$field => $raw] + (\Illuminate\Support\Facades\Schema::hasColumn('nodes', 'calibrated_dry_at') ? ["calibrated_{$point}_at" => now()] : []) + ['last_moisture_pct' => $calibration->moisturePct($node->fill([$field => $raw]), $raw)]);

        $logger->log('node.calibrated', $node, ['before' => $before, 'after' => $node->only(['dry_value', 'wet_value']) + ['point' => $point, 'raw' => $raw]]);
        $this->broadcast($node, 'calibration');

        return $this->one($node);
    }

    public function relayTest(RelayTestRequest $request, Node $node, PumpService $pump, ActivityLogger $logger): JsonResponse
    {
        $until = $pump->startRelayTest($node, $request->user());
        $logger->log('node.relay_test', $node, ['after' => ['seconds' => \App\Support\Namaa::timing('relay_test_seconds'), 'real' => ! $node->park->is_test]]);
        $this->broadcast($node, 'relay_test');

        return response()->json(['data' => [
            'node_id' => $node->id,
            'seconds' => \App\Support\Namaa::timing('relay_test_seconds'),
            'until' => $until->utc()->format('Y-m-d\TH:i:s\Z'),
        ]]);
    }

    public function token(RegenerateTokenRequest $request, Node $node, NodeTokens $tokens, ActivityLogger $logger): JsonResponse
    {
        $token = $tokens->regenerate($node);
        $logger->log('node.token_regenerated', $node, ['after' => ['name' => $node->name]]);

        return response()->json(['data' => [
            'node_id' => $node->id,
            'name' => $node->name,
            'token' => $token,
            'serial_commands' => ["set node {$node->id} {$token}", 'reboot'],
            'secrets_block' => "#define NODE_ID {$node->id}\n#define NODE_TOKEN \"{$token}\"",
        ]], 201);
    }

    public function camera(UpdateCameraRequest $request, Node $node, ActivityLogger $logger): JsonResponse
    {
        $before = $node->only(['capture_mode', 'camera_url', 'camera_roi', 'litter_threshold']);
        $node->update($request->validated());
        $logger->log('node.camera_updated', $node, ['before' => $before, 'after' => $node->only(['capture_mode', 'camera_url', 'camera_roi', 'litter_threshold'])]);
        $this->broadcast($node, 'camera');

        return $this->one($node);
    }

    public function frame(BrowserFrameRequest $request, Node $node, CameraPipeline $pipeline): JsonResponse
    {
        try {
            $detection = $pipeline->process($node, $request->file('image')->get(), 'browser');
        } catch (VisionUnavailable) {
            abort(503, 'The vision service did not answer.');
        }

        return (new DetectionResource($detection))->response()->setStatusCode(201);
    }

    public function cameraTest(CameraTestRequest $request, Node $node, CameraPipeline $pipeline): JsonResponse
    {
        $started = microtime(true);
        try {
            $response = Http::timeout((int) config('namaa.camera.timeout_seconds'))
                ->get(CameraPipeline::frameUrl($node))->throw();
        } catch (ConnectionException|RequestException) {
            abort(503, 'The camera did not answer.');
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        $type = (string) ($response->header('Content-Type') ?: 'image/jpeg');
        abort_unless(str_contains($type, 'image'), 503, 'The camera did not answer with an image.');

        // People are blurred in every frame shown, the test picture too.
        try {
            $jpeg = $pipeline->blurredPreview($response->body())['jpeg'];
        } catch (VisionUnavailable) {
            abort(503, 'The vision service did not answer, so the picture is not shown (people cannot be blurred).');
        }

        return response()->json(['data' => [
            'ok' => true,
            'ms' => $ms,
            'bytes' => strlen($response->body()),
            'content_type' => 'image/jpeg',
            'image' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
        ]]);
    }

    /**
     * GET /api/admin/nodes/{node}/snapshot: one current frame, people blurred, nothing stored
     * (the picture the tray area is drawn on). 503 with camera_offline when the camera or the
     * USB camera bridge does not answer.
     */
    public function snapshot(CameraTestRequest $request, Node $node, CameraPipeline $pipeline): JsonResponse
    {
        try {
            $response = Http::timeout((int) config('namaa.camera.timeout_seconds'))->get(CameraPipeline::frameUrl($node))->throw();
        } catch (\Illuminate\Http\Client\ConnectionException|\Illuminate\Http\Client\RequestException) {
            return response()->json(['message' => 'The camera did not answer.', 'camera_offline' => true], 503);
        }
        abort_unless(str_contains((string) $response->header('Content-Type'), 'image'), 503, 'The camera did not answer with an image.');

        try {
            $preview = $pipeline->blurredPreview($response->body());
        } catch (VisionUnavailable) {
            // Never show a frame that could not be checked for people.
            return response()->json(['message' => 'The vision service did not answer.', 'vision_offline' => true], 503);
        }

        return response()->json(['data' => [
            'image' => 'data:image/jpeg;base64,'.base64_encode($preview['jpeg']),
            'image_w' => $preview['image_w'],
            'image_h' => $preview['image_h'],
        ]]);
    }

    private function one(Node $node): JsonResponse
    {
        $node->load(['park', 'latestReading']);

        return response()->json(['data' => (new NodeHardwareResource($node))->resolve()]);
    }

    private function broadcast(Node $node, string $change): void
    {
        $node->loadMissing('park');
        Realtime::send(new NodeUpdated($node->park->company_id, ['node_id' => $node->id, 'park_id' => $node->park_id, 'change' => $change]));
    }
}
