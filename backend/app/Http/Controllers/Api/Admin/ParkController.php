<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PumpReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveOutlineRequest;
use App\Http\Requests\Admin\SwitchPumpRequest;
use App\Http\Requests\Admin\UpdateParkRequest;
use App\Http\Resources\ParkResource;
use App\Models\Park;
use App\Services\ActivityLogger;
use App\Services\ParkOutline;
use App\Services\PumpService;
use App\Services\ReadingSeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ParkController extends Controller
{
    public function __construct(private readonly PumpService $pump) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $parks = Park::forCompany($request->user()->company_id)
            ->unless($request->boolean('include_test'), fn ($q) => $q->withoutTest())
            ->with(['nodes', 'latestNdvi'])
            ->withCount(['openAlerts as open_alerts_count'])
            ->orderBy('id')
            ->get();

        return ParkResource::collection($parks);
    }

    public function show(Park $park): ParkResource
    {
        Gate::authorize('view', $park);

        return $this->detail($park);
    }

    /** Thresholds and automatic irrigation; changes logged as park.updated with before/after. */
    public function update(UpdateParkRequest $request, Park $park, ActivityLogger $logger): ParkResource
    {
        $fields = ['moisture_min', 'moisture_max', 'auto_irrigation'];
        $before = $park->only($fields);
        $park->fill($request->validated())->save();
        $after = $park->only($fields);

        $changed = array_keys(array_filter($after, fn ($value, $key) => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            $logger->log('park.updated', $park, [
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
            ]);
        }

        return $this->detail($park);
    }

    /**
     * PUT /api/admin/parks/{park}/outline: an outline drawn in the editor, with its
     * source. The area is computed from it; NDVI is recomputed on the queue.
     */
    public function outline(SaveOutlineRequest $request, Park $park, ParkOutline $outline): ParkResource
    {
        $outline->save($park, $request->ring(), $request->source(), $request->validated('reference'), $request->user());

        return $this->detail($park->refresh());
    }

    /** POST /api/admin/parks/{park}/pump: manual switch by an admin. */
    public function pump(SwitchPumpRequest $request, Park $park): JsonResponse
    {
        $node = $park->pumpNode()->first();
        abort_if($node === null, 422, 'This park has no pump.');

        $this->pump->set($node, $request->boolean('on'), PumpReason::Manual, $request->user());

        return response()->json(['data' => $this->pump->state($park->load('nodes'))]);
    }

    public function readings(Request $request, Park $park, ReadingSeries $series): JsonResponse
    {
        Gate::authorize('view', $park);

        $range = $request->validate([
            'range' => ['sometimes', Rule::in(array_keys(ReadingSeries::RANGES))],
        ])['range'] ?? '24h';

        return response()->json($series->forPark($park, $range));
    }

    private function detail(Park $park): ParkResource
    {
        $park->load(['nodes', 'latestNdvi', 'openAlerts.detection', 'activeTasks.assignee', 'activeTasks.alert', 'outlineBy:id,name', 'areaBy:id,name'])
            ->loadCount(['openAlerts as open_alerts_count']);

        return (new ParkResource($park))->detailed()->withPump($this->pump->state($park));
    }
}
