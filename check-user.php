<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$users = App\Models\User::withoutGlobalScopes()->get();
foreach ($users as $u) {
    echo "ID: {$u->id} | Email: {$u->email} | Status: {$u->status} | is_platform_admin: " . ($u->is_platform_admin ? '1' : '0') . " | Company_ID: {$u->company_id}" . PHP_EOL;
}
