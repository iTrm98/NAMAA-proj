<?php

namespace Tests\Feature\Tasks;

use App\Enums\AlertType;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Park;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sprint 6, B4 (board column) and B5 (titles as key + park id). */
class TaskBoardAndTitleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Park $park;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->park = Park::factory()->create(['company_id' => $this->admin->company_id, 'name_en' => 'Al Adel Park']);
    }

    private function make(string $state, array $attributes = []): Task
    {
        $factory = Task::factory();
        if ($state !== 'open') {
            $factory = $factory->{$state}();
        }

        return $factory->create(['park_id' => $this->park->id] + $attributes);
    }

    public function test_every_task_has_its_board_column(): void
    {
        $this->make('open');
        $this->make('inProgress');
        $this->make('done', ['verified' => null]);
        $this->make('done', ['verified' => true]);
        $this->make('done', ['verified' => false]);
        $this->make('open', ['status' => TaskStatus::Cancelled]);

        $boards = collect($this->asUser($this->admin)->getJson('/api/admin/tasks?per_page=100')->assertOk()->json('data'))
            ->pluck('board')->countBy()->all();

        $this->assertEquals(['todo' => 1, 'in_progress' => 1, 'checking' => 1, 'closed' => 3], $boards);
    }

    public function test_the_board_filter_and_the_grouped_response(): void
    {
        $this->make('open');
        $this->make('open');
        $this->make('inProgress');
        $this->make('done', ['verified' => null]);
        $this->make('done', ['verified' => true]);
        $this->make('open', ['status' => TaskStatus::Cancelled]);
        Task::factory()->create(); // other company

        $this->asUser($this->admin)->getJson('/api/admin/tasks?board=closed')->assertOk()->assertJsonCount(2, 'data');

        $response = $this->asUser($this->admin)->getJson('/api/admin/tasks?grouped=1&per_column=1')->assertOk();
        $response->assertJsonPath('counts', ['todo' => 2, 'in_progress' => 1, 'checking' => 1, 'closed' => 2]);
        $this->assertCount(1, $response->json('data.todo')); // limited
        $this->assertSame('checking', $response->json('data.checking.0.board'));
    }

    public function test_the_board_validation_and_roles(): void
    {
        $this->getJson('/api/admin/tasks?grouped=1')->assertUnauthorized();
        $this->asUser($this->admin)->getJson('/api/admin/tasks?board=nope')->assertUnprocessable();
        $this->asUser(User::factory()->worker()->create())->getJson('/api/admin/tasks?grouped=1')->assertForbidden();
    }

    public function test_a_system_title_is_stored_as_key_and_park_with_english_fallback(): void
    {
        $alert = Alert::factory()->create(['park_id' => $this->park->id, 'type' => AlertType::Dry]);

        $response = $this->asUser($this->admin)->postJson('/api/admin/tasks', [
            'alert_id' => $alert->id, 'title_key' => 'taskTitle.dry',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Irrigate Al Adel Park')
            ->assertJsonPath('data.title_key', 'taskTitle.dry')
            ->assertJsonPath('data.title_park_id', $this->park->id)
            ->assertJsonPath('data.title_park.name_en', 'Al Adel Park')
            ->assertJsonPath('data.board', 'todo');

        $this->assertDatabaseHas('tasks', ['id' => $response->json('data.id'), 'title_key' => 'taskTitle.dry', 'title_park_id' => $this->park->id]);
    }

    public function test_a_free_text_title_has_no_key_and_editing_a_system_title_makes_it_free_text(): void
    {
        $free = $this->asUser($this->admin)->postJson('/api/admin/tasks', ['park_id' => $this->park->id, 'title' => 'Look at the fence'])
            ->assertCreated()->assertJsonPath('data.title_key', null)->assertJsonPath('data.title_park_id', null);
        $this->assertNull($free->json('data.title_park'));

        $keyed = $this->asUser($this->admin)->postJson('/api/admin/tasks', [
            'park_id' => $this->park->id, 'title' => 'Check Al Adel Park', 'title_key' => 'taskTitle.manual',
        ])->assertCreated()->assertJsonPath('data.title', 'Check Al Adel Park');

        $this->asUser($this->admin)->patchJson('/api/admin/tasks/'.$keyed->json('data.id'), ['note' => 'only a note'])
            ->assertOk()->assertJsonPath('data.title_key', 'taskTitle.manual');
        $this->asUser($this->admin)->patchJson('/api/admin/tasks/'.$keyed->json('data.id'), ['title' => 'Custom'])
            ->assertOk()->assertJsonPath('data.title', 'Custom')->assertJsonPath('data.title_key', null);
    }

    public function test_title_validation(): void
    {
        $this->asUser($this->admin)->postJson('/api/admin/tasks', ['park_id' => $this->park->id])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->asUser($this->admin)->postJson('/api/admin/tasks', ['park_id' => $this->park->id, 'title_key' => 'taskTitle.nope'])
            ->assertUnprocessable()->assertJsonValidationErrors('title_key');
        $other = Park::factory()->create();
        $this->asUser($this->admin)->postJson('/api/admin/tasks', ['park_id' => $other->id, 'title_key' => 'taskTitle.manual'])->assertUnprocessable();
    }

    public function test_the_title_key_command_maps_known_english_titles_only(): void
    {
        $known = $this->make('open', ['title' => 'Irrigate Al Adel Park']);
        $legacy = $this->make('open', ['title' => 'Fix leak at Al Adel Park']);
        $free = $this->make('open', ['title' => 'Look at the fence']);

        $this->artisan('namaa:tasks:title-keys', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($known->refresh()->title_key);

        $this->artisan('namaa:tasks:title-keys')->assertSuccessful();
        $this->artisan('namaa:tasks:title-keys')->assertSuccessful(); // idempotent

        $this->assertSame('taskTitle.dry', $known->refresh()->title_key);
        $this->assertSame($this->park->id, $known->title_park_id);
        $this->assertSame('taskTitle.leak', $legacy->refresh()->title_key);
        $this->assertSame('Irrigate Al Adel Park', $known->title); // title untouched
        $this->assertNull($free->refresh()->title_key);
    }
}
