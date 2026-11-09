<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTaskRequest;
use App\Http\Requests\Admin\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
{
    private const WITH = ['park', 'alert', 'assignee', 'creator'];

    public function __construct(private readonly TaskService $tasks) {}

    /**
     * GET /api/admin/tasks?status=&park_id=&sort=newest|priority
     * Newest first by default. sort=priority: open and in-progress first
     * (urgent, high, normal, low, oldest first), then the rest, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['all', 'overdue', ...array_column(TaskStatus::cases(), 'value')])],
            'park_id' => ['sometimes', 'integer'],
            'sort' => ['sometimes', Rule::in(['newest', 'priority'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $query = Task::forCompany($request->user()->company_id)
            ->when(! in_array($filters['status'] ?? 'all', ['all', 'overdue'], true), fn ($q) => $q->status($filters['status']))
            ->when(($filters['status'] ?? null) === 'overdue', fn ($q) => $q->overdue())
            ->when($filters['park_id'] ?? null, fn ($q, $id) => $q->where('park_id', $id))
            ->with(self::WITH);

        if (($filters['sort'] ?? 'newest') === 'priority') {
            $query->orderByRaw("case when status in ('open', 'in_progress') then 0 else 1 end")
                ->orderByRaw("case when status in ('open', 'in_progress') then (".TaskPriority::orderSql().') else 0 end')
                ->orderByRaw("case when status in ('open', 'in_progress') then created_at end asc")
                ->orderByDesc('created_at')->orderByDesc('id');
        } else {
            $query->latest()->orderByDesc('id');
        }

        return TaskResource::collection($query->paginate($filters['per_page'] ?? 50));
    }

    public function show(Task $task): TaskResource
    {
        Gate::authorize('update', $task);

        return new TaskResource($task->load(self::WITH));
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $task = $this->tasks->create($request->user(), $request->taskData());

        return (new TaskResource($task->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $this->tasks->update($task, $request->user(), $request->validated());

        return new TaskResource($task->refresh()->load(self::WITH));
    }

    /** The worker's photo, only through this authenticated route (never a public URL). */
    public function photo(Task $task): StreamedResponse
    {
        Gate::authorize('view', $task);
        abort_unless($task->done_photo_path && Storage::disk('local')->exists($task->done_photo_path), 404);

        return Storage::disk('local')->response($task->done_photo_path);
    }
}
