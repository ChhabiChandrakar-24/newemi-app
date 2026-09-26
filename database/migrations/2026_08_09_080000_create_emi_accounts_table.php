<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emi_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('emi_account_code', 32)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number')->index();
            $table->date('invoice_date')->nullable();
            $table->string('product_description')->nullable();
            $table->decimal('financed_amount', 12, 2);
            $table->decimal('down_payment', 12, 2)->default(0);
            $table->decimal('principal_amount', 12, 2);
            $table->unsignedSmallInteger('total_installments');
            $table->decimal('installment_amount', 12, 2);
            $table->date('emi_start_date')->index();
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->unsignedSmallInteger('grace_period_days')->default(0);
            $table->decimal('interest_amount', 12, 2)->default(0);
            $table->decimal('processing_fee', 12, 2)->default(0);
            $table->decimal('other_charges', 12, 2)->default(0);
            $table->decimal('total_payable', 12, 2);
            $table->decimal('total_paid', 12, 2)->default(0);
            $table->decimal('outstanding_amount', 12, 2);
            $table->decimal('overdue_amount', 12, 2)->default(0);
            $table->unsignedSmallInteger('overdue_installments')->default(0);
            $table->date('next_due_date')->nullable()->index();
            $table->date('last_payment_date')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->boolean('auto_lock_enabled')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emi_accounts');
    }
};
