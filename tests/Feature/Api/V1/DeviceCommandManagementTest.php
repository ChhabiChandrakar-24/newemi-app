<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceApiCredential;
use App\Models\DeviceCommand;
use App\Models\EmiAccount;
use App\Models\EmiSchedule;
use App\Models\LockPolicy;
use App\Models\User;
use App\Services\AutomaticDeviceCommandService;
use App\Services\DeviceLockPolicyService;
use App\Services\PaymentDeviceUnlockService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceCommandManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_authorization_and_device_token_separation_are_enforced(): void
    {
        [$device, $token] = $this->enrolledDevice();
        $this->postJson("/api/v1/devices/{$device->id}/commands/warning")->assertUnauthorized();
        Sanctum::actingAs($this->user('auditor'));
        $this->postJson("/api/v1/devices/{$device->id}/commands/warning")->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/v1/devices/{$device->id}/commands/warning")->assertUnauthorized();
    }

    public function test_supported_manual_commands_are_queued_without_changing_actual_state(): void
    {
        [$device] = $this->enrolledDevice();
        Sanctum::actingAs($this->user('admin'));
        $response = $this->postJson("/api/v1/devices/{$device->id}/commands/full-lock", ['remarks' => 'Account overdue'])
            ->assertCreated()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.requested_control_status', 'full_lock');
        $device->refresh();
        $this->assertSame('active', $device->control_status);
        $this->assertSame('full_lock', $device->desired_control_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.command.full_lock.queued', 'entity_id' => (string) $response->json('data.id')]);
    }

    public function test_command_payload_uses_owning_company_branding(): void
    {
        [$device] = $this->enrolledDevice();
        $device->company->update(['name' => 'Alpha Finance', 'support_phone' => '+910000001111', 'support_email' => 'alpha@example.test']);
        Sanctum::actingAs($this->user('admin'));

        $payload = $this->postJson("/api/v1/devices/{$device->id}/commands/warning", ['message' => 'Demo warning'])
            ->assertCreated()->json('data.payload');

        $this->assertSame('Alpha Finance', $payload['company_name']);
        $this->assertSame('+910000001111', $payload['support_phone']);
        $this->assertSame('alpha@example.test', $payload['support_contact']);
        $this->assertStringNotContainsString('Beta', json_encode($payload));
    }

    public function test_full_lock_command_enforces_factory_reset_restriction_in_payload(): void
    {
        [$device] = $this->enrolledDevice();
        Sanctum::actingAs($this->user('admin'));

        $payload = $this->postJson("/api/v1/devices/{$device->id}/commands/full-lock", ['remarks' => 'Lock test'])
            ->assertCreated()->json('data.payload');

        $this->assertIsArray($payload['restrictions']);
        $this->assertTrue($payload['restrictions']['restrict_factory_reset']);
        $this->assertTrue($payload['restrictions']['restrict_safe_boot']);
    }

    public function test_fail_safe_business_and_capability_checks_reject_commands(): void
    {
        Sanctum::actingAs($this->user('admin'));
        [$unsupported] = $this->enrolledDevice(['capabilities' => array_fill_keys(config('devices.capabilities'), false)]);
        $this->postJson("/api/v1/devices/{$unsupported->id}/commands/full-lock")->assertUnprocessable();
        [$unmanaged] = $this->enrolledDevice(['management_mode' => 'unmanaged']);
        $this->postJson("/api/v1/devices/{$unmanaged->id}/commands/warning")->assertCreated();
        $this->postJson("/api/v1/devices/{$unmanaged->id}/commands/full-lock")->assertUnprocessable()
            ->assertJsonPath('errors.command.0.code', 'unsupported_management_mode');
        [$released] = $this->enrolledDevice(['enrollment_status' => 'released', 'released_at' => now()]);
        $this->postJson("/api/v1/devices/{$released->id}/commands/unlock")->assertUnprocessable();
        [$noConsent] = $this->enrolledDevice();
        $noConsent->customer->update(['consent_given' => false]);
        $this->postJson("/api/v1/devices/{$noConsent->id}/commands/warning")->assertUnprocessable();
    }

    public function test_active_duplicate_and_idempotency_key_do_not_create_duplicates(): void
    {
        [$device] = $this->enrolledDevice();
        Sanctum::actingAs($this->user('admin'));
        $first = $this->postJson("/api/v1/devices/{$device->id}/commands/full-lock", [], ['Idempotency-Key' => 'lock-1'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/devices/{$device->id}/commands/full-lock", [], ['Idempotency-Key' => 'lock-1'])->assertOk()->assertJsonPath('data.id', $first);
        $this->postJson("/api/v1/devices/{$device->id}/commands/full-lock")->assertOk()->assertJsonPath('data.id', $first);
        $this->assertSame(1, DeviceCommand::count());
    }

    public function test_polling_is_device_scoped_ordered_and_marks_dispatch(): void
    {
        [$device, $token] = $this->enrolledDevice();
        [$other, $otherToken] = $this->enrolledDevice();
        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/v1/devices/{$other->id}/commands/warning")->assertCreated();
        $expected = $this->postJson("/api/v1/devices/{$device->id}/commands/full-lock")->assertCreated()->json('data.id');
        $this->withToken($token)->getJson('/api/v1/device/commands/next')->assertOk()->assertJsonPath('data.id', $expected)->assertJsonPath('data.status', 'dispatched');
        $this->withToken($token)->getJson('/api/v1/device/commands/next')->assertOk()->assertJsonPath('data', null);
        $this->withToken($otherToken)->getJson('/api/v1/device/commands/next')->assertOk()->assertJsonPath('data.device_id', $other->id);
    }

    public function test_receipt_ack_and_success_result_update_actual_state_only_for_owner(): void
    {
        [$device, $token] = $this->enrolledDevice();
        [, $otherToken] = $this->enrolledDevice();
        Sanctum::actingAs($this->user('admin'));
        $id = $this->postJson("/api/v1/devices/{$device->id}/commands/full-lock")->json('data.id');
        $this->withToken($token)->getJson('/api/v1/device/commands/next')->assertOk();
        $this->withToken($otherToken)->postJson("/api/v1/device/commands/{$id}/received")->assertNotFound();
        $this->withToken($token)->postJson("/api/v1/device/commands/{$id}/received")->assertOk()->assertJsonPath('data.status', 'received');
        $this->withToken($token)->postJson("/api/v1/device/commands/{$id}/acknowledge")->assertOk()->assertJsonPath('data.status', 'acknowledged');
        $this->withToken($token)->postJson("/api/v1/device/commands/{$id}/result", ['applied' => true, 'resulting_control_status' => 'full_lock'])
            ->assertOk()->assertJsonPath('data.status', 'applied');
        $this->assertSame('full_lock', $device->fresh()->control_status);
        $this->assertDatabaseHas('device_events', ['device_id' => $device->id, 'event_type' => 'full_lock_applied']);
    }

    public function test_failed_result_does_not_change_actual_state_and_unlock_requires_success(): void
    {
        [$device, $token] = $this->enrolledDevice(['control_status' => 'full_lock']);
        Sanctum::actingAs($this->user('admin'));
        $id = $this->postJson("/api/v1/devices/{$device->id}/commands/unlock")->assertCreated()->json('data.id');
        $this->withToken($token)->getJson('/api/v1/device/commands/next');
        $this->withToken($token)->postJson("/api/v1/device/commands/{$id}/received");
        $this->withToken($token)->postJson("/api/v1/device/commands/{$id}/result", ['applied' => false, 'result_code' => 'policy_rejected'])->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame('full_lock', $device->fresh()->control_status);

        Sanctum::actingAs($this->user('admin'));
        $second = $this->postJson("/api/v1/devices/{$device->id}/commands/unlock")->assertCreated()->json('data.id');
        $this->withToken($token)->getJson('/api/v1/device/commands/next');
        $this->withToken($token)->postJson("/api/v1/device/commands/{$second}/received");
        $this->withToken($token)->postJson("/api/v1/device/commands/{$second}/result", ['applied' => true, 'resulting_control_status' => 'unlocked'])->assertOk();
        $this->assertSame('unlocked', $device->fresh()->control_status);
    }

    public function test_expired_commands_are_marked_and_never_delivered(): void
    {
        [$device, $token] = $this->enrolledDevice();
        DeviceCommand::create($this->commandData($device, ['expires_at' => now()->subMinute()]));
        $this->withToken($token)->getJson('/api/v1/device/commands/next')->assertOk()->assertJsonPath('data', null);
        $this->assertSame('expired', DeviceCommand::first()->status);
        $this->artisan('device-commands:expire')->assertSuccessful();
    }

    public function test_policy_thresholds_and_engine_are_idempotent(): void
    {
        [$device] = $this->enrolledDevice();
        $account = $device->emiAccount;
        $account->update(['auto_lock_enabled' => true, 'status' => 'overdue', 'overdue_amount' => '100.00']);
        EmiSchedule::factory()->create(['emi_account_id' => $account->id, 'due_date' => today()->subDays(20), 'grace_until' => today()->subDays(20), 'status' => 'overdue', 'overdue_amount' => '100.00']);
        $policy = LockPolicy::create(['name' => 'Default', 'is_default' => true, 'is_active' => true, 'warning_after_overdue_days' => 3, 'partial_lock_after_overdue_days' => 7, 'full_lock_after_overdue_days' => 15, 'unlock_on_payment_clearance' => true]);
        $evaluation = app(DeviceLockPolicyService::class)->evaluate($device, $account, $policy);
        $this->assertSame('full_lock', $evaluation['state']);
        $engine = app(AutomaticDeviceCommandService::class);
        $this->assertSame('queued', $engine->evaluate($device)['outcome']);
        $this->assertContains($engine->evaluate($device->fresh())['outcome'], ['unchanged', 'duplicate', 'waiting_interval']);
        $this->assertSame(1, DeviceCommand::where('command_type', 'full_lock')->count());
        $this->artisan('devices:evaluate-lock-policies')->assertSuccessful();
    }

    public function test_lock_policy_crud_and_command_history_are_permission_protected(): void
    {
        [$device] = $this->enrolledDevice();
        Sanctum::actingAs($this->user('auditor'));
        $this->getJson('/api/v1/lock-policies')->assertOk();
        $this->postJson('/api/v1/lock-policies', $this->policyData())->assertForbidden();
        Sanctum::actingAs($this->user('admin'));
        $policy = $this->postJson('/api/v1/lock-policies', $this->policyData())->assertCreated()->json('data.id');
        $this->putJson("/api/v1/devices/{$device->id}/lock-policy", ['lock_policy_id' => $policy])->assertOk()->assertJsonPath('data.lock_policy_id', $policy);
        $this->getJson("/api/v1/devices/{$device->id}/commands?status=queued")->assertOk();
    }

    public function test_payment_clearance_queues_unlock_but_does_not_change_actual_state(): void
    {
        [$device] = $this->enrolledDevice(['control_status' => 'full_lock']);
        LockPolicy::create([...$this->policyData(), 'is_active' => true]);
        $device->emiAccount->update(['overdue_amount' => '0.00']);
        $count = app(PaymentDeviceUnlockService::class)->queueIfCleared($device->emiAccount, $this->user('admin'));
        $this->assertSame(1, $count);
        $this->assertDatabaseHas('device_commands', ['device_id' => $device->id, 'command_type' => 'unlock', 'source' => 'payment', 'status' => 'queued']);
        $this->assertSame('full_lock', $device->fresh()->control_status);
        $this->assertSame('unlocked', $device->fresh()->desired_control_status);
    }

    public function test_eligible_command_can_be_cancelled_but_applied_command_cannot(): void
    {
        [$device, $token] = $this->enrolledDevice();
        Sanctum::actingAs($this->user('admin'));
        $queued = $this->postJson("/api/v1/devices/{$device->id}/commands/warning")->assertCreated()->json('data.id');
        $this->postJson("/api/v1/device-commands/{$queued}/cancel", ['remarks' => 'No longer required'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $applied = $this->postJson("/api/v1/devices/{$device->id}/commands/full-lock")->assertCreated()->json('data.id');
        $this->withToken($token)->getJson('/api/v1/device/commands/next');
        $this->withToken($token)->postJson("/api/v1/device/commands/{$applied}/received");
        $this->withToken($token)->postJson("/api/v1/device/commands/{$applied}/result", ['applied' => true, 'resulting_control_status' => 'full_lock']);
        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/v1/device-commands/{$applied}/cancel")->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.command.cancelled', 'entity_id' => (string) $queued]);
    }

    public function test_policy_sync_accepts_only_explicit_safe_policy_fields(): void
    {
        [$device] = $this->enrolledDevice();
        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/v1/devices/{$device->id}/commands/policy-sync", [
            'policy_version' => 'policy-2', 'allowed_packages' => ['com.example.payments'],
            'restrictions' => ['restrict_factory_reset' => true, 'arbitrary_setting' => true],
        ])->assertCreated()->assertJsonPath('data.payload.policy_version', 'policy-2')
            ->assertJsonPath('data.payload.restrictions.restrict_factory_reset', true)
            ->assertJsonMissingPath('data.payload.restrictions.arbitrary_setting');
        $this->postJson("/api/v1/devices/{$device->id}/commands/policy-sync", ['allowed_packages' => ['bad package']])
            ->assertUnprocessable()->assertJsonValidationErrors('allowed_packages.0');
    }

    private function enrolledDevice(array $overrides = []): array
    {
        $customer = Customer::factory()->create(['consent_given' => true, 'consent_given_at' => now()]);
        $account = EmiAccount::factory()->create(['customer_id' => $customer->id, 'status' => 'active']);
        $device = Device::factory()->create(array_merge(['customer_id' => $customer->id, 'emi_account_id' => $account->id,
            'management_mode' => 'fully_managed', 'enrollment_status' => 'enrolled', 'capabilities' => array_fill_keys(config('devices.capabilities'), true),
            'consent_verified_at' => now()], $overrides));
        $token = 'device-token-'.Str::random(48);
        DeviceApiCredential::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $token), 'name' => 'test']);

        return [$device->load(['customer', 'emiAccount']), $token];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function policyData(): array
    {
        return ['name' => 'Strict policy', 'is_default' => true, 'warning_after_overdue_days' => 3, 'partial_lock_after_overdue_days' => 7, 'full_lock_after_overdue_days' => 15, 'unlock_on_payment_clearance' => true, 'offline_behavior' => 'defer'];
    }

    private function commandData(Device $device, array $overrides = []): array
    {
        return array_merge(['command_uuid' => (string) Str::uuid(), 'device_id' => $device->id, 'command_type' => 'show_warning', 'requested_control_status' => 'warning', 'status' => 'queued', 'priority' => 50, 'source' => 'manual', 'requested_at' => now(), 'available_at' => now(), 'expires_at' => now()->addHour()], $overrides);
    }
}
