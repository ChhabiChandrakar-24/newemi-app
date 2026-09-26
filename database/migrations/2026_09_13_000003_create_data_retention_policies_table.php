<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_retention_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->enum('data_type', [
                'device_events', 'device_notifications', 'device_locations',
                'device_commands', 'device_consents', 'audit_logs',
            ])->default('device_events')->index();
            $table->unsignedInteger('retention_period_days')->default(90);
            $table->enum('action', ['delete', 'anonymize', 'keep'])->default('delete');
            $table->string('reason', 191)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'data_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_retention_policies');
    }
};