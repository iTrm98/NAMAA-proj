<?php

namespace App\Http\Controllers\Api\Worker;

use App\Enums\PumpReason;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\DoneTaskRequest;
use App\Http\Requests\Worker\TaskPumpRequest;
use App\Http\Resources\ParkResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\PumpService;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** /api/worker/tasks: only the tasks assigned to the signed-in worker (others: 404). */
class TaskController extends Controller
{
    private const WITH = ['park', 'alert', 'assignee', 'creator'];

    public function __construct(
        private readonly TaskService $tasks,
        private readonly PumpService $pump,
    ) {}

    /** Open and in-progress tasks (urgent first, oldest first), then the last 10 done or cancelled. */
    public function index(Request $request): JsonResponse
    {
        $mine = fn () => Task::forWorker($request->user()->id)->with(self::WITH);

        $active = $mine()->active()->byPriority()->get();
        $recent = $mine()->whereIn('status', [TaskStatus::Done, TaskStatus::Cancelled])
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(10)->get();

        return response()->json(['data' => TaskResource::collection($active->concat($recent))->resolve()]);
    }

    /** The task with its park (outline, thresholds, latest values, pump) and alert. */
    public function show(Task $task): JsonResponse
    {
        Gate::authorize('work', $task);

        return response()->json(['data' => $this->detail($task)]);
    }

    public function start(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('work', $task);
        $this->tasks->start($task, $request->user());

        return response()->json(['data' => $this->detail($task)]);
    }

    public function done(DoneTaskRequest $request, Task $task): JsonResponse
    {
        $this->tasks->done($task, $request->user(), $request->validated('note'), $request->file('photo'));

        return response()->json(['data' => $this->detail($task)]);
    }

    public function pump(TaskPumpRequest $request, Task $task): JsonResponse
    {
        $node = $task->park->pumpNode()->first();
        abort_if($node === null, 422, 'This park has no pump.');

        $this->pump->set($node, $request->boolean('on'), PumpReason::Manual, $request->user());

        return response()->json(['data' => $this->detail($task)]);
    }

    public function photo(Task $task): StreamedResponse
    {
        Gate::authorize('view', $task);
        abort_unless($task->done_photo_path && Storage::disk('local')->exists($task->done_photo_path), 404);

        return Storage::disk('local')->response($task->done_photo_path);
    }

    /** @return array<string, mixed> */
    private function detail(Task $task): array
    {
        $task->refresh()->load([...self::WITH, 'park.nodes']);
        $park = $task->park->loadCount(['openAlerts as open_alerts_count']);

        return (new TaskResource($task))->resolve() + [
            'park_detail' => (new ParkResource($park))->resolve(),
            'pump' => $this->pump->state($park),
        ];
    }
}
