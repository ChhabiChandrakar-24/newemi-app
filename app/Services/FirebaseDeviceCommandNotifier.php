<?php

namespace App\Services;

use App\Contracts\DeviceCommandNotifierInterface;
use App\Jobs\SendDeviceCommandPushJob;
use App\Models\DeviceCommand;

class FirebaseDeviceCommandNotifier implements DeviceCommandNotifierInterface
{
    public function notify(DeviceCommand $command): void
    {
        SendDeviceCommandPushJob::dispatch($command->id, $command->company_id)->onQueue('device-push')->afterCommit();
    }
}
