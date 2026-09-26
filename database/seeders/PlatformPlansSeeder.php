<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class PlatformPlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter Plan',
                'code' => 'STARTER',
                'description' => 'Ideal for small retail shops starting with device locking.',
                'duration_type' => 'months',
                'duration_value' => 12,
                'duration_months' => 12,
                'device_limit' => 100,
                'price' => 5000,
                'currency' => 'INR',
                'is_trial' => false,
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 10,
                'features' => ['emi', 'devices', 'payments', 'reports'],
            ],
            [
                'name' => 'Professional Plan',
                'code' => 'PRO',
                'description' => 'Best for growing businesses with multiple locations.',
                'duration_type' => 'months',
                'duration_value' => 12,
                'duration_months' => 12,
                'device_limit' => 500,
                'price' => 20000,
                'currency' => 'INR',
                'is_trial' => false,
                'is_active' => true,
                'is_popular' => true,
                'sort_order' => 20,
                'features' => ['emi', 'devices', 'payments', 'reports', 'crm'],
            ],
            [
                'name' => 'Enterprise Plan',
                'code' => 'ENTERPRISE',
                'description' => 'Unlimited scaling for large distributors.',
                'duration_type' => 'months',
                'duration_value' => 12,
                'duration_months' => 12,
                'device_limit' => 5000,
                'price' => 100000,
                'currency' => 'INR',
                'is_trial' => false,
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 30,
                'features' => ['emi', 'devices', 'payments', 'reports', 'crm', 'audit'],
            ],
            [
                'name' => '14-Day Free Trial',
                'code' => 'TRIAL',
                'description' => 'Try all features for 14 days.',
                'duration_type' => 'days',
                'duration_value' => 14,
                'duration_months' => 1,
                'device_limit' => 10,
                'price' => 0,
                'currency' => 'INR',
                'is_trial' => true,
                'is_active' => true,
                'is_popular' => false,
                'sort_order' => 0,
                'features' => ['emi', 'devices', 'payments', 'reports', 'crm'],
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::withTrashed()->updateOrCreate(
                ['code' => $plan['code']],
                array_merge($plan, ['deleted_at' => null])
            );
        }
    }
}
