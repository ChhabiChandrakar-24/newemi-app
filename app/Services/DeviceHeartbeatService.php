<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DeviceHeartbeatService
{
    public function __construct(private readonly DeviceEventService $events) {}

    public function record(Device $device, array $data, ?string $ipAddress): Device
    {
        return DB::transaction(function () use ($device, $data, $ipAddress): Device {
            $device = Device::query()->lockForUpdate()->findOrFail($device->getKey());
            $wasOffline = $device->connectivity_status === 'offline';
            $oldManagement = $device->management_mode;
            $oldCompliance = $device->compliance_status;
            $oldSim = $device->sim_fingerprint;
            $newSim = isset($data['sim_fingerprint']) ? hash('sha256', $data['sim_fingerprint']) : $oldSim;
            $capabilities = collect(config('devices.capabilities'))
                ->mapWithKeys(fn (string $key): array => [$key => (bool) ($data['capabilities'][$key] ?? false)])
                ->all();

            $device->update([
                ...Arr::only($data, ['app_version', 'android_version', 'sdk_version', 'battery_level', 'battery_charging', 'network_type', 'management_mode', 'compliance_status', 'policy_version', 'sim_state', 'location_permission_state']),
                'management_status' => $data['management_status'] ?? ($device->management_status ?? 'MANAGED'),
                'sim_fingerprint' => $newSim,
                'capabilities' => $capabilities,
                'last_seen_at' => now(), 'last_heartbeat_at' => now(),
                'last_ip_address' => $ipAddress, 'connectivity_status' => 'online', 'connection_status' => 'ONLINE',
            ]);

            if ($wasOffline) {
                $this->events->record($device, 'heartbeat_restored', 'info', ['code' => 'heartbeat_received']);
            }
            if ($oldManagement !== $device->management_mode) {
                $lost = in_array($oldManagement, ['device_owner', 'fully_managed', 'dedicated'], true)
                    && in_array($device->management_mode, ['unmanaged', 'unknown'], true);
                $this->events->record($device, $lost ? 'management_removed' : 'management_changed', $lost ? 'critical' : 'high', ['previous' => $oldManagement, 'current' => $device->management_mode]);
            }
            if ($oldCompliance !== $device->compliance_status) {
                $this->events->record($device, 'compliance_changed', $device->compliance_status === 'compliant' ? 'info' : 'high', ['previous' => $oldCompliance, 'current' => $device->compliance_status]);
            }
            if ($device->compliance_status === 'non_compliant') {
                $this->events->record($device, 'device_non_compliant', 'high', ['code' => 'agent_reported'], true);
            }
            if ($oldSim && $newSim !== $oldSim && ($capabilities['can_detect_sim_change'] ?? false)) {
                $this->events->record($device, 'sim_changed', 'high', ['code' => 'fingerprint_changed']);
            }
            $minimumVersion = config('devices.minimum_agent_version');
            if ($minimumVersion && version_compare((string) $device->app_version, $minimumVersion, '<')) {
                $this->events->record($device, 'agent_outdated', 'warning', ['app_version' => $device->app_version, 'current' => $minimumVersion], true);
            }
            foreach ($data['security_events'] ?? [] as $reported) {
                $this->events->record($device, $reported['event_type'], $reported['severity'], $reported['metadata'] ?? null);
            }

            return $device->refresh();
        });
    }
}
