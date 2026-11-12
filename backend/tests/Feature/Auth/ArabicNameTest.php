<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sprint 6, B6: users.name_ar and the command that sets it for the demo accounts. */
class ArabicNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_sets_the_demo_names_and_is_idempotent(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Demo Admin']);
        $ahmed = User::factory()->worker()->create(['name' => 'Ahmed']);
        $khalid = User::factory()->worker()->create(['name' => 'Khalid']);
        $other = User::factory()->worker()->create(['name' => 'Someone Else']);

        $this->artisan('namaa:users:arabic-names')->expectsOutputToContain('3 user(s)')->assertSuccessful();
        $this->artisan('namaa:users:arabic-names')->expectsOutputToContain('0 user(s)')->assertSuccessful();

        $this->assertSame('مدير العرض', $admin->refresh()->name_ar);
        $this->assertSame('أحمد', $ahmed->refresh()->name_ar);
        $this->assertSame('خالد', $khalid->refresh()->name_ar);
        $this->assertNull($other->refresh()->name_ar);
    }

    public function test_the_user_resource_and_the_workers_list_expose_name_ar(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->worker()->create(['company_id' => $admin->company_id, 'name' => 'Ahmed', 'name_ar' => 'أحمد']);

        $this->asUser($admin)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.name_ar', null);
        $this->asUser($admin)->getJson('/api/admin/workers')->assertOk()->assertJsonPath('data.0.name_ar', 'أحمد');
    }
}
