<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakePlatformAdmin extends Command
{
    protected $signature = 'platform:make-admin {email} {--yes : Skip confirmation}';

    protected $description = 'Deliberately promote an existing user to platform administrator';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->firstOrFail();
        if (! $this->option('yes') && ! $this->confirm("Promote {$user->email} to platform-wide administrator?")) {
            return self::FAILURE;
        }
        $user->forceFill(['is_platform_admin' => true, 'company_id' => null])->save();
        $this->info('Platform administrator promoted. This action should be recorded in deployment evidence.');

        return self::SUCCESS;
    }
}
