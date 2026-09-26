<?php

return ['enabled' => (bool) env('BACKUP_ENABLED', false), 'disk' => env('BACKUP_DISK', 'local'), 'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14), 'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'), 'encryption_password' => env('BACKUP_ENCRYPTION_PASSWORD')];
