<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\AutomaticDeviceCommandService;
use Illuminate\Console\Command;

class EvaluateDeviceLockPolicies extends Command
{
    protected $signature = 'devices:evaluate-lock-policies';

    protected $description = 'Evaluate configured lock policies and queue supported device commands';

    public function handle(AutomaticDeviceCommandService $engine, \App\Services\EmiOverdueService $overdueService): int
    {
        $summary = ['processed' => 0, 'queued' => 0, 'unchanged' => 0, 'skipped' => 0, 'unsupported' => 0, 'duplicate' => 0];
        Device::query()->where('enrollment_status', 'enrolled')->whereNull('released_at')->whereNotNull('emi_account_id')->with(['emiAccount', 'customer'])->chunkById(100, function ($devices) use ($engine, $overdueService, &$summary): void {
            foreach ($devices as $device) {
                $summary['processed']++;
                if ($device->emiAccount) {
                    $overdueService->recalculate($device->emiAccount);
                    $device->refresh();
                }
                $result = $engine->evaluate($device);
                $summary[$result['outcome']]++;
            }
        });
        $this->components->info(collect($summary)->map(fn ($value, $key) => "{$key}={$value}")->implode(' '));

        return self::SUCCESS;
    }
}
