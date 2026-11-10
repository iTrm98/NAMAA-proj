<?php

namespace Tests\Feature\Impact;

use App\Enums\AlertType;
use App\Enums\TaskPriority;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sprint 3, F1/F3 and Sprint 3.1 C (water): the impact figures (GET /api/admin/impact). */
class ImpactTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Park $park;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
        // The older tests measure a few hours only; the data threshold has its own tests below.
        config(['namaa.impact.water_since' => null, 'namaa.impact.min_cycles' => 0, 'namaa.impact.min_measured_hours' => 0]);
        $this->park = Park::factory()->create(['company_id' => $this->company->id, 'auto_irrigation' => true]);
        $this->node = Node::factory()->withPump()->create(['park_id' => $this->park->id, 'expected_flow_l_min' => 2.0]);
    }

    /** One reading of the pump node $secondsAgo before now; $flow = pump on at that flow. */
    private function reading(int $secondsAgo, ?float $flow = null, ?Node $node = null): void
    {
        Reading::factory()->create([
            'node_id' => ($node ?? $this->node)->id,
            'pump_on' => $flow !== null,
            'flow' => $flow ?? 0.0,
            'created_at' => now()->subSeconds($secondsAgo),
        ]);
    }

    /** Readings every $every s from $fromAgo s ago to $toAgo s ago; pump on (2 L/min) for $pumpFrom..$pumpTo s ago. */
    private function series(int $fromAgo, int $toAgo, int $every, ?int $pumpFrom = null, ?int $pumpTo = null, ?Node $node = null): void
    {
        for ($t = $fromAgo; $t >= $toAgo; $t -= $every) {
            $pumping = $pumpFrom !== null && $t <= $pumpFrom && $t > $pumpTo;
            $this->reading($t, $pumping ? 2.0 : null, $node);
        }
    }

    private function impact(string $query = ''): array
    {
        return $this->asUser($this->admin)->getJson('/api/admin/impact'.$query)->assertOk()->json('data');
    }

    public function test_pump_time_is_compared_with_the_schedule_over_the_measured_time(): void
    {
        // 6 h of readings every 60 s; the pump runs for 5 minutes (5 readings at 2 L/min).
        $this->series(6 * 3600, 0, 60, pumpFrom: 3 * 3600, pumpTo: 3 * 3600 - 300);

        $water = $this->impact('?days=7')['water'];

        $this->assertSame('pump_time_vs_schedule', $water['method']);
        $this->assertSame(['sessions_per_day' => 2, 'minutes_per_session' => 20], $water['schedule']);
        $this->assertEqualsWithDelta(6.0, $water['measured_hours'], 0.05);
        $this->assertEqualsWithDelta(5.0, $water['pump_min'], 0.01);
        $this->assertEqualsWithDelta(10.0, $water['baseline_min'], 0.01, '6 h of a 2 x 20 min a day schedule');
        $this->assertEqualsWithDelta(50.0, $water['saved_pct'], 0.1);
        $this->assertEqualsWithDelta(10.0, $water['used_l'], 0.01, '5 min at 2 L/min');
        $this->assertEqualsWithDelta(20.0, $water['baseline_l'], 0.01, '10 min at the expected 2 L/min');
        $this->assertSame(1, $water['parks_measured']);
    }

    public function test_gaps_without_readings_are_left_out_of_both_sides(): void
    {
        // 1 h measured, then the laptop is off for 5 h (the pump was on in the last reading), then 1 h measured.
        $this->series(7 * 3600, 6 * 3600, 60);
        $this->reading(6 * 3600 - 30, 2.0); // pump on, followed by a 5 h silence: not pumping time
        $this->series(3600, 0, 60);

        $water = $this->impact('?days=7')['water'];

        $this->assertEqualsWithDelta(2.0, $water['measured_hours'], 0.05);
        $this->assertEqualsWithDelta(0.0, $water['pump_min'], 0.01);
        $this->assertEqualsWithDelta(2 / 24 * 40, $water['baseline_min'], 0.05, 'only the 2 measured hours of the schedule');
        $this->assertEqualsWithDelta(100.0, $water['saved_pct'], 0.1);
    }

    public function test_no_readings_means_not_measured(): void
    {
        $water = $this->impact()['water'];

        $this->assertSame(0, $water['parks_measured']);
        $this->assertNull($water['saved_pct']);
        $this->assertSame(0.0, (float) $water['measured_hours']);
        $this->assertSame('no_readings', $this->impact()['parks'][0]['water']['not_compared']);
    }

    public function test_only_parks_with_a_pump_and_automatic_irrigation_are_compared(): void
    {
        $manual = Park::factory()->create(['company_id' => $this->company->id, 'auto_irrigation' => false]);
        $manualNode = Node::factory()->withPump()->create(['park_id' => $manual->id, 'expected_flow_l_min' => 2.0]);
        $noPump = Park::factory()->create(['company_id' => $this->company->id, 'auto_irrigation' => false]);
        $sensor = Node::factory()->create(['park_id' => $noPump->id]);
        $this->series(3600, 0, 60);
        $this->series(3600, 0, 60, node: $manualNode);
        $this->series(3600, 0, 60, node: $sensor);

        $data = $this->impact('?days=7');

        $this->assertSame(1, $data['water']['parks_measured']);
        $rows = collect($data['parks'])->keyBy('id');
        $this->assertSame('manual_irrigation', $rows[$manual->id]['water']['not_compared']);
        $this->assertSame('no_pump', $rows[$noPump->id]['water']['not_compared']);
        $this->assertNull($rows[$this->park->id]['water']['not_compared']);
    }

    public function test_readings_before_water_since_and_a_per_park_schedule(): void
    {
        config(['namaa.impact.water_since' => now()->subHour()->utc()->toDateTimeString(),
            'namaa.water_baseline.parks' => [$this->park->id => ['sessions_per_day' => 1, 'minutes_per_session' => 24]]]);
        $this->series(3 * 3600, 0, 60);

        $water = $this->impact('?days=7')['water'];

        $this->assertEqualsWithDelta(1.0, $water['measured_hours'], 0.05, 'only the hour since water_since');
        $this->assertEqualsWithDelta(1.0, $water['baseline_min'], 0.02, '1 h of 1 x 24 min a day');
        $this->assertSame(['sessions_per_day' => 1, 'minutes_per_session' => 24], $this->impact('?days=7')['parks'][0]['water']['schedule']);
    }

    public function test_alerts_and_tasks_of_the_period(): void
    {
        Alert::factory()->create(['park_id' => $this->park->id, 'type' => AlertType::Leak]);
        Alert::factory()->create(['park_id' => $this->park->id, 'type' => AlertType::Litter]);
        Alert::factory()->create(['park_id' => $this->park->id, 'type' => AlertType::Dry, 'created_at' => now()->subDays(40)]);

        Task::factory()->done()->create(['park_id' => $this->park->id, 'verified' => true, 'time_to_resolve_min' => 30, 'water_used_l' => 12.5]);
        Task::factory()->done()->create(['park_id' => $this->park->id, 'verified' => true, 'time_to_resolve_min' => 50]);
        Task::factory()->done()->create(['park_id' => $this->park->id, 'verified' => true, 'time_to_resolve_min' => 90]);
        Task::factory()->done()->create(['park_id' => $this->park->id, 'verified' => false]);
        Task::factory()->done()->create(['park_id' => $this->park->id, 'verified' => null]); // still being checked
        Task::factory()->done()->create(['park_id' => $this->park->id, 'verified' => true, 'done_at' => now()->subDays(10), 'time_to_resolve_min' => 999]);
        Task::factory()->inProgress()->create(['park_id' => $this->park->id, 'priority' => TaskPriority::Urgent, 'escalated_at' => now()->subHour(), 'overdue_at' => now()->subMinutes(10)]);

        $week = $this->impact('?days=7');

        $this->assertSame(2, $week['alerts']['total']);
        $this->assertSame(1, $week['alerts']['by_priority']['urgent']);
        $this->assertSame(1, $week['alerts']['by_category']['water']);
        $this->assertSame(1, $week['alerts']['by_category']['cleanliness']);
        $this->assertSame(['leak' => 1, 'litter' => 1], $week['alerts']['by_type']);

        $this->assertSame(5, $week['tasks']['done']);
        $this->assertSame(3, $week['tasks']['verified']);
        $this->assertSame(1, $week['tasks']['not_verified']);
        $this->assertSame(1, $week['tasks']['pending']);
        $this->assertSame(0.75, $week['tasks']['verified_rate']);
        $this->assertSame(50, $week['tasks']['median_resolve_min']);
        $this->assertSame(1, $week['tasks']['escalated']);
        $this->assertSame(1, $week['tasks']['overdue']);
        $this->assertEquals(12.5, $week['tasks']['water_used_l']);

        $month = $this->impact('?days=30');
        $this->assertSame(6, $month['tasks']['done']);
        $this->assertSame(2, $month['alerts']['total'], 'the 40-day-old alert is outside 30 days too');
    }

    public function test_per_park_rows_and_source_mix(): void
    {
        $simulated = Park::factory()->simulated()->create(['company_id' => $this->company->id]);
        Alert::factory()->create(['park_id' => $simulated->id]);

        $data = $this->impact();

        $this->assertSame(['real' => 1, 'simulated' => 1], $data['source_mix']);
        $this->assertCount(2, $data['parks']);
        $row = collect($data['parks'])->firstWhere('id', $simulated->id);
        $this->assertSame('simulated', $row['source']);
        $this->assertSame(1, $row['alerts']);
        $this->assertArrayHasKey('water', $row);
        $this->assertArrayHasKey('verified_rate', $row['tasks']);
    }

    public function test_it_only_counts_the_admins_company(): void
    {
        $other = Park::factory()->create();
        Alert::factory()->create(['park_id' => $other->id]);

        $data = $this->impact();

        $this->assertSame(0, $data['alerts']['total']);
        $this->assertCount(1, $data['parks']);
    }

    public function test_validation_and_roles(): void
    {
        $this->asUser($this->admin)->getJson('/api/admin/impact?days=0')->assertUnprocessable();
        $this->asUser($this->admin)->getJson('/api/admin/impact?days=91')->assertUnprocessable();
        $worker = User::factory()->worker()->create(['company_id' => $this->company->id]);
        $this->asUser($worker)->getJson('/api/admin/impact')->assertForbidden();
    }

    // ---- Sprint 4, 0.2: no fake 100 % ----

    public function test_a_park_without_a_completed_cycle_or_24_hours_has_not_enough_data(): void
    {
        config(['namaa.impact.min_cycles' => 1, 'namaa.impact.min_measured_hours' => 24]);
        // 30 h measured but the pump never ran: the saving would be a fake 100 %.
        $this->series(30 * 3600, 0, 85);

        $data = $this->impact('?days=7');

        $this->assertFalse($data['water']['enough_data']);
        $this->assertNull($data['water']['saved_pct']);
        $this->assertNull($data['water']['saved_l']);
        $this->assertSame(1, $data['water']['parks_not_enough_data']);
        $this->assertSame(0, $data['water']['parks_measured']);
        $this->assertSame('not_enough_data', $data['parks'][0]['water']['not_compared']);
        $this->assertFalse($data['parks'][0]['water']['enough_data']);
    }

    public function test_a_cycle_alone_is_not_enough_without_24_measured_hours(): void
    {
        config(['namaa.impact.min_cycles' => 1, 'namaa.impact.min_measured_hours' => 24]);
        $this->series(6 * 3600, 0, 60, pumpFrom: 3 * 3600, pumpTo: 3 * 3600 - 300);

        $water = $this->impact('?days=7')['water'];

        $this->assertFalse($water['enough_data']);
        $this->assertNull($water['saved_pct']);
        $this->assertEqualsWithDelta(6.0, $water['measured_hours'], 0.05, 'the measured hours are still shown');
        $this->assertSame(1, $water['cycles']);
    }

    public function test_enough_data_gives_the_saving_and_totals_count_only_those_parks(): void
    {
        config(['namaa.impact.min_cycles' => 1, 'namaa.impact.min_measured_hours' => 24]);
        $this->series(26 * 3600, 0, 60, pumpFrom: 3 * 3600, pumpTo: 3 * 3600 - 600);
        $young = Park::factory()->create(['company_id' => $this->company->id, 'auto_irrigation' => true]);
        $youngNode = Node::factory()->withPump()->create(['park_id' => $young->id, 'expected_flow_l_min' => 2.0]);
        $this->series(2 * 3600, 0, 60, pumpFrom: 3600, pumpTo: 3000, node: $youngNode);

        $data = $this->impact('?days=7');

        $this->assertTrue($data['water']['enough_data']);
        $this->assertSame(1, $data['water']['parks_measured']);
        $this->assertSame(1, $data['water']['parks_not_enough_data']);
        $this->assertNotNull($data['water']['saved_pct']);
        $this->assertEqualsWithDelta(10.0, $data['water']['pump_min'], 0.01, 'the young park is not in the totals');
        $row = collect($data['parks'])->keyBy('id');
        $this->assertTrue($row[$this->park->id]['water']['enough_data']);
        $this->assertFalse($row[$young->id]['water']['enough_data']);
        $this->assertNull($row[$young->id]['water']['saved_pct']);
    }
}
