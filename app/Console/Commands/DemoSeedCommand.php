<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed {--force : Explicitly allow an intentional production demo seed}';
    protected $description = 'Populate isolated, synthetic development/test demo records';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to seed demo data in production without --force.');
            return self::FAILURE;
        }
        if (app()->environment('production')) putenv('DEMO_SEED_ALLOWED=true');
        $this->call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => (bool) $this->option('force')]);
        $this->info('Synthetic demo data is ready. No record represents a real managed device or person.');
        return self::SUCCESS;
    }
}
