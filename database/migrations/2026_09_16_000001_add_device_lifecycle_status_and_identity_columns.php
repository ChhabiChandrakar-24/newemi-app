<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            if (! Schema::hasColumn('devices', 'imei2')) {
                $table->string('imei2', 32)->nullable()->index()->after('imei');
            }
            if (! Schema::hasColumn('devices', 'connection_status')) {
                $table->string('connection_status', 20)->default('UNKNOWN')->index()->after('connectivity_status');
            }
            if (! Schema::hasColumn('devices', 'device_lock_status')) {
                $table->string('device_lock_status', 20)->default('UNLOCKED')->index()->after('control_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            if (Schema::hasColumn('devices', 'imei2')) {
                $table->dropColumn('imei2');
            }
            if (Schema::hasColumn('devices', 'connection_status')) {
                $table->dropColumn('connection_status');
            }
            if (Schema::hasColumn('devices', 'device_lock_status')) {
                $table->dropColumn('device_lock_status');
            }
        });
    }
};
