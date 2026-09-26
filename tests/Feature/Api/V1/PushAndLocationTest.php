<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\DeviceCommandNotifierInterface;
use App\Contracts\PushProviderInterface;
use App\Jobs\SendDeviceCommandPushJob;
use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceApiCredential;
use App\Models\DeviceCommand;
use App\Models\DeviceLocation;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Services\DeviceCommandService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushAndLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_device_registers_rotates_and_revokes_encrypted_push_token(): void
    {
        [$device,$credential] = $this->device();
        $this->withToken($credential)->postJson('/api/v1/device/push-token', ['provider' => 'fcm', 'token' => str_repeat('a', 80), 'platform' => 'android', 'app_version' => '1.0'])->assertCreated()->assertJsonMissingPath('data.token');
        $this->assertNotSame(str_repeat('a', 80), DevicePushToken::first()->token_encrypted);
        $this->withToken($credential)->postJson('/api/v1/device/push-token', ['provider' => 'fcm', 'token' => str_repeat('b', 80), 'platform' => 'android'])->assertCreated();
        $this->assertSame(1, DevicePushToken::where('is_active', true)->count());
        $this->withToken($credential)->deleteJson('/api/v1/device/push-token')->assertNoContent();
        $this->assertSame(0, DevicePushToken::where('is_active', true)->count());
    }

    public function test_command_creation_invokes_notifier_without_changing_command_state(): void
    {
        [$device] = $this->device();
        $fake = new class implements DeviceCommandNotifierInterface
        {
            public int $count = 0;

            public function notify(DeviceCommand $command): void
            {
                $this->count++;
            }
        };
        $this->app->instance(DeviceCommandNotifierInterface::class, $fake);
        $actor = $this->user('admin');
        app(DeviceCommandService::class)->queue($device, 'show_warning', [], $actor);
        $this->assertSame(1, $fake->count);
        $this->assertSame('queued', DeviceCommand::first()->status);
    }

    public function test_invalid_push_token_is_deactivated_but_command_remains_queued(): void
    {
        [$device] = $this->device();
        $command = $this->command($device);
        $token = $device->pushTokens()->create(['provider' => 'fcm', 'token_hash' => hash('sha256', 'token'), 'token_encrypted' => Crypt::encryptString('token'), 'platform' => 'android', 'is_active' => true, 'registered_at' => now()]);
        $fake = new class implements PushProviderInterface
        {
            public function send(string $token, array $data, bool $highPriority): array
            {
                return ['status' => 'invalid_token', 'message_id' => null, 'failure_code' => 'unregistered', 'failure_message' => 'Invalid'];
            }
        };
        (new SendDeviceCommandPushJob($command->id))->handle($fake);
        $this->assertFalse($token->fresh()->is_active);
        $this->assertSame('queued', $command->fresh()->status);
        $this->assertDatabaseHas('device_push_attempts', ['status' => 'invalid_token']);
    }

    public function test_transient_push_failure_never_removes_authoritative_command(): void
    {
        [$device] = $this->device();
        $command = $this->command($device);
        $device->pushTokens()->create(['provider' => 'fcm', 'token_hash' => hash('sha256', 'token'), 'token_encrypted' => Crypt::encryptString('token'), 'platform' => 'android', 'is_active' => true, 'registered_at' => now()]);
        $fake = new class implements PushProviderInterface
        {
            public function send(string $token, array $data, bool $highPriority): array
            {
                return ['status' => 'failed', 'message_id' => null, 'failure_code' => 'transport_error', 'failure_message' => 'Offline'];
            }
        };
        try {
            (new SendDeviceCommandPushJob($command->id))->handle($fake);
        } catch (\RuntimeException) {
            // Expected: Laravel queue will apply bounded retry/backoff.
        }
        $this->assertSame('queued', $command->fresh()->status);
        $this->assertDatabaseHas('device_push_attempts', ['status' => 'failed', 'failure_code' => 'transport_error']);
    }

    public function test_location_requires_separate_consent_and_valid_coordinates(): void
    {
        [$device,$credential] = $this->device();
        $payload = ['latitude' => 28.6139, 'longitude' => 77.2090, 'accuracy' => 20, 'captured_at' => now()->toIso8601String(), 'source' => 'fused'];
        $this->withToken($credential)->postJson('/api/v1/device/location', $payload)->assertUnprocessable();
        Sanctum::actingAs($this->user('admin'));
        $this->putJson("/api/v1/devices/{$device->id}/location-settings", ['enabled' => true, 'requested_mode' => 'foreground_only', 'consent_confirmed' => true, 'remarks' => 'Signed location consent received'])->assertOk();
        $this->withToken($credential)->postJson('/api/v1/device/location', $payload)->assertCreated();
        $this->withToken($credential)->postJson('/api/v1/device/location', [...$payload, 'latitude' => 100])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.location_consent_enabled']);
    }

    public function test_location_rbac_withdrawal_and_retention_cleanup(): void
    {
        [$device,$credential] = $this->device(['location_tracking_enabled' => true, 'location_tracking_mode' => 'foreground_only', 'location_consent_given_at' => now()]);
        DeviceLocation::create(['device_id' => $device->id, 'latitude' => 1, 'longitude' => 1, 'captured_at' => now()->subDays(40), 'received_at' => now()->subDays(40), 'source' => 'fused', 'tracking_mode' => 'foreground_only']);
        Sanctum::actingAs($this->user('staff'));
        $this->getJson("/api/v1/devices/{$device->id}/location")->assertForbidden();
        Sanctum::actingAs($this->user('admin'));
        $this->getJson("/api/v1/devices/{$device->id}/location")->assertOk();
        $this->putJson("/api/v1/devices/{$device->id}/location-settings", ['enabled' => false, 'requested_mode' => 'disabled', 'consent_confirmed' => false, 'remarks' => 'Consent withdrawn'])->assertOk();
        $this->withToken($credential)->postJson('/api/v1/device/location', ['latitude' => 1, 'longitude' => 1, 'captured_at' => now()->toIso8601String(), 'source' => 'fused'])->assertUnprocessable();
        $this->artisan('devices:purge-old-locations')->assertSuccessful();
        $this->assertSame(0, DeviceLocation::count());
    }

    private function device(array $overrides = []): array
    {
        $customer = Customer::factory()->create(['consent_given' => true]);
        $device = Device::factory()->create(array_merge(['customer_id' => $customer->id, 'management_mode' => 'device_owner', 'enrollment_status' => 'enrolled', 'capabilities' => array_fill_keys(config('devices.capabilities'), true)], $overrides));
        $credential = 'credential-'.Str::random(50);
        DeviceApiCredential::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $credential), 'name' => 'test']);

        return [$device->load('customer'), $credential];
    }

    private function command(Device $device): DeviceCommand
    {
        return DeviceCommand::create(['command_uuid' => Str::uuid(), 'device_id' => $device->id, 'command_type' => 'show_warning', 'requested_control_status' => 'warning', 'status' => 'queued', 'priority' => 50, 'source' => 'manual', 'requested_at' => now(), 'available_at' => now(), 'expires_at' => now()->addHour()]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
