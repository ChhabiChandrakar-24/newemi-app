<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('report_uuid')->unique();
            $table->string('report_type', 60)->index();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('format', 10);
            $table->json('filters');
            $table->string('status', 20)->default('queued')->index();
            $table->string('file_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('scheduled_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('report_type', 60);
            $table->json('filters');
            $table->string('format', 10);
            $table->string('schedule_type', 20);
            $table->json('schedule_config');
            $table->json('delivery_channels');
            $table->json('recipients');
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('system_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('deduplication_key')->nullable()->unique();
            $table->string('alert_type', 60)->index();
            $table->string('severity', 20)->index();
            $table->string('title');
            $table->text('message');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
        Schema::dropIfExists('scheduled_reports');
        Schema::dropIfExists('generated_reports');
    }
};
