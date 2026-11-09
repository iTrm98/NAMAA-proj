<?php

namespace Tests\Feature\Realtime;

use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/broadcasting/auth with the Bearer token. Tests run with the null
 * broadcaster (it approves everything), so this test swaps in a Pusher-protocol
 * broadcaster that points at a closed local port (nothing is ever sent; only
 * the channel signature is computed) and registers the channels again.
 */
class ChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.connections.channel-test' => [
            'driver' => 'pusher',
            'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test',
            'options' => ['host' => '127.0.0.1', 'port' => 9, 'scheme' => 'http', 'useTLS' => false],
        ]]);
        $manager = $this->app->make(BroadcastManager::class);
        $manager->setDefaultDriver('channel-test');
        require base_path('routes/channels.php');
    }

    private function auth(?User $user, string $channel)
    {
        $this->app['auth']->forgetGuards();
        $request = $user ? $this->asUser($user) : $this->withoutToken();

        return $request->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    }

    public function test_company_channel_is_for_admins_of_that_company(): void
    {
        $admin = User::factory()->admin()->create();
        $worker = User::factory()->worker()->create(['company_id' => $admin->company_id]);

        $this->auth($admin, 'private-company.'.$admin->company_id)->assertOk()->assertJsonStructure(['auth']);
        $this->auth($admin, 'private-company.'.($admin->company_id + 1))->assertForbidden();
        $this->auth($worker, 'private-company.'.$admin->company_id)->assertForbidden();
    }

    public function test_user_channel_is_for_that_user_only(): void
    {
        $worker = User::factory()->worker()->create();
        $other = User::factory()->worker()->create(['company_id' => $worker->company_id]);

        $this->auth($worker, 'private-user.'.$worker->id)->assertOk();
        $this->auth($worker, 'private-user.'.$other->id)->assertForbidden();
    }

    public function test_municipality_channel_is_for_municipality_users(): void
    {
        $this->auth(User::factory()->municipality()->create(), 'private-municipality')->assertOk();
        $this->auth(User::factory()->admin()->create(), 'private-municipality')->assertForbidden();
    }

    public function test_a_token_is_required(): void
    {
        $this->auth(null, 'private-municipality')->assertUnauthorized();
    }
}
