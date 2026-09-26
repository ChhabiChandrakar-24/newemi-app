<?php

namespace App\Contracts;

use App\Models\DeviceCommand;

interface DeviceCommandNotifierInterface
{
    public function notify(DeviceCommand $command): void;
}
