<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 60)->index();
            $table->string('severity', 20)->index();
            $table->timestamp('event_time')->index();
            $table->json('payload')->nullable();
            $table->timestamp('acknowledged_at')->nullable()->index();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['device_id', 'event_time']);
            $table->index(['severity', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');
    }
};
