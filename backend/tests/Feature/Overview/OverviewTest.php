<?php

namespace Tests\Feature\Overview;

use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Enums\TaskPriority;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sprint 6, B2: GET /api/admin/overview and /api/municipality/overview. */
class OverviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Park $real;

    private Park $sim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
        $this->real = Park::factory()->create(['company_id' => $this->company->id, 'source' => DataSource::Real, 'moisture_min' => 25, 'moisture_max' => 70]);
        $this->sim = Park::factory()->simulated()->create(['company_id' => $this->company->id]);
    }

    public function test_the_overview_has_kpis_the_right_now_strip_and_the_needs_attention_lists(): void
    {
        $node = Node::factory()->create(['park_id' => $this->real->id, 'last_moisture_pct' => 36.0]);
        Node::factory()->create(['park_id' => $this->sim->id, 'last_seen_at' => null, 'source' => DataSource::Real]); // awaiting
        $dry = Alert::factory()->create(['park_id' => $this->real->id, 'node_id' => $node->id, 'type' => AlertType::Dry, 'data' => ['moisture_pct' => 20.0]]);
        Alert::factory()->create(['park_id' => $this->sim->id, 'type' => AlertType::Leak]);
        Alert::factory()->create(['park_id' => $this->sim->id, 'type' => AlertType::Litter]);
        Alert::factory()->resolved()->create(['park_id' => $this->sim->id, 'type' => AlertType::Over]);

        $worker = User::factory()->worker()->create(['company_id' => $this->company->id, 'name' => 'Ahmed', 'name_ar' => 'أحمد']);
        Task::factory()->inProgress()->create([
            'park_id' => $this->real->id, 'alert_id' => $dry->id, 'assigned_to' => $worker->id,
            'priority' => TaskPriority::High, 'title' => 'Irrigate X', 'title_key' => 'taskTitle.dry', 'title_park_id' => $this->real->id,
        ]);
        Task::factory()->done()->create(['park_id' => $this->real->id, 'verified' => true, 'time_to_resolve_min' => 30, 'done_at' => now()->subDay()]);
        Task::factory()->done()->create(['park_id' => $this->real->id, 'verified' => true, 'time_to_resolve_min' => 50, 'done_at' => now()->subDays(2)]);
        Task::factory()->done()->create(['park_id' => $this->real->id, 'verified' => false, 'done_at' => now()->subDays(3)]);
        Task::factory()->done()->create(['park_id' => $this->real->id, 'verified' => null, 'done_at' => now()->subDays(3)]);
        Task::factory()->done()->create(['park_id' => $this->real->id, 'verified' => true, 'time_to_resolve_min' => 999, 'done_at' => now()->subDays(40)]); // outside 30 days

        $data = $this->asUser($this->admin)->getJson('/api/admin/overview')->assertOk()->json('data');

        $this->assertSame(['total' => 2, 'real' => 1, 'simulated' => 1], $data['kpis']['parks']);
        $this->assertSame(3, $data['kpis']['open_alerts']['total']);
        $this->assertEquals(['urgent' => 1, 'high' => 1, 'normal' => 1, 'low' => 0], $data['kpis']['open_alerts']['by_priority']);
        $this->assertSame(['days' => 30, 'done' => 4, 'verified' => 2, 'not_verified' => 1, 'pending' => 1, 'verified_pct' => 66.7], $data['kpis']['fixes']);
        $this->assertSame(40, $data['kpis']['median_fix_minutes']);
        $this->assertSame(
            ['parks_needing_water' => 1, 'open_leaks' => 1, 'open_litter' => 1, 'sensors_awaiting_installation' => 1],
            $data['right_now'],
        );

        $alerts = $data['needs_attention']['alerts'];
        $this->assertSame(['leak', 'dry', 'litter'], array_column($alerts, 'type')); // urgent, high, normal
        $this->assertSame('real', $alerts[1]['park']['source']);

        $task = $data['needs_attention']['tasks_in_progress'][0];
        $this->assertSame('taskTitle.dry', $task['title_key']);
        $this->assertSame('أحمد', $task['assigned_to']['name_ar']);
        $this->assertSame(47.5, $task['watering']['target_pct']);
        $this->assertEquals(36.0, $task['watering']['current_pct']);
        $this->assertEquals(20.0, $task['watering']['start_pct']);
        $this->assertSame(58, $task['watering']['progress_pct']); // (36 - 20) / (47.5 - 20)

        $this->assertSame('awaiting_installation', $data['needs_attention']['setup'][0]['type']);
    }

    public function test_an_empty_company_gets_zeroes_and_nulls(): void
    {
        $other = User::factory()->admin()->create();

        $data = $this->asUser($other)->getJson('/api/admin/overview')->assertOk()->json('data');

        $this->assertSame(0, $data['kpis']['parks']['total']);
        $this->assertNull($data['kpis']['fixes']['verified_pct']);
        $this->assertNull($data['kpis']['median_fix_minutes']);
        $this->assertSame([], $data['needs_attention']['alerts']);
    }

    public function test_other_companies_and_test_parks_are_not_counted(): void
    {
        Park::factory()->create(['is_test' => true, 'company_id' => $this->company->id]);
        $foreign = Park::factory()->create();
        Alert::factory()->create(['park_id' => $foreign->id, 'type' => AlertType::Leak]);

        $data = $this->asUser($this->admin)->getJson('/api/admin/overview')->json('data');

        $this->assertSame(2, $data['kpis']['parks']['total']);
        $this->assertSame(0, $data['kpis']['open_alerts']['total']);
    }

    public function test_it_needs_an_admin_token(): void
    {
        $this->getJson('/api/admin/overview')->assertUnauthorized();
        $this->asUser(User::factory()->worker()->create())->getJson('/api/admin/overview')->assertForbidden();
        $this->asUser(User::factory()->municipality()->create())->getJson('/api/admin/overview')->assertForbidden();
    }

    public function test_the_municipality_overview_spans_companies_and_is_read_only(): void
    {
        $foreign = Park::factory()->create();
        Alert::factory()->create(['park_id' => $foreign->id, 'type' => AlertType::Leak]);
        Alert::factory()->create(['park_id' => $this->real->id, 'type' => AlertType::Dry]);
        Park::factory()->create(['is_test' => true]);
        $viewer = User::factory()->municipality()->create();

        $data = $this->asUser($viewer)->getJson('/api/municipality/overview')->assertOk()->json('data');

        $this->assertSame(3, $data['kpis']['parks']['total']);
        $this->assertSame(2, $data['kpis']['open_alerts']['total']);
        $this->assertCount(2, $data['by_company']);
        $this->assertArrayHasKey('company', $data['needs_attention']['alerts'][0]['park']);

        $this->asUser($viewer)->postJson('/api/admin/tasks', [])->assertForbidden();
        $this->asUser($this->admin)->getJson('/api/municipality/overview')->assertForbidden();
    }

    public function test_a_placeholder_node_never_asks_for_an_installation_whatever_its_name(): void
    {
        Node::factory()->create(['park_id' => $this->sim->id, 'name' => 'Pergola board', 'last_seen_at' => null, 'source' => DataSource::Real, 'placeholder' => true]);
        Node::factory()->create(['park_id' => $this->sim->id, 'name' => 'Model A', 'last_seen_at' => null, 'source' => DataSource::Real]); // real board with a "placeholder" name

        $setup = $this->asUser($this->admin)->getJson('/api/admin/overview')->assertOk()->json('data.needs_attention.setup');

        $this->assertSame(['Model A'], array_column(array_column($setup, 'node'), 'name'));
    }

    public function test_the_municipality_overview_needs_a_token(): void
    {
        $this->getJson('/api/municipality/overview')->assertUnauthorized();
    }

    public function test_the_overview_does_not_query_per_row(): void
    {
        foreach (range(1, 6) as $i) {
            $park = Park::factory()->create(['company_id' => $this->company->id]);
            Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::Dry]);
        }

        \DB::enableQueryLog();
        $this->asUser($this->admin)->getJson('/api/admin/overview')->assertOk();
        $count = count(\DB::getQueryLog());

        $this->assertLessThan(20, $count);
    }
}
