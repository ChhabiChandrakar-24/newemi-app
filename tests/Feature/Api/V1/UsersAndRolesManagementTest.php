<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UsersAndRolesManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->getJson('/api/v1/roles')->assertUnauthorized();
        $this->getJson('/api/v1/permissions')->assertUnauthorized();
    }

    public function test_unauthorized_role_receives_forbidden(): void
    {
        $staff = $this->userWithRole('staff');
        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_super_admin_can_list_search_filter_sort_and_manage_users(): void
    {
        Sanctum::actingAs($this->userWithRole('super-admin'));

        $create = $this->postJson('/api/v1/users', $this->userPayload())
            ->assertCreated()
            ->assertJsonPath('data.email', 'manager@example.com')
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonMissingPath('data.password');

        $userId = $create->json('data.id');

        $this->getJson('/api/v1/users?search=manager%40example.com&status=active&role=manager&sort=name&direction=asc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $userId)
            ->assertJsonStructure(['data', 'links', 'meta']);

        $this->getJson("/api/v1/users/{$userId}")->assertOk();

        $this->putJson("/api/v1/users/{$userId}", $this->userPayload([
            'name' => 'Updated Manager',
            'email' => 'updated.manager@example.com',
            'password' => null,
            'password_confirmation' => null,
        ]))->assertOk()->assertJsonPath('data.name', 'Updated Manager');

        $this->patchJson("/api/v1/users/{$userId}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->deleteJson("/api/v1/users/{$userId}")->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $userId]);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        Sanctum::actingAs($this->userWithRole('super-admin'));
        User::factory()->create(['email' => 'manager@example.com']);

        $this->postJson('/api/v1/users', $this->userPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_self_deactivation_is_rejected(): void
    {
        $admin = $this->userWithRole('admin');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/users/{$admin->id}/status", ['status' => 'inactive'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->putJson("/api/v1/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'mobile_number' => $admin->mobile_number,
            'password' => null,
            'password_confirmation' => null,
            'status' => 'inactive',
            'role' => 'admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_final_super_admin_cannot_be_deleted(): void
    {
        $superAdmin = $this->userWithRole('super-admin');
        $admin = $this->userWithRole('admin');
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/users/{$superAdmin->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');
    }

    public function test_auditor_can_list_but_cannot_mutate_users(): void
    {
        $auditor = $this->userWithRole('auditor');
        Sanctum::actingAs($auditor);

        $this->getJson('/api/v1/users')->assertOk();
        $this->postJson('/api/v1/users', $this->userPayload())->assertForbidden();
        $this->putJson("/api/v1/users/{$auditor->id}", $this->userPayload())->assertForbidden();
        $this->deleteJson("/api/v1/users/{$auditor->id}")->assertForbidden();
    }

    public function test_super_admin_can_list_create_update_and_delete_custom_roles(): void
    {
        Sanctum::actingAs($this->userWithRole('super-admin'));

        $this->getJson('/api/v1/roles')->assertOk()->assertJsonFragment(['name' => 'auditor']);
        $this->getJson('/api/v1/permissions')->assertOk()->assertJsonFragment(['users.create']);

        $create = $this->postJson('/api/v1/roles', [
            'name' => 'collections-agent',
            'permissions' => ['customers.view', 'payments.view', 'payments.create'],
        ])->assertCreated()->assertJsonPath('data.name', 'collections-agent');

        $roleId = $create->json('data.id');
        $this->getJson("/api/v1/roles/{$roleId}")->assertOk();

        $this->putJson("/api/v1/roles/{$roleId}", [
            'name' => 'collections-lead',
            'permissions' => ['customers.view', 'payments.view', 'reports.view'],
        ])->assertOk()->assertJsonFragment(['reports.view']);

        $this->deleteJson("/api/v1/roles/{$roleId}")->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
    }

    public function test_system_role_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->userWithRole('super-admin'));
        $role = Role::findByName('auditor', 'web');

        $this->deleteJson("/api/v1/roles/{$role->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'renamed-auditor',
            'permissions' => ['users.view'],
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_user_create_and_update_are_audited_without_passwords(): void
    {
        $actor = $this->userWithRole('super-admin');
        Sanctum::actingAs($actor);

        $create = $this->postJson('/api/v1/users', $this->userPayload())->assertCreated();
        $userId = $create->json('data.id');
        $this->putJson("/api/v1/users/{$userId}", $this->userPayload(['name' => 'Audited Update']))->assertOk();

        $logs = AuditLog::query()->where('entity_id', (string) $userId)->orderBy('id')->get();
        $this->assertSame(['user.created', 'user.updated'], $logs->pluck('action')->all());
        $this->assertSame($actor->id, $logs->first()->actor_user_id);
        $this->assertArrayNotHasKey('password', $logs->first()->new_values);
        $this->assertNotNull($logs->first()->ip_address);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function userPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Manager User',
            'email' => 'manager@example.com',
            'mobile_number' => '+919876543210',
            'password' => 'Strong!Pass123',
            'password_confirmation' => 'Strong!Pass123',
            'status' => 'active',
            'role' => 'manager',
        ], $overrides);
    }
}
