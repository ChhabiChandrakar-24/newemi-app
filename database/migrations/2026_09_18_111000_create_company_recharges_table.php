<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_recharges', function (Blueprint $table): void {
            $table->id();
            $table->string('recharge_code', 32)->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('plan_name', 100);
            $table->unsignedInteger('duration_months');
            $table->unsignedInteger('device_limit');
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->string('currency', 3)->default('INR');
            $table->string('payment_method', 32)->default('upi');
            $table->string('payment_reference', 100)->nullable();
            $table->string('payment_status', 32)->default('successful');
            $table->string('recharge_status', 32)->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'recharge_status']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_recharges');
    }
};
