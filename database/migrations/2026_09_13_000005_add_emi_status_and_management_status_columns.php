<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emi_accounts', function (Blueprint $table): void {
            $table->enum('emi_status', [
                'ACTIVE', 'WARNING', 'GRACE_PERIOD', 'RESTRICTED', 'LOCKED',
                'PAID', 'RELEASED', 'CLOSED', 'AT_RISK', 'UNENROLLED',
            ])->nullable()->index()->after('status');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->enum('management_status', [
                'NOT_ENROLLED', 'ENROLLMENT_PENDING', 'ENROLLED', 'MANAGED',
                'ACTIVE', 'AT_RISK', 'UNENROLLED', 'RE_ENROLLMENT_REQUIRED',
                'RELEASED', 'RESTRICTED', 'LOCKED',
            ])->nullable()->index()->after('enrollment_status');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('management_status');
        });

        Schema::table('emi_accounts', function (Blueprint $table): void {
            $table->dropColumn('emi_status');
        });
    }
};