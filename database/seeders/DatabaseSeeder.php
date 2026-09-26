<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(LockPolicySeeder::class);
        $this->call(SystemAdminSeeder::class);
        $this->call(PlatformPlansSeeder::class);
        $this->call(DevelopmentSuperAdminSeeder::class);
    }
}
