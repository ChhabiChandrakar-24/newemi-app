<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('emi_schedule_id')->constrained()->restrictOnDelete();
            $table->decimal('allocated_amount', 12, 2);
            $table->string('allocation_type', 30)->default('installment');
            $table->timestamps();
            $table->index(['payment_id', 'allocation_type']);
            $table->index(['emi_schedule_id', 'allocation_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
