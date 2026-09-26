<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_api_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('name', 100)->default('android-agent');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
            $table->index(['device_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_api_credentials');
    }
};
