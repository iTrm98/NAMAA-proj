<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The demo company and its four accounts. All share one password:
 * env DEMO_PASSWORD (default "password"), never stored in a tracked file.
 */
class DemoAccountsSeeder extends Seeder
{
    public const COMPANY_NAME = 'Green Makkah Maintenance Co. (demo)';

    public const ADMIN_EMAIL = 'admin@namaa.test';

    public const WORKER1_EMAIL = 'worker1@namaa.test';

    public const WORKER2_EMAIL = 'worker2@namaa.test';

    public const MUNICIPALITY_EMAIL = 'municipality@namaa.test';

    public function run(): void
    {
        $company = Company::create(['name' => self::COMPANY_NAME]);
        $password = (string) env('DEMO_PASSWORD', 'password');

        $accounts = [
            [self::ADMIN_EMAIL, 'Demo Admin', UserRole::Admin, $company->id, null],
            [self::WORKER1_EMAIL, 'Ahmed', UserRole::Worker, $company->id, '0550000001'],
            [self::WORKER2_EMAIL, 'Khalid', UserRole::Worker, $company->id, '0550000002'],
            [self::MUNICIPALITY_EMAIL, 'Makkah Municipality Viewer', UserRole::Municipality, null, null],
        ];

        foreach ($accounts as [$email, $name, $role, $companyId, $phone]) {
            User::factory()->create([
                'company_id' => $companyId,
                'role' => $role,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'locale' => 'ar',
                'password' => $password, // hashed by the model cast
            ]);
        }

        $this->command?->info(sprintf(
            'Demo accounts: %s, %s, %s, %s (password from DEMO_PASSWORD, default "password").',
            self::ADMIN_EMAIL, self::WORKER1_EMAIL, self::WORKER2_EMAIL, self::MUNICIPALITY_EMAIL,
        ));
    }
}
