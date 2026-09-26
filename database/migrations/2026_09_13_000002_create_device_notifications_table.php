<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->index();
            $table->foreignId('device_id')->nullable()->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('emi_account_id')->nullable()->constrained();

            $table->string('notification_type', 80)->index();
            $table->enum('recipient_type', ['device', 'customer', 'admin'])
                ->default('device')->index();
            $table->string('title', 191);
            $table->text('message')->nullable();
            $table->enum('delivery_status', ['queued', 'sent', 'failed', 'skipped'])
                ->default('queued')->index();
            $table->enum('channel', ['push', 'in_app', 'email', 'sms', 'system'])
                ->default('push');
            $table->timestamp('delivered_at')->nullable();
            $table->string('deduplication_key', 191)->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['company_id', 'delivery_status', 'created_at']);
            $table->index(['company_id', 'notification_type']);
            $table->index(['device_id', 'notification_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_notifications');
    }
};