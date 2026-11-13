<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreParkClaimRequest;
use App\Http\Resources\ParkClaimResource;
use App\Models\Park;
use App\Models\ParkClaim;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ParkClaimController extends Controller
{
    public function index(Park $park): AnonymousResourceCollection
    {
        Gate::authorize('view', $park);

        return ParkClaimResource::collection($park->claims()->orderBy('id')->get());
    }

    public function store(StoreParkClaimRequest $request, Park $park, ActivityLogger $logger): JsonResponse
    {
        $claim = $park->claims()->create($request->validated() + ['created_by' => $request->user()->id]);
        $logger->log('park.claim_added', $park, ['after' => $request->safe()->only(['field', 'value', 'source_name'])]);

        return (new ParkClaimResource($claim))->response()->setStatusCode(201);
    }

    public function destroy(ParkClaim $claim, ActivityLogger $logger): JsonResponse
    {
        $park = $claim->park;
        Gate::authorize('update', $park);
        $before = $claim->only(['field', 'value', 'source_name']);
        $claim->delete();
        $logger->log('park.claim_removed', $park, ['before' => $before]);

        return response()->json(null, 204);
    }
}
