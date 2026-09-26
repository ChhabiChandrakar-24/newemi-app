<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupService
{
    public function run(): BackupRun
    {
        $run = BackupRun::create(['type' => 'database', 'status' => 'running', 'disk' => config('backup.disk'), 'started_at' => now()]);
        try {
            $name = 'backups/database-'.now()->format('Ymd-His').'.sql';
            $tmp = tempnam(sys_get_temp_dir(), 'emi-backup-');
            $connection = config('database.default');
            if ($connection === 'sqlite') {
                copy(config('database.connections.sqlite.database'), $tmp);
            } else {
                $c = config("database.connections.$connection");
                $args = [config('backup.mysqldump_binary'), '--single-transaction', '--quick', '--skip-lock-tables', '--host='.$c['host'], '--port='.(string) $c['port'], '--user='.$c['username'], '--result-file='.$tmp, $c['database']];
                $process = new Process($args, null, ['MYSQL_PWD' => (string) $c['password']]);
                $process->setTimeout(900);
                $process->mustRun();
            }Storage::disk(config('backup.disk'))->put($name, fopen($tmp, 'rb'));
            $checksum = hash_file('sha256', $tmp);
            $size = filesize($tmp);
            unlink($tmp);
            $run->update(['status' => 'completed', 'file_name' => $name, 'size_bytes' => $size, 'checksum' => $checksum, 'completed_at' => now()]);

            return $run;
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error' => str($e->getMessage())->limit(500), 'completed_at' => now()]);
            throw $e;
        }
    }

    public function cleanup(): int
    {
        $count = 0;
        BackupRun::where('created_at', '<', now()->subDays(config('backup.retention_days')))->where('status', 'completed')->each(function ($r) use (&$count) {
            if ($r->file_name) {
                Storage::disk($r->disk)->delete($r->file_name);
            }$r->delete();
            $count++;
        });

        return $count;
    }
}
