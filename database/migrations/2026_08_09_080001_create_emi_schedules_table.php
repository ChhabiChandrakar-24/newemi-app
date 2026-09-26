<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emi_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emi_account_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->date('due_date')->index();
            $table->decimal('opening_balance', 12, 2);
            $table->decimal('principal_due', 12, 2);
            $table->decimal('interest_due', 12, 2)->default(0);
            $table->decimal('installment_amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('outstanding_amount', 12, 2);
            $table->decimal('overdue_amount', 12, 2)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->date('grace_until')->nullable();
            $table->timestamps();
            $table->unique(['emi_account_id', 'installment_number']);
            $table->index(['emi_account_id', 'due_date']);
            $table->index(['emi_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emi_schedules');
    }
};
