<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('discount_type')->default('percentage'); // 'percentage' or 'fixed'
            $table->decimal('discount_value', 10, 2); // e.g. 50 (for 50%) or 500 (for ₹500)
            $table->decimal('max_discount_amount', 10, 2)->nullable(); // cap for percentage discount
            $table->decimal('min_order_amount', 10, 2)->default(0); // minimum purchase required
            $table->unsignedInteger('usage_limit')->nullable(); // total usage cap across platform
            $table->unsignedInteger('times_used')->default(0);
            $table->unsignedInteger('usage_limit_per_company')->default(1); // max uses per shop
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('allowed_plans')->nullable(); // null for all plans, or array of plan_ids
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['code', 'is_active']);
        });

        Schema::create('promo_code_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('company_recharge_id')->nullable()->constrained('company_recharges')->nullOnDelete();
            $table->decimal('original_amount', 10, 2);
            $table->decimal('discount_amount', 10, 2);
            $table->decimal('final_amount', 10, 2);
            $table->timestamps();

            $table->index(['promo_code_id', 'company_id']);
        });

        Schema::table('company_recharges', function (Blueprint $table) {
            if (!Schema::hasColumn('company_recharges', 'promo_code')) {
                $table->string('promo_code', 50)->nullable()->after('amount');
            }
            if (!Schema::hasColumn('company_recharges', 'discount_amount')) {
                $table->decimal('discount_amount', 10, 2)->default(0)->after('promo_code');
            }
            if (!Schema::hasColumn('company_recharges', 'original_amount')) {
                $table->decimal('original_amount', 10, 2)->nullable()->after('discount_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_recharges', function (Blueprint $table) {
            if (Schema::hasColumn('company_recharges', 'original_amount')) {
                $table->dropColumn('original_amount');
            }
            if (Schema::hasColumn('company_recharges', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }
            if (Schema::hasColumn('company_recharges', 'promo_code')) {
                $table->dropColumn('promo_code');
            }
        });

        Schema::dropIfExists('promo_code_usages');
        Schema::dropIfExists('promo_codes');
    }
};
