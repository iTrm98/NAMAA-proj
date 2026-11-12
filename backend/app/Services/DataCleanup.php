<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One-off data cleanups (Sprint 3.1). Each one first works out exactly what it
 * would delete (the "plan", printed by --dry-run), then deletes that and logs
 * every deleted row in activity_logs. Real parks' readings and pump logs are
 * never touched.
 */
class DataCleanup
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly CameraImages $images,
        private readonly TaskService $tasks,
        private readonly AlertService $alerts,
    ) {}

    /** Words that mark a task as made by a test or a demo run (title or note, any case). */
    public const TEST_TASK_PATTERN = '/regression|e2e|\btest/i';

    /**
     * False offline alerts from the scheduler gap bug (fixed Nov 10, 2026,
     * 02:56 UTC): offline alerts on simulated nodes, raised before $before,
     * whose node had a reading in the normal offline window before the
     * alert started (so it was not really offline).
     *
     * @return array{alerts: Collection<int, Alert>, tasks: Collection<int, Task>}
     */
    public function falseOfflinePlan(CarbonInterface $before): array
    {
        $window = (int) config('namaa.timings.offline_seconds.normal');

        $alerts = Alert::query()
            ->with('park:id,name_en')
            ->where('type', AlertType::Offline->value)
            ->where('created_at', '<', $before)
            ->whereHas('node', fn ($q) => $q->where('source', DataSource::Simulated->value))
            ->orderBy('id')
            ->get()
            ->filter(fn (Alert $alert) => DB::table('readings')
                ->where('node_id', $alert->node_id)
                ->whereBetween('created_at', [$alert->created_at->copy()->subSeconds($window), $alert->created_at])
                ->exists())
            ->values();

        return ['alerts' => $alerts, 'tasks' => $this->tasksOf($alerts)];
    }

    /**
     * Test frames on a camera: every detection that did not come from the capture
     * job (origin upload / scenario), the photo files, the camera's litter and
     * litter_repeated alerts and their tasks. Frames with origin "camera" are
     * kept (and so are alerts that point to one).
     *
     * @return array{detections: Collection<int, Detection>, kept: int, alerts: Collection<int, Alert>, tasks: Collection<int, Task>}
     */
    public function testCameraPlan(Node $camera): array
    {
        $detections = Detection::where('node_id', $camera->id)->orderBy('id')->get();
        [$real, $test] = $detections->partition(fn (Detection $d) => $d->origin === 'camera');
        $realIds = $real->pluck('id')->all();

        $alerts = Alert::query()
            ->with('park:id,name_en')
            ->where('node_id', $camera->id)
            ->whereIn('type', [AlertType::Litter->value, AlertType::LitterRepeated->value])
            ->orderBy('id')
            ->get()
            ->reject(fn (Alert $a) => $a->detection_id !== null && in_array($a->detection_id, $realIds, true))
            ->values();

        return ['detections' => $test->values(), 'kept' => $real->count(), 'alerts' => $alerts, 'tasks' => $this->tasksOf($alerts)];
    }

    /** @param array{alerts: Collection<int, Alert>, tasks: Collection<int, Task>} $plan */
    public function runFalseOffline(array $plan, CarbonInterface $before): void
    {
        DB::transaction(function () use ($plan, $before) {
            $reason = ['reason' => 'false_offline_scheduler_gap', 'cutoff' => $before->toIso8601ZuluString()];
            $this->deleteTasks($plan['tasks'], $reason);
            $this->deleteAlerts($plan['alerts'], $reason);
        });
    }

    /** @param array{detections: Collection<int, Detection>, kept: int, alerts: Collection<int, Alert>, tasks: Collection<int, Task>} $plan */
    public function runTestCamera(Node $camera, array $plan): int
    {
        $files = 0;

        DB::transaction(function () use ($camera, $plan, &$files) {
            $reason = ['reason' => 'test_camera_data', 'node_id' => $camera->id];
            $this->deleteTasks($plan['tasks'], $reason);
            $this->deleteAlerts($plan['alerts'], $reason);

            foreach ($plan['detections'] as $frame) {
                $files += $this->images->delete($frame);
                $this->logger->log('detection.deleted', $frame, ['before' => [
                    'id' => $frame->id, 'origin' => $frame->origin, 'count' => $frame->count,
                    'photo_path' => $frame->photo_path, 'captured_at' => $frame->captured_at?->toIso8601ZuluString(),
                ]] + $reason, companyId: $camera->park->company_id);
                $frame->delete();
            }

            // The node's cached litter count and "last seen" came from the test frames.
            $latest = Detection::where('node_id', $camera->id)->latest('captured_at')->first();
            $before = ['last_seen_at' => $camera->last_seen_at?->toIso8601ZuluString(), 'last_litter_count' => $camera->last_litter_count];
            $camera->forceFill([
                'last_seen_at' => $latest?->captured_at,
                'last_litter_count' => $latest?->count,
            ])->save();
            $this->logger->log('node.updated', $camera, ['before' => $before, 'after' => [
                'last_seen_at' => $camera->last_seen_at?->toIso8601ZuluString(), 'last_litter_count' => $camera->last_litter_count,
            ]] + $reason, companyId: $camera->park->company_id);
        });

        return $files;
    }

    /**
     * Demo leftovers on the simulated parks (never a real park or node):
     *  - active tasks whose title or note says regression / E2E / test: cancelled;
     *  - Sim Cam frames that did not come from the current data/samples photos
     *    (detections.sample empty or no longer matching a sample file), their
     *    photo files, litter alerts and those alerts' tasks: deleted;
     *  - open alerts of the cancelled test tasks: resolved.
     *
     * @return array{tasks: Collection<int, Task>, alerts_to_resolve: Collection<int, Alert>, detections: Collection<int, Detection>, delete_alerts: Collection<int, Alert>, delete_tasks: Collection<int, Task>}
     */
    public function demoLeftoversPlan(): array
    {
        $simParks = fn ($q) => $q->where('source', DataSource::Simulated->value);

        $cameras = Node::where('type', NodeType::Camera)->where('source', DataSource::Simulated->value)
            ->whereHas('park', $simParks)->pluck('id');

        $current = CameraFeed::currentSampleIds();
        $detections = Detection::whereIn('node_id', $cameras)->orderBy('id')->get()
            ->filter(fn (Detection $d) => $d->sample === null || ! in_array($d->sample, $current, true))->values();

        $litter = [AlertType::Litter->value, AlertType::LitterRepeated->value];
        $deleteAlerts = Alert::whereIn('type', $litter)->whereIn('node_id', $cameras)
            ->where(fn ($q) => $q->whereIn('detection_id', $detections->pluck('id'))->orWhereNull('detection_id'))
            ->orderBy('id')->get();
        $deleteTasks = $this->tasksOf($deleteAlerts);

        $tasks = Task::whereIn('status', TaskStatus::active())->whereHas('park', $simParks)
            ->whereNotIn('id', $deleteTasks->pluck('id'))->orderBy('id')->get()
            ->filter(fn (Task $t) => preg_match(self::TEST_TASK_PATTERN, ($t->title ?? '').' '.($t->note ?? '')) === 1)->values();

        $resolve = $tasks->isEmpty() ? collect() : Alert::open()->whereIn('id', $tasks->pluck('alert_id')->filter())
            ->whereNotIn('id', $deleteAlerts->pluck('id'))->with('park')->orderBy('id')->get();

        return ['tasks' => $tasks, 'alerts_to_resolve' => $resolve, 'detections' => $detections,
            'delete_alerts' => $deleteAlerts, 'delete_tasks' => $deleteTasks];
    }

    /**
     * @param  array{tasks: Collection<int, Task>, alerts_to_resolve: Collection<int, Alert>, detections: Collection<int, Detection>, delete_alerts: Collection<int, Alert>, delete_tasks: Collection<int, Task>}  $plan
     * @return int photo files deleted
     */
    public function runDemoLeftovers(array $plan): int
    {
        $files = 0;
        $reason = ['reason' => 'demo_leftovers'];

        DB::transaction(function () use ($plan, $reason, &$files) {
            foreach ($plan['tasks'] as $task) {
                $this->tasks->cancel($task->refresh(), null, 'demo_leftovers');
            }
            foreach ($plan['alerts_to_resolve'] as $alert) {
                $this->alerts->resolve($alert->refresh(), 'demo_leftovers');
            }

            $this->deleteTasks($plan['delete_tasks'], $reason);
            $this->deleteAlerts($plan['delete_alerts'], $reason);

            foreach ($plan['detections'] as $frame) {
                $files += $this->images->delete($frame);
                $this->logger->log('detection.deleted', $frame, ['before' => [
                    'id' => $frame->id, 'origin' => $frame->origin, 'count' => $frame->count, 'photo_path' => $frame->photo_path,
                    'captured_at' => $frame->captured_at?->toIso8601ZuluString(),
                ]] + $reason, companyId: $frame->node?->park?->company_id);
                $frame->delete();
            }

            // The cameras' cached litter count came from the deleted frames.
            foreach ($plan['detections']->pluck('node_id')->unique() as $nodeId) {
                $latest = Detection::where('node_id', $nodeId)->latest('captured_at')->first();
                Node::whereKey($nodeId)->update(['last_litter_count' => $latest?->count]);
            }
        });

        return $files;
    }

    /** The camera "Camera 1" (or the given id); null when it is not a camera. */
    public static function camera(?int $id): ?Node
    {
        $node = $id ? Node::find($id) : Node::where('name', 'Camera 1')->first();

        return $node && $node->type === NodeType::Camera ? $node : null;
    }

    /** @param Collection<int, Alert> $alerts @return Collection<int, Task> */
    private function tasksOf(Collection $alerts): Collection
    {
        return $alerts->isEmpty()
            ? collect()
            : Task::whereIn('alert_id', $alerts->pluck('id'))->orderBy('id')->get();
    }

    /** @param Collection<int, Task> $tasks @param array<string, mixed> $reason */
    private function deleteTasks(Collection $tasks, array $reason): void
    {
        foreach ($tasks as $task) {
            $this->logger->log('task.deleted', $task, ['before' => [
                'id' => $task->id, 'alert_id' => $task->alert_id, 'park_id' => $task->park_id,
                'title' => $task->title, 'status' => $task->status->value,
            ]] + $reason, companyId: $task->company_id);
            $task->delete();
        }
    }

    /** @param Collection<int, Alert> $alerts @param array<string, mixed> $reason */
    private function deleteAlerts(Collection $alerts, array $reason): void
    {
        foreach ($alerts as $alert) {
            $this->logger->log('alert.deleted', $alert, ['before' => [
                'id' => $alert->id, 'type' => $alert->type->value, 'park_id' => $alert->park_id, 'node_id' => $alert->node_id,
                'status' => $alert->status->value, 'created_at' => $alert->created_at?->toIso8601ZuluString(),
                'resolved_at' => $alert->resolved_at?->toIso8601ZuluString(),
            ]] + $reason, companyId: $alert->park?->company_id);
            $alert->delete();
        }
    }
}
