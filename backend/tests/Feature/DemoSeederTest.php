<?php

namespace Tests\Feature;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\ParkStatus;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Node;
use App\Models\NdviReading;
use App\Models\Park;
use App\Models\Reading;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_builds_the_expected_demo(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(4, User::count());
        $this->assertNull(User::where('email', 'municipality@namaa.test')->value('company_id'));
        $this->assertSame(5, Park::count());
        // 4 sensors, Camera 1 and Sim Cam (Sprint 3.1); the second company's park is not seeded.
        $this->assertSame(7, Node::count());
        $this->assertSame('simulated', Node::where('name', 'Sim Cam')->sole()->source->value);
        $this->assertNull(Park::where('name_en', 'Al Masfalah Park')->first());
        // Data sources come from parks.json (Sprint 3.1).
        $this->assertSame('osm', Park::where('name_en', 'King Abdullah Park')->value('outline_source'));
        $this->assertSame('approximate', Park::where('name_en', 'Garden Aziziyah')->value('outline_source'));
        $this->assertSame(5, DB::table('personal_access_tokens')->where('abilities', '["device"]')->count());

        // Real parks: no fake history.
        $real = Park::where('source', 'real')->pluck('id');
        $this->assertCount(2, $real);
        $this->assertSame(0, Reading::whereIn('park_id', $real)->count());
        $this->assertSame(0, NdviReading::whereIn('park_id', $real)->count());
        $this->assertSame(2, Park::whereIn('id', $real)->where('status', ParkStatus::Offline)->count());

        // Simulated parks: 7 days x 144 readings each, no seeded NDVI (satellite data is fetched, never invented).
        $this->assertSame(3 * 1008, Reading::where('source', 'simulated')->count());
        $this->assertSame(0, NdviReading::count());

        $adel = Park::where('name_en', 'Al Adel Park')->firstOrFail();
        $this->assertSame(ParkStatus::Dry, $adel->status);
        $this->assertLessThan(25, $adel->sensorNode->last_moisture_pct);
        $openDry = Alert::open()->where('park_id', $adel->id)->where('type', AlertType::Dry)->firstOrFail();
        $task = Task::where('alert_id', $openDry->id)->firstOrFail();
        $this->assertSame(TaskStatus::Open, $task->status);
        $this->assertNull($task->assigned_to);

        foreach (['Alkhatateen Park' => [AlertType::Leak, 42], 'Alhilal Park' => [AlertType::Dry, 65]] as $name => [$type, $minutes]) {
            $park = Park::where('name_en', $name)->firstOrFail();
            $this->assertSame(ParkStatus::Ok, $park->status);
            $alert = Alert::where('park_id', $park->id)->where('type', $type)->firstOrFail();
            $this->assertSame(AlertStatus::Resolved, $alert->status);
            $done = Task::where('alert_id', $alert->id)->firstOrFail();
            $this->assertSame(TaskStatus::Done, $done->status);
            $this->assertTrue($done->verified);
            $this->assertSame($minutes, $done->time_to_resolve_min);
            $this->assertGreaterThan(0, (float) $done->water_used_l);
            $this->assertSame(5, DB::table('activity_logs')->where('subject_id', $done->id)->count());
        }
    }
}
