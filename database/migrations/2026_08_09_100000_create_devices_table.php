<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->string('device_code', 32)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('emi_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('display_name')->nullable();
            $table->string('brand', 100)->nullable()->index();
            $table->string('model', 150)->nullable()->index();
            $table->string('manufacturer', 100)->nullable();
            $table->string('android_version', 50)->nullable();
            $table->unsignedSmallInteger('sdk_version')->nullable();
            $table->string('serial_number', 150)->nullable();
            $table->string('imei', 32)->nullable()->index();
            $table->uuid('internal_device_uuid')->unique();
            $table->string('installation_id_hash', 64)->nullable()->unique();
            $table->string('invoice_number', 100)->nullable()->index();
            $table->string('app_version', 50)->nullable();
            $table->string('management_mode', 30)->default('unmanaged')->index();
            $table->string('enrollment_status', 30)->default('pending')->index();
            $table->string('control_status', 30)->default('active')->index();
            $table->string('connectivity_status', 20)->default('unknown')->index();
            $table->string('compliance_status', 30)->default('unknown')->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->ipAddress('last_ip_address')->nullable();
            $table->unsignedTinyInteger('battery_level')->nullable();
            $table->boolean('battery_charging')->nullable();
            $table->string('network_type', 30)->nullable();
            $table->string('sim_state', 50)->nullable();
            $table->string('sim_fingerprint', 128)->nullable();
            $table->string('policy_version', 50)->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamp('consent_verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
