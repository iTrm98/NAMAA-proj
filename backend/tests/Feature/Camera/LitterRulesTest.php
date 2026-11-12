<?php

namespace Tests\Feature\Camera;

use App\Enums\AlertCategory;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Events\AlertUpdated;
use App\Models\Alert;
use App\Models\Detection;
use App\Models\Task;
use App\Services\TaskVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Sprint 3, C2: litter and litter_repeated on camera frames, and litter task verification. */
class LitterRulesTest extends TestCase
{
    use CameraTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCamera();
    }

    private function litter(): ?Alert
    {
        return Alert::open()->whereIn('type', [AlertType::Litter, AlertType::LitterRepeated])->first();
    }

    public function test_litter_raises_after_two_frames_not_after_one(): void
    {
        $this->frames([2]);
        $this->assertNull($this->litter(), 'one frame is not enough');

        $this->frames([1]);
        $alert = $this->litter();
        $this->assertSame(AlertType::Litter, $alert->type);
        $this->assertSame(AlertCategory::Cleanliness, $alert->type->category());
        $this->assertSame(TaskPriority::Normal, $alert->priority);
        $this->assertSame($this->camera->id, $alert->node_id);
        $latest = Detection::latest('id')->first();
        $this->assertSame($latest->id, $alert->detection_id, 'the frame that raised it');
        $this->assertSame(['bottle'], $alert->data['labels']);
        $this->assertSame(2, $alert->data['frames']);
    }

    public function test_a_clean_frame_between_breaks_the_streak(): void
    {
        $this->frames([2, 0, 2]);
        $this->assertNull($this->litter());
    }

    public function test_litter_resolves_after_two_clean_frames(): void
    {
        $this->frames([1, 1]);
        $alert = $this->litter();

        $this->frames([0]);
        $this->assertSame(AlertStatus::Open, $alert->fresh()->status, 'one clean frame is not enough');

        $this->frames([0]);
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'alert.resolved', 'subject_id' => $alert->id]);
    }

    public function test_repeated_by_items_in_one_frame_upgrades_in_place(): void
    {
        Event::fake([AlertUpdated::class]);
        $this->frames([1, 1]);
        $alert = $this->litter();

        $this->frames([5]);

        $this->assertSame(1, Alert::count(), 'one alert per problem');
        $alert->refresh();
        $this->assertSame(AlertType::LitterRepeated, $alert->type);
        $this->assertSame(TaskPriority::High, $alert->priority);
        $this->assertSame('many_items', $alert->data['repeated_reason']);
        $this->assertSame(Detection::latest('id')->first()->id, $alert->detection_id, 'the frame that upgraded it');
        $this->assertDatabaseHas('activity_logs', ['action' => 'alert.escalated', 'subject_id' => $alert->id]);
        Event::assertDispatched(AlertUpdated::class, fn ($e) => $e->payload['alert']['type'] === 'litter_repeated');
    }

    public function test_four_items_are_not_repeated(): void
    {
        $this->frames([4, 4, 4]);
        $this->assertSame(AlertType::Litter, $this->litter()->type, 'litter that stays is not "repeated"');
    }

    public function test_repeated_when_litter_comes_back_three_times_within_the_window(): void
    {
        // Episodes: 1 (frames 1-2, raises), 2 (after a clean frame), 3 (after another clean frame).
        $this->frames([1, 1, 0, 1]);
        $this->assertSame(AlertType::Litter, $this->litter()->type);
        $this->frames([0, 1]);
        $this->assertSame(AlertType::LitterRepeated, $this->litter()->type);
        $this->assertSame('came_back', $this->litter()->data['repeated_reason']);
    }

    public function test_episodes_outside_the_window_do_not_count(): void
    {
        $this->frames([1, 1, 0, 1, 0, 0]); // 2 episodes, then resolved
        $this->assertNull($this->litter());

        $this->travel(2)->hours();
        $this->frames([1, 1]); // a new alert, only 1 episode within the hour
        $this->assertSame(AlertType::Litter, $this->litter()->type);
    }

    public function test_repeated_when_still_open_after_24_hours(): void
    {
        $this->frames([1, 1]);
        $alert = $this->litter();

        $this->travel(23)->hours();
        $this->artisan('namaa:capture')->assertSuccessful(); // checkAge runs every loop
        $this->assertSame(AlertType::Litter, $alert->fresh()->type);

        $this->travel(2)->hours();
        config(['namaa.camera.url' => '']); // even without new frames
        $this->artisan('namaa:capture')->assertSuccessful();
        $alert->refresh();
        $this->assertSame(AlertType::LitterRepeated, $alert->type);
        $this->assertSame('open_long', $alert->data['repeated_reason']);
    }

    public function test_demo_timings_repeat_after_three_minutes_open(): void
    {
        config(['namaa.demo_mode' => true]);
        $this->frames([1, 1]);
        $this->travel(181)->seconds();
        $this->frames([1]);
        $this->assertSame(AlertType::LitterRepeated, $this->litter()->type);
    }

    public function test_a_litter_task_is_verified_by_clean_frames_over_the_window(): void
    {
        $this->frames([2, 2]);
        $alert = $this->litter();
        $task = Task::factory()->create(['park_id' => $this->park->id, 'alert_id' => $alert->id, 'status' => TaskStatus::Done, 'done_at' => now()]);
        $verify = fn () => app(TaskVerifier::class)->verify($task->fresh());

        // Clean frames every 5 min (normal mode): window 10 min.
        foreach ([0, 0] as $count) {
            $this->litterQueue = [$count];
            $this->travel(5)->minutes();
            $this->artisan('namaa:capture')->assertSuccessful();
        }
        $this->assertNull($verify(), '5 min of clean frames');
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status, 'the rule resolved it after 2 clean frames');

        $this->travel(5)->minutes();
        $this->artisan('namaa:capture')->assertSuccessful();
        $this->assertTrue($verify());
        $this->assertTrue($task->fresh()->verified);
    }

    public function test_in_demo_mode_a_litter_task_is_verified_by_three_clean_frames_in_a_row(): void
    {
        config(['namaa.demo_mode' => true]);
        $this->frames([2, 2]);
        $task = Task::factory()->create(['park_id' => $this->park->id, 'alert_id' => $this->litter()->id, 'status' => TaskStatus::Done, 'done_at' => now()]);
        $verify = fn () => app(TaskVerifier::class)->verify($task->fresh());

        foreach ([0, 0, 3, 0, 0, 0] as $i => $count) {
            $this->litterQueue = [$count];
            $this->travel(10)->seconds();
            $this->artisan('namaa:capture')->assertSuccessful();
            // The frame with litter restarts the count: verified only on the 3rd clean one after it.
            $i === 5 ? $this->assertTrue($verify()) : $this->assertNull($verify(), "after frame $i");
        }
        $this->assertTrue($task->fresh()->verified);
    }

    public function test_in_demo_mode_two_clean_frames_are_not_enough(): void
    {
        config(['namaa.demo_mode' => true]);
        $this->frames([2, 2]);
        $task = Task::factory()->create(['park_id' => $this->park->id, 'alert_id' => $this->litter()->id, 'status' => TaskStatus::Done, 'done_at' => now()]);

        foreach ([0, 0] as $count) {
            $this->litterQueue = [$count];
            $this->travel(10)->seconds();
            $this->artisan('namaa:capture')->assertSuccessful();
        }
        $this->assertNull(app(TaskVerifier::class)->verify($task->fresh()));
    }

    public function test_in_normal_mode_three_quick_clean_frames_do_not_verify_before_the_window(): void
    {
        config(['namaa.demo_mode' => false]);
        $this->frames([2, 2]);
        $task = Task::factory()->create(['park_id' => $this->park->id, 'alert_id' => $this->litter()->id, 'status' => TaskStatus::Done, 'done_at' => now()]);

        foreach ([0, 0, 0] as $count) {
            $this->litterQueue = [$count];
            $this->travel(10)->seconds();
            $this->artisan('namaa:capture')->assertSuccessful();
        }
        $this->assertNull(app(TaskVerifier::class)->verify($task->fresh()));
    }

    public function test_litter_returning_breaks_the_verification_window(): void
    {
        $this->frames([2, 2]);
        $task = Task::factory()->create(['park_id' => $this->park->id, 'alert_id' => $this->litter()->id, 'status' => TaskStatus::Done, 'done_at' => now()]);

        foreach ([0, 3, 0] as $count) {
            $this->litterQueue = [$count];
            $this->travel(5)->minutes();
            $this->artisan('namaa:capture')->assertSuccessful();
        }
        $this->assertNull(app(TaskVerifier::class)->verify($task->fresh()), 'window restarted at the last clean frame');

        $this->travel(60)->minutes();
        $this->assertFalse(app(TaskVerifier::class)->verify($task->fresh()));
        $this->assertSame('condition_returned', $task->fresh()->verify_reason);
    }

    public function test_the_alert_photo_follows_the_newest_litter_frame(): void
    {
        $this->frames([4, 3]);
        $first = $this->litter();

        $this->frames([2]);
        $alert = $first->fresh();
        $latest = Detection::latest('id')->first();
        $this->assertSame($latest->id, $alert->detection_id, 'photo and count describe the same frame');
        $this->assertSame(2, $alert->data['count']);
        $this->assertSame($first->detection_id, $alert->data['first_detection_id']);
    }

    public function test_alerts_and_tasks_show_the_frame_that_raised_it(): void
    {
        $this->frames([1, 1]);
        $alert = $this->litter();
        $task = Task::factory()->create(['park_id' => $this->park->id, 'alert_id' => $alert->id]);

        $this->asUser($this->admin)->getJson('/api/admin/alerts')->assertOk()
            ->assertJsonPath('data.0.detection_id', $alert->detection_id)
            ->assertJsonPath('data.0.detection.count', 1)
            ->assertJsonPath('data.0.detection.image_url', "/admin/detections/{$alert->detection_id}/image")
            ->assertJsonPath('data.0.category', 'cleanliness');

        $this->asUser($this->admin)->getJson("/api/admin/tasks/{$task->id}")->assertOk()
            ->assertJsonPath('data.alert.detection.id', $alert->detection_id);
    }
}
