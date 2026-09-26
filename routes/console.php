<?php

use App\Jobs\GenerateReportJob;
use App\Models\BackupRun;
use App\Models\GeneratedReport;
use App\Models\ScheduledReport;
use App\Services\AlertService;
use App\Services\BackupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('device-commands:expire')->everyMinute()->withoutOverlapping();
Schedule::command('devices:evaluate-lock-policies')->everyMinute()->withoutOverlapping();
Schedule::command('devices:mark-offline')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('devices:purge-old-locations')->daily()->withoutOverlapping();
Schedule::command('reports:run-scheduled')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('reports:cleanup')->daily()->withoutOverlapping();
Schedule::command('alerts:dispatch-pending')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('emi:run-cycle')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('retention:apply')->daily()->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('02:00')->withoutOverlapping()->when(fn () => config('backup.enabled'));
Schedule::command('backup:cleanup')->dailyAt('03:00')->withoutOverlapping()->when(fn () => config('backup.enabled'));

Artisan::command('backup:run', function (BackupService $backups) {
    $run = $backups->run();
    $this->info("Backup completed: {$run->size_bytes} bytes; SHA-256 {$run->checksum}");
})->purpose('Create a private database backup');
Artisan::command('backup:list', function () {
    $this->table(['Date', 'Type', 'Status', 'Bytes', 'SHA-256'], BackupRun::latest()->limit(30)->get()->map(fn ($r) => [$r->created_at, $r->type, $r->status, $r->size_bytes, $r->checksum]));
})->purpose('List safe backup metadata');
Artisan::command('backup:cleanup', function (BackupService $backups) {
    $this->info($backups->cleanup().' expired backups removed.');
})->purpose('Remove backups beyond configured retention');
Artisan::command('system:check', function () {
    $checks = ['PHP >= 8.3' => version_compare(PHP_VERSION, '8.3.0', '>='), 'APP_KEY' => filled(config('app.key')), 'Database' => rescue(fn () => DB::select('select 1') !== [], false, false), 'Storage writable' => is_writable(storage_path()), 'Cache writable' => is_writable(storage_path('framework/cache')), 'Frontend build' => file_exists(base_path('../frontend/dist/index.html'))];
    foreach ($checks as $name => $ok) {
        $this->line(($ok ? '[PASS] ' : '[FAIL] ').$name);
    }

    return in_array(false, $checks, true) ? 1 : 0;
})->purpose('Check production prerequisites without exposing secrets');

Artisan::command('dev:network-check {--port=8000 : Expected local development port}', function () {
    $port = (int) $this->option('port');
    $addresses = collect(gethostbynamel(gethostname()) ?: [])
        ->filter(fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && ! str_starts_with($ip, '127.'))
        ->unique()->values();
    $preferred = $addresses->first() ?: '<DEVELOPMENT_PC_LAN_IP>';

    $this->table(['Check', 'Value'], [
        ['Laravel environment', app()->environment()],
        ['Detected PC IPv4', $addresses->isEmpty() ? 'None detected' : $addresses->join(', ')],
        ['Configured APP_URL', config('app.url')],
        ['Expected Android API URL', "http://{$preferred}:{$port}/api/v1/"],
        ['Development port', (string) $port],
        ['Health endpoint', "/api/health (http://{$preferred}:{$port}/api/health)"],
        ['Required listen address', "0.0.0.0:{$port} or {$preferred}:{$port}"],
    ]);
    $this->warn('This command is diagnostic only; it does not start a server or change Windows Firewall.');
})->purpose('Show safe LAN settings for Android-to-Laravel development');

Artisan::command('alerts:dispatch-pending', function (AlertService $alerts) {
    $this->info($alerts->syncDeviceEvents().' dashboard alerts created.');
})->purpose('Create deduplicated dashboard alerts from high-priority device events');

Artisan::command('reports:run-scheduled', function () {
    ScheduledReport::query()->where('is_active', true)->where('next_run_at', '<=', now())->each(function ($schedule): void {
        $report = GeneratedReport::create(['report_uuid' => (string) Str::uuid(), 'report_type' => $schedule->report_type, 'requested_by' => $schedule->created_by, 'format' => $schedule->format, 'filters' => $schedule->filters, 'status' => 'queued', 'requested_at' => now(), 'expires_at' => now()->addDays(7)]);
        GenerateReportJob::dispatch($report->id);
        $next = match ($schedule->schedule_type) {
            'daily' => now()->addDay(), 'weekly' => now()->addWeek(), default => now()->addMonth()
        };
        $schedule->update(['last_run_at' => now(), 'next_run_at' => $next]);
    });
})->purpose('Dispatch due scheduled reports idempotently');

Artisan::command('reports:cleanup', function () {
    GeneratedReport::query()->where('expires_at', '<=', now())->each(function ($report): void {
        if ($report->file_path) {
            Storage::disk('local')->delete($report->file_path);
        }
        $report->update(['status' => 'expired', 'file_path' => null]);
    });
})->purpose('Expire generated report files safely');

Artisan::command('emi:run-cycle', function (\App\Services\EmiLifecycleService $lifecycle, \App\Services\AutomaticDeviceCommandService $commandEngine) {
    $summary = $lifecycle->recalculateAll();
    $this->info("EMI cycle evaluated: {$summary['processed']} accounts processed, {$summary['changed']} changed.");

    \App\Models\Device::query()
        ->where('enrollment_status', 'enrolled')
        ->whereNull('released_at')
        ->whereNotNull('emi_account_id')
        ->chunkById(100, function ($devices) use ($commandEngine): void {
            foreach ($devices as $device) {
                $commandEngine->evaluate($device);
            }
        });
})->purpose('Recalculate EMI lifecycle, overdue, grace period and evaluate restrictions');

Artisan::command('retention:apply', function (\App\Services\DataRetentionPolicyService $retentionService) {
    $companies = \App\Models\Company::all();
    $totalDeleted = 0;
    $totalAnonymized = 0;
    foreach ($companies as $company) {
        $result = $retentionService->apply($company);
        $totalDeleted += $result['deleted'];
        $totalAnonymized += $result['anonymized'];
    }
    $this->info("Retention applied: {$companies->count()} companies, {$totalDeleted} deleted, {$totalAnonymized} anonymized.");
})->purpose('Apply data retention policies to expire or anonymize aged device telemetry');

