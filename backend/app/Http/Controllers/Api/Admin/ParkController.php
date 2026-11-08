<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateParkRequest;
use App\Http\Resources\ParkResource;
use App\Models\Park;
use App\Services\ActivityLogger;
use App\Services\ReadingSeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ParkController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $parks = Park::forCompany($request->user()->company_id)
            ->with('nodes')
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

    public function update(UpdateParkRequest $request, Park $park, ActivityLogger $logger): ParkResource
    {
        $before = $park->only(['moisture_min', 'moisture_max']);
        $park->fill($request->validated())->save();
        $after = $park->only(['moisture_min', 'moisture_max']);

        if ($before !== $after) {
            $logger->log('park.updated', $park, ['before' => $before, 'after' => $after]);
        }

        return $this->detail($park);
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
        $park->load(['nodes', 'openAlerts'])->loadCount(['openAlerts as open_alerts_count']);

        return (new ParkResource($park))->detailed();
    }
}
