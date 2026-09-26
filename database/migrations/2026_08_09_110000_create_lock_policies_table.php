<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lock_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('warning_after_overdue_days')->default(1);
            $table->unsignedSmallInteger('partial_lock_after_overdue_days')->nullable();
            $table->unsignedSmallInteger('full_lock_after_overdue_days')->nullable();
            $table->boolean('unlock_on_payment_clearance')->default(true);
            $table->unsignedSmallInteger('grace_period_override')->nullable();
            $table->string('offline_behavior', 30)->default('defer');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lock_policies');
    }
};
