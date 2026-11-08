<?php

namespace Tests\Feature\Auth;

use App\Models\Node;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Probe routes for the role middleware.
        Route::middleware(['api', 'auth:sanctum', 'user.token', 'role:admin'])
            ->get('/api/_test/admin-only', fn () => ['ok' => true]);
        Route::middleware(['api', 'auth:sanctum', 'user.token', 'role:admin|municipality'])
            ->get('/api/_test/admin-or-municipality', fn () => ['ok' => true]);
    }

    public function test_login_returns_a_token_and_the_user(): void
    {
        $user = User::factory()->admin()->create(['email' => 'admin@namaa.test', 'password' => 'secret-pass']);

        $response = $this->postJson('/api/auth/login', ['email' => 'admin@namaa.test', 'password' => 'secret-pass']);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'company_id', 'company', 'locale']])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.company_id', $user->company_id);

        $this->assertSame(['user'], $user->tokens()->first()->abilities);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'user_id' => $user->id, 'company_id' => $user->company_id]);
    }

    public function test_wrong_password_gives_a_generic_422(): void
    {
        User::factory()->create(['email' => 'worker@namaa.test', 'password' => 'right-pass']);

        $wrongPassword = $this->postJson('/api/auth/login', ['email' => 'worker@namaa.test', 'password' => 'wrong']);
        $unknownEmail = $this->postJson('/api/auth/login', ['email' => 'nobody@namaa.test', 'password' => 'wrong']);

        $wrongPassword->assertStatus(422)->assertJsonValidationErrors('email');
        $unknownEmail->assertStatus(422);
        $this->assertSame($wrongPassword->json('errors.email'), $unknownEmail->json('errors.email'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        User::factory()->create(['email' => 'worker@namaa.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'worker@namaa.test', 'password' => 'wrong'])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => 'worker@namaa.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_me_returns_the_user_and_needs_a_token(): void
    {
        $user = User::factory()->municipality()->create();
        $token = $user->createToken('dashboard', ['user'])->plainTextToken;

        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'municipality')
            ->assertJsonPath('user.company_id', null)
            ->assertJsonPath('user.company', null);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('dashboard', ['user'])->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_node_token_is_rejected_on_user_routes(): void
    {
        $token = Node::factory()->create()->createToken('device', ['device'])->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertForbidden();
        $this->withToken($token)->getJson('/api/_test/admin-only')->assertForbidden();
    }

    public function test_user_token_without_the_user_ability_is_rejected(): void
    {
        $token = User::factory()->admin()->create()->createToken('other', ['device'])->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertForbidden();
    }

    public function test_role_middleware(): void
    {
        $admin = User::factory()->admin()->create()->createToken('d', ['user'])->plainTextToken;
        $worker = User::factory()->worker()->create()->createToken('d', ['user'])->plainTextToken;
        $municipality = User::factory()->municipality()->create()->createToken('d', ['user'])->plainTextToken;

        $this->withToken($admin)->getJson('/api/_test/admin-only')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($worker)->getJson('/api/_test/admin-only')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($municipality)->getJson('/api/_test/admin-only')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($municipality)->getJson('/api/_test/admin-or-municipality')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($worker)->getJson('/api/_test/admin-or-municipality')->assertForbidden();
    }
}
