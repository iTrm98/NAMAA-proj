<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The complete NAMAA demo: company and accounts, parks and nodes (with node
 * tokens), simulated history (readings, alerts, tasks, NDVI, activity logs).
 *
 * Built for an empty database. Always run it as: php artisan migrate:fresh --seed
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('email', DemoAccountsSeeder::ADMIN_EMAIL)->exists()) {
            throw new RuntimeException(
                'The demo data already exists. Rebuild it with: php artisan migrate:fresh --seed'
            );
        }

        $this->call([
            DemoAccountsSeeder::class,
            DemoParksSeeder::class,
            DemoHistorySeeder::class,
        ]);
    }
}
