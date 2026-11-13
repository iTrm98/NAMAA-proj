<?php

namespace Tests\Feature\ParkTruth;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\MonitoringMode;
use App\Enums\ParkStatus;
use App\Enums\SiteType;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ParkModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sprint 6.2: --dry-run never writes. Every command is run with live data and the whole database is compared. */
class DryRunTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['namaa.demo_mode' => true]);
        $this->company = Company::factory()->create();
        User::factory()->admin()->create(['company_id' => $this->company->id]);
    }

    /** Row count and content hash of every table. @return array<string, string> */
    private function snapshot(): array
    {
        $snap = [];
        foreach (DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name") as $t) {
            $rows = DB::table($t->name)->get()->map(fn ($r) => json_encode($r))->sort()->values();
            $snap[$t->name] = $rows->count().':'.md5($rows->implode('|'));
        }

        return $snap;
    }

    private function assertUnchanged(callable $run): void
    {
        $before = $this->snapshot();
        $run();
        $this->assertSame($before, $this->snapshot(), 'a dry run must not change any table');
    }

    private function simPark(string $name, ?string $role = null, string $node = 'Sim 1'): Park
    {
        $park = Park::factory()->simulated()->create(['company_id' => $this->company->id, 'name_en' => $name, 'demo_role' => $role, 'status' => ParkStatus::Ok]);
        Node::factory()->simulated()->withPump()->create(['park_id' => $park->id, 'name' => $node]);
        Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::Dry, 'status' => AlertStatus::Open]);

        return $park;
    }

    public function test_import_dry_run_writes_nothing(): void
    {
        $this->simPark('Existing');
        $dir = sys_get_temp_dir().'/namaa-dry-'.uniqid();
        mkdir($dir, 0775, true);
        $square = fn (float $lng) => ['type' => 'Polygon', 'coordinates' => [[[$lng, 21.4], [$lng + 0.002, 21.4], [$lng + 0.002, 21.402], [$lng, 21.402], [$lng, 21.4]]]];
        $file = $dir.'/in.geojson';
        file_put_contents($file, json_encode(['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'properties' => ['osm_id' => 'way/1', 'name' => 'Brand New', 'claims' => [['field' => 'status', 'value' => 'open', 'source_name' => 'X']]], 'geometry' => $square(39.8)],
            ['type' => 'Feature', 'properties' => ['osm_id' => 'way/2', 'name' => 'Existing', 'district' => 'Somewhere'], 'geometry' => $square(39.9)],
        ]]));

        try {
            $this->assertUnchanged(fn () => $this->artisan('namaa:parks:import', ['file' => $file, '--company' => $this->company->id, '--dry-run' => true])->assertSuccessful());
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function test_archive_dry_run_writes_nothing(): void
    {
        $this->simPark('Plain');

        $this->assertUnchanged(fn () => $this->artisan('namaa:parks:archive', ['park' => 'Plain', '--dry-run' => true])->assertSuccessful());
    }

    public function test_set_mode_dry_run_writes_nothing(): void
    {
        $this->simPark('Plain');

        $this->assertUnchanged(fn () => $this->artisan('namaa:parks:set-mode', ['park' => 'Plain', 'mode' => 'satellite_only', '--dry-run' => true])->assertSuccessful());
    }

    public function test_demo_role_dry_run_writes_nothing(): void
    {
        $this->simPark('Holder', 'leak');
        $this->simPark('Taker', null, 'Sim 2');

        $this->assertUnchanged(fn () => $this->artisan('namaa:parks:demo-role', ['park' => 'Taker', 'step' => 'leak', '--dry-run' => true])->assertSuccessful());
    }

    public function test_stand_setup_dry_run_writes_nothing(): void
    {
        Park::factory()->create(['company_id' => $this->company->id, 'name_en' => 'Workshop (test bench)', 'is_test' => true, 'site_type' => SiteType::Park]);
        $king = Park::factory()->create(['company_id' => $this->company->id, 'name_en' => 'King Abdullah Park']);
        Node::factory()->withPump()->create(['park_id' => $king->id, 'name' => 'Model A']);

        $this->assertUnchanged(fn () => $this->artisan('namaa:stand:setup', ['--dry-run' => true, '--move-real-nodes' => true])->assertSuccessful());
    }

    public function test_archive_resolves_open_alerts_with_the_park_archived_note(): void
    {
        $park = $this->simPark('Plain');
        $ndvi = Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::NdviDrop, 'status' => AlertStatus::Open]);
        $other = $this->simPark('Other', null, 'Sim 2');
        $otherAlert = Alert::open()->where('park_id', $other->id)->sole();

        $this->artisan('namaa:parks:archive', ['park' => 'Plain'])->assertSuccessful();

        $this->assertSame(0, Alert::open()->where('park_id', $park->id)->count());
        $this->assertSame(AlertStatus::Resolved, $ndvi->refresh()->status);
        $logs = ActivityLog::where('action', 'alert.resolved')->get();
        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $this->assertSame(ParkModeService::ARCHIVE_NOTE, $log->diff['after']['reason'] ?? null);
        }
        $this->assertSame(AlertStatus::Open, $otherAlert->refresh()->status, 'other parks keep their alerts');
    }
}
