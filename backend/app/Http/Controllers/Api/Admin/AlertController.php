<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AlertType;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AlertController extends Controller
{
    /**
     * GET /api/admin/alerts?status=open|resolved|all&park_id=&type=
     * Company alerts by priority (urgent, high, normal, low), then oldest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'resolved', 'all'])],
            'park_id' => ['sometimes', 'integer'],
            'type' => ['sometimes', Rule::enum(AlertType::class)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $status = $filters['status'] ?? 'open';

        $alerts = Alert::query()
            ->whereHas('park', fn ($q) => $q->where('company_id', $request->user()->company_id)
                ->unless($request->boolean('include_test'), fn ($p) => $p->withoutTest()))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($filters['park_id'] ?? null, fn ($q, $id) => $q->where('park_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->with(['park', 'latestTask.assignee', 'detection'])
            ->byPriority()
            ->paginate($filters['per_page'] ?? 50);

        return AlertResource::collection($alerts);
    }
}
