<?php

namespace App\Http\Controllers\Api\Municipality;

use App\Http\Controllers\Api\Admin\DetectionController;
use App\Http\Controllers\Api\Admin\NdviController;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImpactRequest;
use App\Http\Resources\AlertResource;
use App\Http\Resources\ParkResource;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Detection;
use App\Models\Park;
use App\Services\ImpactService;
use App\Services\OverviewService;
use App\Services\TimelineService;
use App\Services\PumpService;
use App\Services\ReadingSeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only, across all companies (role municipality). Same shapes as the
 * admin routes, plus `company`. There is no write route in this group.
 */
class ParkController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $parks = Park::parks()->withoutTest()->with(['nodes.latestPumpLog', 'company', ...Park::satelliteRelations()])
            ->withCount(['openAlerts as open_alerts_count'])
            ->orderBy('company_id')
            ->orderBy('id')
            ->get();

        return ParkResource::collection($parks);
    }

    /** GET /api/municipality/parks/{park}: the admin park detail, read-only. */
    /** GET /api/municipality/parks/{park}/photo: read-only stream of the cover photo (no upload or delete). */
    public function photo(Park $park): StreamedResponse
    {
        abort_if($park->is_test, 404);
        abort_unless($park->cover_photo_path && Storage::disk('local')->exists($park->cover_photo_path), 404);

        return Storage::disk('local')->response($park->cover_photo_path);
    }

    public function show(Park $park, PumpService $pump): ParkResource
    {
        abort_if($park->is_test, 404);

        $park->load(['nodes', 'company', ...Park::satelliteRelations(), 'openAlerts.detection', 'activeTasks.assignee', 'activeTasks.alert', 'outlineBy:id,name', 'areaBy:id,name'])
            ->loadCount(['openAlerts as open_alerts_count']);

        return (new ParkResource($park))->detailed()->withPump($pump->state($park));
    }

    /** GET /api/municipality/parks/{park}/readings?range=24h */
    public function readings(Request $request, Park $park, ReadingSeries $series): JsonResponse
    {
        abort_if($park->is_test, 404);

        $range = $request->validate([
            'range' => ['sometimes', Rule::in(array_keys(ReadingSeries::RANGES))],
        ])['range'] ?? '24h';

        return response()->json($series->forPark($park, $range));
    }

    /** GET /api/municipality/parks/{park}/ndvi?days=90 */
    public function ndvi(Request $request, Park $park): AnonymousResourceCollection
    {
        abort_if($park->is_test, 404);

        return NdviController::history($request, $park);
    }

    /** GET /api/municipality/parks/{park}/detections?limit=6 */
    public function detections(Request $request, Park $park): AnonymousResourceCollection
    {
        abort_if($park->is_test, 404);

        return DetectionController::latest($request, $park);
    }

    /** GET /api/municipality/detections/{detection}/image (people already blurred). */
    public function detectionImage(Detection $detection): \Symfony\Component\HttpFoundation\Response
    {
        abort_if($detection->park->is_test, 404);

        return DetectionController::stream($detection);
    }

    /** GET /api/municipality/overview: the admin overview across all companies. */
    public function overview(OverviewService $overview): JsonResponse
    {
        $parks = Park::parks()->withoutTest()->with(['nodes', 'company'])->orderBy('company_id')->orderBy('id')->get();

        return response()->json(['data' => $overview->build($parks, withCompany: true)]);
    }

    /** GET /api/municipality/parks/{park}/timeline?alert_id= */
    public function timeline(Request $request, Park $park, TimelineService $timeline): JsonResponse
    {
        abort_if($park->is_test, 404);
        $alertId = $request->validate(['alert_id' => ['sometimes', 'integer']])['alert_id'] ?? null;

        return response()->json(['data' => $timeline->forPark($park, $alertId)]);
    }

    /** GET /api/municipality/alerts?status=open&company_id= */
    public function alerts(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'resolved', 'all'])],
            'company_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $status = $filters['status'] ?? 'open';

        $alerts = Alert::query()
            ->whereHas('park', fn ($p) => $p->parks()->withoutTest())
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($filters['company_id'] ?? null, fn ($q, $id) => $q->whereHas('park', fn ($p) => $p->where('company_id', $id)))
            ->with(['park.company', 'latestTask.assignee', 'detection'])
            ->byPriority()
            ->paginate($filters['per_page'] ?? 50);

        return AlertResource::collection($alerts);
    }

    /** GET /api/municipality/impact?days=30&company_id=: the impact figures plus a row per company. */
    public function impact(ImpactRequest $request, ImpactService $impact): JsonResponse
    {
        $parks = Park::parks()->withoutTest()->with(['company', ...Park::satelliteRelations()])
            ->when($request->validated('company_id'), fn ($q, $id) => $q->where('company_id', $id))
            ->orderBy('company_id')->orderBy('id')->get();
        $days = $request->days();

        $summary = $impact->summary($parks, $days);
        $summary['by_company'] = $parks->groupBy('company_id')->map(function ($companyParks) use ($impact, $days) {
            /** @var Company $company */
            $company = $companyParks->first()->company;
            $s = $impact->summary($companyParks, $days);

            return [
                'company' => ['id' => $company->id, 'name' => $company->name],
                'parks' => $companyParks->count(),
                'source_mix' => $s['source_mix'],
                'water' => $s['water'],
                'alerts' => $s['alerts']['total'],
                'alerts_urgent' => $s['alerts']['by_priority']['urgent'] ?? 0,
                'tasks' => $s['tasks'],
            ];
        })->values()->all();

        return response()->json(['data' => $summary]);
    }
}
