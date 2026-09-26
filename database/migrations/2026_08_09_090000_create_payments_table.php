<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_code', 32)->unique();
            $table->foreignId('emi_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('payment_date')->index();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 30)->index();
            $table->string('transaction_reference', 150)->nullable()->index();
            $table->string('external_reference', 150)->nullable()->index();
            $table->string('receipt_number', 40)->nullable()->unique();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('payment_type', 30)->index();
            $table->string('status', 20)->default('pending')->index();
            $table->text('notes')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('created_at');
            $table->index(['emi_account_id', 'status']);
            $table->index(['customer_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
