<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Models\Alert;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-off data cleanups (Sprint 3.1). Each one first works out exactly what it
 * would delete (the "plan", printed by --dry-run), then deletes that and logs
 * every deleted row in activity_logs. Real parks' readings and pump logs are
 * never touched.
 */
class DataCleanup
{
    public function __construct(private readonly ActivityLogger $logger) {}

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
        $disk = Storage::disk(config('namaa.camera.disk'));
        $files = 0;

        DB::transaction(function () use ($camera, $plan, $disk, &$files) {
            $reason = ['reason' => 'test_camera_data', 'node_id' => $camera->id];
            $this->deleteTasks($plan['tasks'], $reason);
            $this->deleteAlerts($plan['alerts'], $reason);

            foreach ($plan['detections'] as $frame) {
                if ($frame->photo_path && $disk->exists($frame->photo_path)) {
                    $disk->delete($frame->photo_path);
                    $files++;
                }
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
