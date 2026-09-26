<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('desired_control_status', 30)->nullable()->after('control_status')->index();
            $table->foreignId('lock_policy_id')->nullable()->after('desired_control_status')->constrained()->nullOnDelete();
            $table->boolean('policy_automation_paused')->default(false)->after('lock_policy_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropForeign(['lock_policy_id']);
            $table->dropColumn(['desired_control_status', 'lock_policy_id', 'policy_automation_paused']);
        });
    }
};
