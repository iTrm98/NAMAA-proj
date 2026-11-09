<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerController extends Controller
{
    /** GET /api/admin/workers: the company's workers with their open task count (open + in progress). */
    public function index(Request $request): JsonResponse
    {
        $workers = User::query()
            ->where('company_id', $request->user()->company_id)
            ->where('role', UserRole::Worker)
            ->withCount([
                'assignedTasks as open_tasks' => fn ($q) => $q->whereIn('status', TaskStatus::active()),
                'assignedTasks as in_progress_tasks' => fn ($q) => $q->where('status', TaskStatus::InProgress),
            ])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $workers->map(fn (User $w) => [
            'id' => $w->id,
            'name' => $w->name,
            'phone' => $w->phone,
            'open_tasks' => (int) $w->open_tasks,
            'in_progress_tasks' => (int) $w->in_progress_tasks,
        ])->all()]);
    }
}
