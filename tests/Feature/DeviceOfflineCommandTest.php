<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceOfflineCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_enrolled_device_becomes_offline_but_fresh_device_stays_online(): void
    {
        $stale = Device::factory()->create([
            'enrollment_status' => 'enrolled', 'connectivity_status' => 'online',
            'last_heartbeat_at' => now()->subMinutes(config('devices.heartbeat_timeout_minutes') + 1),
        ]);
        $fresh = Device::factory()->create([
            'enrollment_status' => 'enrolled', 'connectivity_status' => 'online',
            'last_heartbeat_at' => now()->subMinute(),
        ]);

        $this->artisan('devices:mark-offline')->expectsOutput('Marked 1 device(s) offline.')->assertSuccessful();
        $this->assertSame('offline', $stale->fresh()->connectivity_status);
        $this->assertSame('online', $fresh->fresh()->connectivity_status);
        $this->assertDatabaseHas('device_events', ['device_id' => $stale->id, 'event_type' => 'device_offline']);
    }

    public function test_offline_command_is_idempotent_and_does_not_duplicate_event(): void
    {
        $device = Device::factory()->create([
            'enrollment_status' => 'enrolled', 'connectivity_status' => 'online',
            'last_heartbeat_at' => now()->subHour(),
        ]);

        $this->artisan('devices:mark-offline')->assertSuccessful();
        $this->artisan('devices:mark-offline')->expectsOutput('Marked 0 device(s) offline.')->assertSuccessful();
        $this->assertSame(1, $device->events()->where('event_type', 'device_offline')->count());
    }
}
