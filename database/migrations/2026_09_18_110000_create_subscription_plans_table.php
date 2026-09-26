<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 64)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_months')->default(1);
            $table->unsignedInteger('device_limit')->default(25);
            $table->decimal('price', 10, 2)->default(0.00);
            $table->string('currency', 3)->default('INR');
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_popular')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Seed initial default plans
        DB::table('subscription_plans')->insert([
            [
                'name' => 'Starter 1 Month',
                'code' => 'starter-1m-25dev',
                'description' => 'Ideal for small retail shops starting out with EMI device management.',
                'duration_months' => 1,
                'device_limit' => 25,
                'price' => 999.00,
                'currency' => 'INR',
                'features' => json_encode([
                    'Up to 25 Devices',
                    '1 Month Full Validity',
                    'Kiosk Lock & Warning System',
                    'Real-time Heartbeat & Location',
                    'Standard Email & Phone Support',
                ]),
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Growth 3 Months',
                'code' => 'growth-3m-50dev',
                'description' => 'Great for growing mobile retail stores with medium monthly volume.',
                'duration_months' => 3,
                'device_limit' => 50,
                'price' => 2499.00,
                'currency' => 'INR',
                'features' => json_encode([
                    'Up to 50 Devices',
                    '3 Months Validity',
                    'Factory Reset & Safe Boot Block',
                    'Anti-tamper & Uninstall Lock',
                    'Automated EMI Reminders',
                    'Priority Support',
                ]),
                'is_active' => true,
                'is_popular' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Business 6 Months',
                'code' => 'business-6m-100dev',
                'description' => 'Designed for established multi-counter electronics & smartphone shops.',
                'duration_months' => 6,
                'device_limit' => 100,
                'price' => 4799.00,
                'currency' => 'INR',
                'features' => json_encode([
                    'Up to 100 Devices',
                    '6 Months Validity',
                    'Complete Lock & Unlock Control',
                    'Customer Payment Receipts',
                    'Multi-Staff Role Permissions',
                    'Dedicated Account Manager',
                ]),
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Enterprise Annual',
                'code' => 'enterprise-12m-250dev',
                'description' => 'Maximum savings and highest device quota for high-volume dealers.',
                'duration_months' => 12,
                'device_limit' => 250,
                'price' => 8999.00,
                'currency' => 'INR',
                'features' => json_encode([
                    'Up to 250 Devices',
                    '12 Months (1 Full Year) Validity',
                    'All Advanced Security Policies',
                    'Automated Payment Gateways',
                    'Custom Lock Screen Branding',
                    '24x7 Priority SLA Support',
                ]),
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
