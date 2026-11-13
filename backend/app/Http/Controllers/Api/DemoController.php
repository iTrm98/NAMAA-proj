<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RunScenarioRequest;
use App\Http\Requests\Demo\DemoFrameRequest;
use App\Http\Requests\Demo\DemoLoginRequest;
use App\Http\Resources\DetectionResource;
use App\Jobs\FetchNdvi;
use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Models\Node;
use App\Services\CameraFeed;
use App\Services\OfflineChecker;
use App\Services\StandSetup;
use App\Models\Park;
use App\Services\NdviService;
use App\Services\SystemHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Services\CameraPipeline;
use App\Services\VisionUnavailable;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ScenarioService;
use App\Support\Namaa;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Demo helpers. Everything except the status answers 404 when DEMO_MODE is
 * false, as if the routes did not exist.
 */
class DemoController extends Controller
{
    /** GET /api/demo/status (public). */
    public function status(): JsonResponse
    {
        return response()->json(['demo_mode' => Namaa::demoMode()]);
    }

    /**
     * POST /api/auth/demo-login { role: admin | worker } (public, 10/min per IP).
     * The account comes from config, never from the request. Same response as
     * POST /api/auth/login.
     */
    public function login(DemoLoginRequest $request, ActivityLogger $logger): JsonResponse
    {
        $email = config('namaa.demo_accounts')[$request->validated('role')];
        $user = User::where('email', $email)->first();
        abort_if($user === null, 404);

        $logger->log('auth.demo_login', $user, ['after' => ['role' => $user->role->value]], user: $user);

        return AuthController::tokenResponse($user);
    }

    /**
     * GET /api/admin/demo/story: the park and node behind each demo step of the admin's company.
     * A role with no park falls back to today's config nodes; litter is the demo stand's real
     * camera when it is online, else the simulated camera (flagged `source: simulated`).
     */
    public function story(Request $request, ScenarioService $scenarios): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $row = fn (?Node $node) => $node ? [
            'park_id' => $node->park_id,
            'name_ar' => $node->park->name_ar,
            'name_en' => $node->park->name_en,
            'node' => ['id' => $node->id, 'name' => $node->name, 'source' => $node->source->value],
        ] : null;

        $story = array_map($row, $scenarios->storyNodes($companyId));

        $stand = StandSetup::find($companyId);
        $camera = $stand ? Node::where('park_id', $stand->id)->where('type', NodeType::Camera)
            ->where('source', DataSource::Real->value)->whereNull('detached_at')
            ->where('last_seen_at', '>=', now()->subSeconds(OfflineChecker::offlineSeconds(NodeType::Camera)))
            ->orderBy('id')->first() : null;
        $camera ??= CameraFeed::cameraOf($companyId);
        $story['litter'] = $row($camera?->loadMissing('park'));

        return response()->json(['data' => $story]);
    }

    /** POST /api/admin/demo/scenario { name }: the admin's company's simulated parks only. */
    public function scenario(RunScenarioRequest $request, ScenarioService $scenarios): JsonResponse
    {
        try {
            $summary = $scenarios->run($request->validated('name'), $request->user(), $request->user()->company_id, (bool) $request->validated('full', false));
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage()); // e.g. this company has no simulated node for the scenario
        }

        return response()->json(['data' => $summary]);
    }

    /**
     * POST /api/admin/demo/frame (multipart image + node_id): the stage fallback
     * when the camera board or the Wi-Fi fails. The photo goes through exactly
     * the same pipeline as a captured frame (/waste, blur, detection, litter rules).
     */
    public function frame(DemoFrameRequest $request, CameraPipeline $pipeline, ActivityLogger $logger): JsonResponse
    {
        $node = Node::findOrFail($request->validated('node_id'));
        $via = $request->validated('via') ?? 'file';

        try {
            $detection = $pipeline->process($node, $request->file('image')->get(), 'upload');
        } catch (VisionUnavailable $e) {
            abort(503, 'The vision service did not answer.');
        }

        $logger->log('demo.frame', $detection, ['after' => ['node_id' => $node->id, 'via' => $via, 'count' => $detection->count]],
            companyId: $request->user()->company_id);

        return (new DetectionResource($detection))->response()->setStatusCode(201);
    }

    /**
     * POST /api/admin/demo/ndvi { days? }: "Fetch satellite data now" for every
     * park of the admin's company that has an outline (queued, one job per park).
     * 409 while a run started in the last 10 minutes is still going.
     */
    public function ndvi(Request $request, ActivityLogger $logger): JsonResponse
    {
        $days = (int) ($request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:365']])['days'] ?? 90);
        $current = NdviService::currentRun();
        if ($current && $current['finished_at'] === null && Carbon::parse($current['started_at'])->gt(now()->subMinutes(10))) {
            return response()->json(['data' => $current], 409);
        }

        $parkIds = Park::forCompany($request->user()->company_id)->parks()->whereNotNull('polygon')->orderBy('id')->pluck('id')->all();
        abort_if($parkIds === [], 422, 'No park of your company has an outline.');

        $run = NdviService::startRun($parkIds, $days);
        foreach ($parkIds as $id) {
            FetchNdvi::dispatch($id, $days, $run['id']);
        }
        $logger->log('demo.ndvi', null, ['after' => ['parks' => $parkIds, 'days' => $days]], companyId: $request->user()->company_id);

        return response()->json(['data' => $run], 202);
    }

    /** GET /api/admin/demo/health: the namaa:doctor checks (quick: no camera /capture call). */
    public function health(Request $request, SystemHealth $health): JsonResponse
    {
        $checks = $health->checks(quick: true, companyId: $request->user()->company_id);

        return response()->json(['data' => [
            'overall' => SystemHealth::overall($checks),
            'checks' => $checks,
            'checked_at' => now()->toIso8601String(),
        ]]);
    }

    /** GET /api/admin/demo/ndvi: progress of the last run (null before any). */
    public function ndviStatus(): JsonResponse
    {
        return response()->json(['data' => NdviService::currentRun()]);
    }
}
