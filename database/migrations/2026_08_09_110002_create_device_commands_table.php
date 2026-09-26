<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('command_uuid')->unique();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('command_type', 30)->index();
            $table->string('requested_control_status', 30)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedTinyInteger('priority')->default(50)->index();
            $table->string('source', 20)->default('manual')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->index();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->unsignedSmallInteger('max_retries')->default(3);
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->text('result_message')->nullable();
            $table->text('remarks')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->json('result_payload')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'idempotency_key']);
            $table->index(['device_id', 'status', 'available_at', 'priority'], 'device_command_dispatch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
