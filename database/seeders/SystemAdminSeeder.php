<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SystemAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = \App\Models\User::query()->firstOrCreate(
            ['email' => 'admin@geetaforgetech.com'],
            [
                'name' => 'System Admin',
                'password' => \Illuminate\Support\Facades\Hash::make('Admin@12345'),
                'status' => 'active',
                'is_platform_admin' => true,
            ]
        );
        // If the user was already created but is_platform_admin is false, update it.
        if (! $user->is_platform_admin) {
            $user->update(['is_platform_admin' => true]);
        }
        $user->syncRoles(['super-admin']);
    }
}
