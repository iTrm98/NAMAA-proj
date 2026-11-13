<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Park;
use App\Services\OverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/admin/overview (api.md, Overview): the company's dashboard in one request. */
class OverviewController extends Controller
{
    public function __invoke(Request $request, OverviewService $overview): JsonResponse
    {
        $parks = Park::forCompany($request->user()->company_id)->parks()->withoutTest()->with('nodes')->orderBy('id')->get();

        $data = $overview->build($parks) + ['demo_stand' => $overview->demoStand($request->user()->company_id)];

        return response()->json(['data' => $data]);
    }
}
