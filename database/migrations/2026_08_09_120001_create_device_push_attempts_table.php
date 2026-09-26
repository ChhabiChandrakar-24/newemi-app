<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_push_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_command_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('fcm');
            $table->string('status', 20)->default('pending')->index();
            $table->string('provider_message_id')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamps();
            $table->index(['device_command_id', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_push_attempts');
    }
};
