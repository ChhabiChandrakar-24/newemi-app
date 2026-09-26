<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->uuid('webhook_key')->unique();
            $table->string('provider', 50);
            $table->string('display_name');
            $table->enum('environment', ['test', 'live'])->default('test');
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->text('public_key')->nullable();
            $table->text('encrypted_secret')->nullable();
            $table->text('encrypted_webhook_secret')->nullable();
            $table->longText('config')->nullable();
            $table->string('status', 50)->default('not_configured');
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 50)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'provider']);
            $table->index(['company_id', 'is_enabled', 'is_default']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
