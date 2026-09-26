<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->dateTime('captured_at')->index();
            $table->dateTime('received_at');
            $table->string('source', 30);
            $table->string('tracking_mode', 30);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['device_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_locations');
    }
};
