<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('device_management_settings')->whereNull('updated_by')->where('heartbeat_timeout_minutes', 45)->update(['heartbeat_timeout_minutes' => 30]);
    }

    public function down(): void {}
};
