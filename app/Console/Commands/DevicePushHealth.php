<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DevicePushToken;
use Illuminate\Console\Command;

class DevicePushHealth extends Command
{
    protected $signature = 'devices:push-health';

    protected $description = 'Show aggregate push registration health without exposing tokens';

    public function handle(): int
    {
        $enrolled = Device::where('enrollment_status', 'enrolled')->count();
        $with = Device::where('enrollment_status', 'enrolled')->whereHas('pushTokens', fn ($q) => $q->where('is_active', true))->count();
        $invalid = DevicePushToken::whereNotNull('invalidated_at')->where('invalidated_at', '>=', now()->subDays(7))->count();
        $this->table(['Metric', 'Count'], [['Enrolled devices', $enrolled], ['With active push token', $with], ['Without active push token', max(0, $enrolled - $with)], ['Invalidated in 7 days', $invalid]]);

        return self::SUCCESS;
    }
}
