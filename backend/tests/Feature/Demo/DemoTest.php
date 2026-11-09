<?php

namespace Tests\Feature\Demo;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DemoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $company->id, 'email' => 'admin@namaa.test']);
        User::factory()->worker()->create(['company_id' => $company->id, 'email' => 'worker1@namaa.test', 'name' => 'Ahmed']);
        User::factory()->municipality()->create(['email' => 'municipality@namaa.test']);
    }

    public function test_status_tells_the_dashboard_whether_demo_mode_is_on(): void
    {
        $this->getJson('/api/demo/status')->assertOk()->assertExactJson(['demo_mode' => false]);
        config(['namaa.demo_mode' => true]);
        $this->getJson('/api/demo/status')->assertOk()->assertExactJson(['demo_mode' => true]);
    }

    public function test_demo_login_is_404_when_demo_mode_is_off(): void
    {
        $this->postJson('/api/auth/demo-login', ['role' => 'admin'])->assertNotFound();
    }

    public function test_demo_login_gives_admin_and_worker_tokens_with_the_login_shape(): void
    {
        config(['namaa.demo_mode' => true]);

        $admin = $this->postJson('/api/auth/demo-login', ['role' => 'admin'])->assertOk()
            ->assertJsonPath('user.email', 'admin@namaa.test')->assertJsonPath('user.role', 'admin');
        $worker = $this->postJson('/api/auth/demo-login', ['role' => 'worker'])->assertOk()
            ->assertJsonPath('user.email', 'worker1@namaa.test')->assertJsonPath('user.name', 'Ahmed');

        // Same keys as POST /api/auth/login.
        $this->app['auth']->forgetGuards();
        $login = $this->withoutToken()->postJson('/api/auth/login', ['email' => 'admin@namaa.test', 'password' => 'password'])->assertOk();
        $this->assertSame(array_keys($login->json()), array_keys($admin->json()));
        $this->assertSame(array_keys($login->json('user')), array_keys($admin->json('user')));

        // The token works and has the `user` ability.
        $this->app['auth']->forgetGuards();
        $this->withToken($worker->json('token'))->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.role', 'worker');
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.demo_login', 'user_id' => $this->admin->id]);
        $this->assertSame(['user'], $this->admin->tokens()->latest('id')->first()->abilities);
    }

    public function test_municipality_unknown_and_email_are_rejected(): void
    {
        config(['namaa.demo_mode' => true]);

        $this->postJson('/api/auth/demo-login', ['role' => 'municipality'])->assertStatus(422)->assertJsonValidationErrors('role');
        $this->postJson('/api/auth/demo-login', ['role' => 'root'])->assertStatus(422);
        $this->postJson('/api/auth/demo-login', [])->assertStatus(422);
        // The request can never choose the account.
        $this->postJson('/api/auth/demo-login', ['role' => 'worker', 'email' => 'admin@namaa.test'])
            ->assertOk()->assertJsonPath('user.role', UserRole::Worker->value);
    }

    public function test_demo_login_is_throttled_to_10_per_minute(): void
    {
        config(['namaa.demo_mode' => true]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/demo-login', ['role' => 'worker'])->assertOk();
        }
        $this->postJson('/api/auth/demo-login', ['role' => 'worker'])->assertStatus(429);
    }

    public function test_scenario_endpoint_runs_only_in_demo_mode_for_admins(): void
    {
        $park = Park::factory()->simulated()->create(['company_id' => $this->admin->company_id]);
        $node = Node::factory()->simulated()->withPump()->create(['park_id' => $park->id, 'name' => 'Sim 2']);

        $this->asUser($this->admin)->postJson('/api/admin/demo/scenario', ['name' => 'leak'])->assertNotFound();

        config(['namaa.demo_mode' => true]);
        $this->asUser($this->admin)->postJson('/api/admin/demo/scenario', ['name' => 'leak'])
            ->assertOk()->assertJsonPath('data.scenario', 'leak')->assertJsonPath('data.node_id', $node->id);
        $this->assertTrue(Cache::get("namaa:sim:{$node->id}:leak"));
        $this->assertDatabaseHas('activity_logs', ['action' => 'demo.scenario', 'user_id' => $this->admin->id]);

        $this->asUser($this->admin)->postJson('/api/admin/demo/scenario', ['name' => 'flood'])->assertStatus(422);
        $this->asUser(User::where('email', 'worker1@namaa.test')->first())->postJson('/api/admin/demo/scenario', ['name' => 'leak'])->assertForbidden();
    }

    public function test_scenario_only_touches_the_admins_own_company(): void
    {
        config(['namaa.demo_mode' => true]);
        $otherPark = Park::factory()->simulated()->create();
        $otherNode = Node::factory()->simulated()->withPump()->create(['park_id' => $otherPark->id, 'name' => 'Sim 2']);

        // The admin's company has no "Sim 2": the scenario cannot reach the other company's node.
        $this->asUser($this->admin)->postJson('/api/admin/demo/scenario', ['name' => 'reset'])->assertOk()->assertJsonPath('data.parks', 0);
        $this->assertFalse(Cache::has("namaa:sim:{$otherNode->id}:moisture"));
    }
}
