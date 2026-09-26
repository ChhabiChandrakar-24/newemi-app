<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeviceHeartbeatRequest;
use App\Http\Resources\Api\V1\DeviceResource;
use App\Services\DeviceHeartbeatService;

class DeviceAgentController extends Controller
{
    public function heartbeat(DeviceHeartbeatRequest $request, DeviceHeartbeatService $heartbeats, ?\App\Models\Device $device = null): DeviceResource
    {
        $targetDevice = $device ?? $request->attributes->get('device');
        if (! $targetDevice) {
            abort(401, 'Device authentication required.');
        }

        return (new DeviceResource($heartbeats->record($targetDevice, $request->validated(), $request->ip())))
            ->additional(['message' => 'Heartbeat accepted.']);
    }
}
