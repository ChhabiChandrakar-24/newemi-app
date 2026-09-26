<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_push_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('fcm');
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->string('platform', 20)->default('android');
            $table->string('app_version', 50)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('registered_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_push_tokens');
    }
};
