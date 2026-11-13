<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PumpReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PhotoRequest;
use App\Http\Requests\Admin\SaveOutlineRequest;
use App\Http\Requests\Admin\SwitchPumpRequest;
use App\Http\Requests\Admin\UpdateParkRequest;
use App\Http\Resources\ParkResource;
use App\Models\Park;
use App\Services\ActivityLogger;
use App\Services\ParkOutline;
use App\Services\PumpService;
use App\Services\ReadingSeries;
use App\Services\TimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;

class ParkController extends Controller
{
    public function __construct(private readonly PumpService $pump) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $parks = Park::forCompany($request->user()->company_id)
            // The demo stand and archived parks are not parks; include_test brings the stand back, include_archived the archive.
            ->when($request->boolean('include_test'),
                fn ($q) => $q->unless($request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at')),
                fn ($q) => $q->parks()->withoutTest())
            ->with(['nodes.latestPumpLog', ...Park::satelliteRelations()])
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
        if ($request->isV2()) {
            $warnings = $outline->saveGeometry($park, $request->validated(), $request->user());

            return $this->detail($park->refresh())->additional(['warnings' => $warnings]);
        }

        $outline->save($park, $request->ring(), $request->source(), $request->validated('reference'), $request->user());

        return $this->detail($park->refresh())->additional(['warnings' => $outline->warnings($park)]);
    }

    /** POST /api/admin/parks/{park}/photo: the cover photo, replacing the old one. */
    public function storePhoto(PhotoRequest $request, Park $park, ActivityLogger $logger): ParkResource
    {
        $file = $request->file('photo');
        $ext = $file->guessExtension() === 'jpeg' ? 'jpg' : ($file->guessExtension() ?: 'jpg');
        $path = "parks/{$park->id}/cover.$ext";
        $disk = Storage::disk('local');

        if ($park->cover_photo_path) {
            $disk->delete($park->cover_photo_path);
        }
        $disk->putFileAs("parks/{$park->id}", $file, "cover.$ext");
        $park->forceFill(['cover_photo_path' => $path, 'cover_photo_at' => now()])->save();
        $logger->log('park.photo_updated', $park, ['after' => ['cover_photo_path' => $path]]);

        return $this->detail($park);
    }

    /** DELETE /api/admin/parks/{park}/photo */
    public function deletePhoto(Park $park, ActivityLogger $logger): Response
    {
        Gate::authorize('update', $park);
        if ($park->cover_photo_path) {
            Storage::disk('local')->delete($park->cover_photo_path);
            $park->forceFill(['cover_photo_path' => null, 'cover_photo_at' => null])->save();
            $logger->log('park.photo_removed', $park);
        }

        return response()->noContent();
    }

    /** GET /api/admin/parks/{park}/photo: authenticated stream. */
    public function photo(Park $park): StreamedResponse
    {
        Gate::authorize('view', $park);
        abort_unless($park->cover_photo_path && Storage::disk('local')->exists($park->cover_photo_path), 404);

        return Storage::disk('local')->response($park->cover_photo_path);
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

    /** GET /api/admin/parks/{id}/timeline?alert_id=: the chain of the latest problem (api.md, Timeline). */
    public function timeline(Request $request, Park $park, TimelineService $timeline): JsonResponse
    {
        Gate::authorize('view', $park);
        $alertId = $request->validate(['alert_id' => ['sometimes', 'integer']])['alert_id'] ?? null;

        return response()->json(['data' => $timeline->forPark($park, $alertId)]);
    }

    private function detail(Park $park): ParkResource
    {
        $park->load(['nodes', ...Park::satelliteRelations(), 'openAlerts.detection', 'activeTasks.assignee', 'activeTasks.alert', 'outlineBy:id,name', 'areaBy:id,name', 'claims'])
            ->loadCount(['openAlerts as open_alerts_count']);

        return (new ParkResource($park))->detailed()->withPump($this->pump->state($park));
    }
}
