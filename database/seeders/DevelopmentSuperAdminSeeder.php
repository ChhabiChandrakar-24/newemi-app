<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class DevelopmentSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'development'])) {
            return;
        }

        $email = env('DEV_SUPER_ADMIN_EMAIL');
        $password = env('DEV_SUPER_ADMIN_PASSWORD');

        if (blank($email) || blank($password)) {
            return;
        }

        Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()]],
        )->validate();

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => env('DEV_SUPER_ADMIN_NAME', 'Development Super Admin'),
                'password' => Hash::make($password),
                'status' => 'active',
            ],
        );
        $user->syncRoles(['super-admin']);
    }
}
