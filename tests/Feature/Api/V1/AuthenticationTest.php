<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_user_can_login_view_profile_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        $user->assignRole('manager');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'test-client',
        ])->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.roles.0', 'manager');

        $token = $login->json('token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => hash('sha256', explode('|', $token, 2)[1])]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    public function test_me_and_logout_require_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_seeded_roles_have_expected_access_profiles(): void
    {
        $auditor = User::factory()->create();
        $auditor->assignRole('auditor');

        $this->assertTrue($auditor->can('reports.view'));
        $this->assertFalse($auditor->can('reports.export'));

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->assertTrue($admin->can('settings.update'));
    }
}
