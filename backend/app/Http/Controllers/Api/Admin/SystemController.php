<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Services\SystemHealth;
use App\Support\DeviceRejections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemController extends Controller
{
    /** GET /api/admin/system/status: the scheduler heartbeat (all admin pages show a banner when it stalls). */
    public function status(): JsonResponse
    {
        return response()->json(['data' => ['scheduler' => SystemHealth::schedulerStatus()]]);
    }

    /** GET /api/admin/device-rejections: the last rejected device requests of the company's nodes. */
    public function rejections(Request $request): JsonResponse
    {
        $nodes = Node::whereHas('park', fn ($q) => $q->where('company_id', $request->user()->company_id))
            ->orderBy('id')->get(['id', 'name']);

        return response()->json(['data' => $nodes
            ->map(fn (Node $n) => ['node_id' => $n->id, 'node_name' => $n->name, 'requests' => DeviceRejections::forNode($n->id)])
            ->filter(fn (array $row) => $row['requests'] !== [])->values()]);
    }
}
