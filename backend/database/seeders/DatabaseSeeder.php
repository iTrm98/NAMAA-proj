<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with the NAMAA demo.
     *
     * Run on an empty database: php artisan migrate:fresh --seed
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
