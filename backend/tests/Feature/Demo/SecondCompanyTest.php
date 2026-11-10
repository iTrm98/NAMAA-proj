<?php

namespace Tests\Feature\Demo;

use App\Console\Commands\DemoSecondCompany;
use App\Enums\DataSource;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Sprint 3.1, E: the second demo company with a new simulated park of its own. */
class SecondCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Company $first;

    private Park $alhilal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->first = Company::factory()->create();
        $this->alhilal = Park::factory()->simulated()->create(['company_id' => $this->first->id, 'name_en' => 'Alhilal Park']);
    }

    public function test_it_creates_the_company_accounts_and_a_new_osm_park_without_moving_any_park(): void
    {
        $this->artisan('namaa:demo:second-company')->expectsOutputToContain('park Al Masfalah Park')->assertSuccessful();

        $company = Company::where('name', DemoSecondCompany::COMPANY_NAME)->sole();
        $admin = User::where('email', 'admin2@namaa.test')->sole();
        $worker = User::where('email', 'worker3@namaa.test')->sole();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertSame(UserRole::Worker, $worker->role);
        $this->assertSame($company->id, $admin->company_id);
        $this->assertSame($company->id, $worker->company_id);
        $this->assertTrue(Hash::check('password', $admin->password), 'DEMO_PASSWORD (default) like the other demo accounts');

        $this->assertSame($this->first->id, $this->alhilal->fresh()->company_id, 'no existing park is moved');

        $park = Park::where('company_id', $company->id)->sole();
        $this->assertSame('Al Masfalah Park', $park->name_en);
        $this->assertSame(DataSource::Simulated, $park->source);
        $this->assertTrue((bool) $park->auto_irrigation);
        $this->assertSame('osm', $park->outline_source instanceof \BackedEnum ? $park->outline_source->value : $park->outline_source);
        $this->assertStringContainsString('way/457991700', (string) $park->outline_reference);
        $this->assertEqualsWithDelta(9936, $park->area_m2, 60, 'area computed from the OSM outline');
        $this->assertSame($park->area_m2 * 4, (int) $park->baseline_l_per_day);

        $node = Node::where('park_id', $park->id)->sole();
        $this->assertSame('Sim 4', $node->name);
        $this->assertTrue((bool) $node->has_pump);
        $this->assertSame(DataSource::Simulated, $node->source);
        $this->assertSame(1, $node->tokens()->count(), 'a device token like the other nodes');

        foreach (['company.created', 'user.created', 'park.created', 'node.created'] as $action) {
            $this->assertDatabaseHas('activity_logs', ['action' => $action, 'company_id' => $company->id]);
        }

        // Each admin sees only their own parks; the municipality sees both companies.
        $this->asUser($admin)->getJson('/api/admin/parks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name_en', 'Al Masfalah Park');
        $firstAdmin = User::factory()->admin()->create(['company_id' => $this->first->id]);
        $this->asUser($firstAdmin)->getJson("/api/admin/parks/{$park->id}")->assertNotFound();
        $this->asUser($firstAdmin)->getJson('/api/admin/parks')->assertOk()->assertJsonCount(1, 'data');
        $muni = User::factory()->create(['role' => UserRole::Municipality, 'company_id' => null]);
        $this->asUser($muni)->getJson('/api/municipality/parks')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->artisan('namaa:demo:second-company')->assertSuccessful();
        $logs = ActivityLog::count();

        $this->artisan('namaa:demo:second-company')->expectsOutputToContain('Nothing to do')->assertSuccessful();

        $this->assertSame(1, Company::where('name', DemoSecondCompany::COMPANY_NAME)->count());
        $this->assertSame(1, User::where('email', 'admin2@namaa.test')->count());
        $this->assertSame(1, Park::where('name_en', 'Al Masfalah Park')->count());
        $this->assertSame(1, Node::where('name', 'Sim 4')->count());
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_reset_includes_the_new_park_and_keeps_auto_irrigation_on(): void
    {
        config(['namaa.demo_mode' => true]);
        $this->artisan('namaa:demo:second-company')->assertSuccessful();
        $park = Park::where('name_en', 'Al Masfalah Park')->sole();
        $park->update(['auto_irrigation' => false]);
        $sim4 = Node::where('name', 'Sim 4')->sole();
        Cache::forever("namaa:sim:{$sim4->id}:offline", true);

        $this->artisan('namaa:scenario reset --full')->assertSuccessful();

        $this->assertTrue((bool) $park->fresh()->auto_irrigation, 'reset turns automatic irrigation on for healthy parks');
        $this->assertNull(Cache::get("namaa:sim:{$sim4->id}:offline"), 'its scenario flags are cleared');
    }
}
