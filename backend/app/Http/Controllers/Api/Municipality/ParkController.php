<?php

namespace App\Http\Controllers\Api\Municipality;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParkResource;
use App\Models\Park;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Read-only, across all companies. Same shape as the admin list, plus `company`. */
class ParkController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $parks = Park::with(['nodes', 'company'])
            ->withCount(['openAlerts as open_alerts_count'])
            ->orderBy('company_id')
            ->orderBy('id')
            ->get();

        return ParkResource::collection($parks);
    }
}
