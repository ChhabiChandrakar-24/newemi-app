<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceApiCredential;
use App\Models\DeviceEnrollment;
use App\Models\EmiAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_api_requires_auth_and_auditor_is_view_only(): void
    {
        $this->getJson('/api/v1/devices')->assertUnauthorized();
        Sanctum::actingAs($this->userWithRole('auditor'));
        $this->getJson('/api/v1/devices')->assertOk();
        $this->postJson('/api/v1/devices', $this->registrationPayload())->assertForbidden();
    }

    public function test_valid_registration_generates_safe_identity_and_audit(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $response = $this->postJson('/api/v1/devices', $this->registrationPayload(['imei' => null]))
            ->assertCreated()->assertJsonPath('data.enrollment_status', 'pending')
            ->assertJsonPath('data.management_mode', 'unmanaged');
        $this->assertMatchesRegularExpression('/^DEV-\d{6,}$/', $response->json('data.device_code'));
        $this->assertTrue(Str::isUuid($response->json('data.internal_device_uuid')));
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.registered', 'entity_id' => (string) $response->json('data.id')]);
    }

    public function test_wrong_customer_account_relationship_and_closed_account_are_rejected(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $account = EmiAccount::factory()->create(['customer_id' => $other->id, 'status' => 'active']);
        $this->postJson('/api/v1/devices', $this->registrationPayload(['customer_id' => $customer->id, 'emi_account_id' => $account->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('emi_account_id');
        $account->update(['customer_id' => $customer->id, 'status' => 'closed']);
        $this->postJson('/api/v1/devices', $this->registrationPayload(['customer_id' => $customer->id, 'emi_account_id' => $account->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('emi_account_id');
    }

    public function test_list_detail_summary_filters_and_related_histories_work(): void
    {
        $device = Device::factory()->create(['connectivity_status' => 'offline', 'brand' => 'SearchBrand']);
        Sanctum::actingAs($this->userWithRole('auditor'));
        $this->getJson('/api/v1/devices?search=SearchBrand&offline_only=1&sort=device_code')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/devices/{$device->id}")->assertOk()->assertJsonPath('data.id', $device->id);
        $this->getJson("/api/v1/devices/{$device->id}/summary")->assertOk()->assertJsonPath('data.events.total', 0);
        $this->getJson("/api/v1/customers/{$device->customer_id}/devices")->assertJsonCount(1, 'data');
    }

    public function test_enrollment_requires_permission(): void
    {
        $device = Device::factory()->create();
        Sanctum::actingAs($this->userWithRole('staff'));
        $this->postJson("/api/v1/devices/{$device->id}/enrollment-token")->assertForbidden();
        Sanctum::actingAs($this->userWithRole('manager'));
        $this->postJson("/api/v1/devices/{$device->id}/enrollment-token")->assertCreated();
    }

    public function test_token_is_hashed_single_use_and_valid_claim_issues_scoped_credential(): void
    {
        [$device, $token] = $this->deviceAndToken();
        $enrollment = DeviceEnrollment::firstOrFail();
        $this->assertNotSame($token, $enrollment->token_hash);
        $this->assertSame(hash('sha256', $token), $enrollment->token_hash);

        $claim = $this->postJson('/api/v1/device-enrollment/claim', $this->claimPayload($token))
            ->assertCreated()->assertJsonPath('data.device.enrollment_status', 'enrolled');
        $credential = $claim->json('data.device_credential');
        $this->assertNotNull($credential);
        $this->assertSame(hash('sha256', $credential), DeviceApiCredential::firstOrFail()->token_hash);
        $this->assertDatabaseHas('device_events', ['device_id' => $device->id, 'event_type' => 'enrollment_completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.enrollment_completed', 'entity_id' => (string) $device->id]);
        $this->postJson('/api/v1/device-enrollment/claim', $this->claimPayload($token))->assertUnprocessable();
    }

    public function test_expired_revoked_and_wrong_tokens_are_rejected(): void
    {
        [, $token] = $this->deviceAndToken();
        DeviceEnrollment::first()->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/device-enrollment/claim', $this->claimPayload($token))->assertUnprocessable();
        $this->assertSame('expired', DeviceEnrollment::first()->status);
        $this->assertDatabaseHas('device_events', ['event_type' => 'enrollment_failure']);

        [$device2, $token2] = $this->deviceAndToken();
        Sanctum::actingAs($this->userWithRole('manager'));
        $this->deleteJson('/api/v1/device-enrollments/'.DeviceEnrollment::where('device_id', $device2->id)->value('id'))->assertOk();
        $this->postJson('/api/v1/device-enrollment/claim', $this->claimPayload($token2))->assertUnprocessable();
        $this->postJson('/api/v1/device-enrollment/claim', $this->claimPayload('wrong-token-value'))->assertUnprocessable();
    }

    public function test_device_credential_is_scoped_and_heartbeat_updates_safe_telemetry(): void
    {
        [$device, $token] = $this->deviceAndToken();
        $credential = $this->postJson('/api/v1/device-enrollment/claim', $this->claimPayload($token))->json('data.device_credential');

        Auth::forgetGuards();
        $this->withToken($credential)->getJson('/api/v1/devices')->assertUnauthorized();
        $this->withToken($credential)->postJson('/api/v1/device/heartbeat', $this->heartbeatPayload([
            'battery_level' => 75, 'location' => ['latitude' => 1, 'longitude' => 2],
        ]))->assertUnprocessable()->assertJsonValidationErrors('location');
        $device->update(['connectivity_status' => 'offline']);
        $this->withToken($credential)->postJson('/api/v1/device/heartbeat', $this->heartbeatPayload())
            ->assertOk()->assertJsonPath('data.connectivity_status', 'online')->assertJsonPath('data.battery_level', 75);
        $device->refresh();
        $this->assertNotNull($device->last_heartbeat_at);
        $this->assertSame(75, $device->battery_level);
        $this->assertDatabaseHas('device_events', ['device_id' => $device->id, 'event_type' => 'heartbeat_restored']);
    }

    public function test_management_loss_noncompliance_sim_change_and_reported_tamper_create_events(): void
    {
        [$device, $token] = $this->deviceAndToken();
        $credential = $this->postJson('/api/v1/device-enrollment/claim', $this->claimPayload($token, ['management_mode' => 'device_owner']))->json('data.device_credential');
        $this->withToken($credential)->postJson('/api/v1/device/heartbeat', $this->heartbeatPayload(['sim_fingerprint' => 'first-supported-marker']))->assertOk();
        $this->withToken($credential)->postJson('/api/v1/device/heartbeat', $this->heartbeatPayload([
            'management_mode' => 'unmanaged', 'compliance_status' => 'non_compliant',
            'sim_fingerprint' => 'second-supported-marker',
            'security_events' => [['event_type' => 'policy_removed', 'severity' => 'critical', 'metadata' => ['component' => 'device_policy']]],
        ]))->assertOk();
        foreach (['management_removed', 'device_non_compliant', 'sim_changed', 'policy_removed'] as $type) {
            $this->assertDatabaseHas('device_events', ['device_id' => $device->id, 'event_type' => $type]);
        }
    }

    public function test_event_listing_and_acknowledgement_are_permission_protected(): void
    {
        $device = Device::factory()->create();
        $event = $device->events()->create(['event_type' => 'possible_tamper', 'severity' => 'high', 'event_time' => now()]);
        Sanctum::actingAs($this->userWithRole('staff'));
        $this->getJson("/api/v1/devices/{$device->id}/events?severity=high&acknowledged=0")->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("/api/v1/device-events/{$event->id}/acknowledge")->assertForbidden();
        Sanctum::actingAs($this->userWithRole('manager'));
        $this->patchJson("/api/v1/device-events/{$event->id}/acknowledge")->assertOk();
        $this->assertNotNull($event->fresh()->acknowledged_at);
    }

    public function test_release_requires_permission_completion_and_revokes_all_secrets(): void
    {
        $account = EmiAccount::factory()->create(['status' => 'active']);
        [$device, $token] = $this->deviceAndToken($account);
        $credential = $this->postJson('/api/v1/device-enrollment/claim', $this->claimPayload($token))->json('data.device_credential');
        Sanctum::actingAs($this->userWithRole('staff'));
        $this->postJson("/api/v1/devices/{$device->id}/release")->assertForbidden();
        Sanctum::actingAs($this->userWithRole('manager'));
        $this->postJson("/api/v1/devices/{$device->id}/release")->assertUnprocessable();
        $account->update(['status' => 'completed']);
        $this->postJson("/api/v1/devices/{$device->id}/release")->assertOk()->assertJsonPath('data.enrollment_status', 'released');
        $this->assertNotNull(DeviceApiCredential::first()->revoked_at);
        $this->withToken($credential)->postJson('/api/v1/device/heartbeat', $this->heartbeatPayload())->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.released', 'entity_id' => (string) $device->id]);
    }

    private function deviceAndToken(?EmiAccount $account = null): array
    {
        $customer = $account?->customer ?? Customer::factory()->create(['consent_given' => true, 'consent_given_at' => now()]);
        if ($account) {
            $customer->update(['consent_given' => true, 'consent_given_at' => now()]);
        }
        $device = Device::factory()->create(['customer_id' => $customer->id, 'emi_account_id' => $account?->id]);
        Sanctum::actingAs($this->userWithRole('manager'));
        $token = $this->postJson("/api/v1/devices/{$device->id}/enrollment-token")->assertCreated()->json('enrollment_token');

        return [$device, $token];
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge(['customer_id' => Customer::factory()->create()->id, 'emi_account_id' => null, 'display_name' => 'Customer Phone', 'brand' => 'Example', 'model' => 'X1', 'manufacturer' => 'Example', 'invoice_number' => 'INV-DEVICE-1', 'imei' => null, 'serial_number' => null, 'notes' => null], $overrides);
    }

    private function claimPayload(string $token, array $overrides = []): array
    {
        return array_merge(['enrollment_token' => $token, 'installation_identifier' => 'installation-identifier-123456', 'brand' => 'Example', 'model' => 'X1', 'manufacturer' => 'Example', 'android_version' => '15', 'sdk_version' => 35, 'app_version' => '1.0.0', 'management_mode' => 'fully_managed', 'capabilities' => $this->capabilities()], $overrides);
    }

    private function heartbeatPayload(array $overrides = []): array
    {
        return array_merge(['app_version' => '1.0.0', 'android_version' => '15', 'sdk_version' => 35, 'battery_level' => 75, 'battery_charging' => true, 'network_type' => 'wifi', 'management_mode' => 'fully_managed', 'compliance_status' => 'compliant', 'policy_version' => '1', 'sim_state' => 'ready', 'capabilities' => $this->capabilities()], $overrides);
    }

    private function capabilities(): array
    {
        return array_fill_keys(config('devices.capabilities'), true);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
