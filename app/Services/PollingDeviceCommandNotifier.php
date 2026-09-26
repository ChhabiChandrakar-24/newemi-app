<?php

namespace App\Services;

use App\Contracts\DeviceCommandNotifierInterface;
use App\Models\DeviceCommand;

class PollingDeviceCommandNotifier implements DeviceCommandNotifierInterface
{
    public function notify(DeviceCommand $command): void
    {
        // Polling is authoritative.
        // For connected testing devices, send an immediate ADB wake-up broadcast for instant (<1s) synchronization.
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                pclose(popen('start /B adb shell am broadcast -a com.example.emiagent.action.SYNC >NUL 2>&1', 'r'));
            } else {
                exec('adb shell am broadcast -a com.example.emiagent.action.SYNC > /dev/null 2>&1 &');
            }
        } catch (\Throwable) {
            // Non-blocking fallback
        }
    }
}
