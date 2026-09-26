<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\DeviceManagementSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceManagementSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_each_company_has_one_effective_profile_with_update_reset_and_history(): void
    {
        $alpha = Company::factory()->create();
        $beta = Company::factory()->create();
        $alphaAdmin = User::factory()->create(['company_id' => $alpha->id]);
        $betaAdmin = User::factory()->create(['company_id' => $beta->id]);
        $alphaAdmin->assignRole('admin');
        $betaAdmin->assignRole('admin');

        Sanctum::actingAs($alphaAdmin);
        $this->getJson('/api/v1/settings/device-management')->assertOk()->assertJsonPath('data.command_max_retries', 3);
        $this->putJson('/api/v1/settings/device-management', [
            'command_max_retries' => 7, 'warning_message' => 'Alpha warning',
            'factory_reset_restriction_enabled' => true, 'allowed_management_modes' => ['device_owner'],
        ])->assertOk()->assertJsonPath('data.command_max_retries', 7);
        $this->assertSame(1, DeviceManagementSetting::withoutGlobalScopes()->where('company_id', $alpha->id)->count());
        $this->postJson('/api/v1/settings/device-management/reset', ['keys' => ['command_max_retries']])
            ->assertOk()->assertJsonPath('data.command_max_retries', 3);
        $this->getJson('/api/v1/settings/device-management/history')->assertOk()->assertJsonCount(3, 'data');

        Sanctum::actingAs($betaAdmin);
        $this->getJson('/api/v1/settings/device-management')->assertOk()
            ->assertJsonPath('data.warning_message', 'Your EMI payment requires attention.')
            ->assertJsonPath('data.factory_reset_restriction_enabled', true);
        $this->assertSame(1, DeviceManagementSetting::withoutGlobalScopes()->where('company_id', $alpha->id)->count());
        $this->assertSame(1, DeviceManagementSetting::withoutGlobalScopes()->where('company_id', $beta->id)->count());
    }

    public function test_sensitive_settings_require_security_permission_and_profile_cannot_be_deleted(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo(['settings.view', 'settings.update']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/settings/device-management', ['heartbeat_interval_minutes' => 10])->assertOk();
        $this->putJson('/api/v1/settings/device-management', ['safe_boot_restriction_enabled' => true])->assertForbidden();
        $this->deleteJson('/api/v1/settings/device-management')->assertMethodNotAllowed();
    }
}
