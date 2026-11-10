<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImpactRequest;
use App\Models\Park;
use App\Services\ImpactService;
use Illuminate\Http\JsonResponse;

/** GET /api/admin/impact?days=30: the company's impact figures and a row per park. */
class ImpactController extends Controller
{
    public function __invoke(ImpactRequest $request, ImpactService $impact): JsonResponse
    {
        $parks = Park::forCompany($request->user()->company_id)
            ->unless($request->boolean('include_test'), fn ($q) => $q->withoutTest())
            ->with('latestNdvi')->orderBy('id')->get();

        return response()->json(['data' => $impact->summary($parks, $request->days())]);
    }
}
