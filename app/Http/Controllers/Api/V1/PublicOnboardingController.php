<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyRecharge;
use App\Models\LockPolicy;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionPlan;
use App\Models\SystemAlert;
use App\Models\User;
use App\Services\DeviceManagementSettingsService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PublicOnboardingController extends Controller
{
    /**
     * Get active plans for public registration page.
     */
    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $plans]);
    }

    /**
     * Self-service company onboarding with plan purchase.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'exists:subscription_plans,id'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'owner_mobile' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'vpa' => ['nullable', 'string', 'max:100'],
            'autopay_bank' => ['nullable', 'string', 'max:100'],
            'is_autopay' => ['nullable', 'boolean'],
        ]);

        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);
        $isAutoPay = ($validated['payment_method'] ?? '') === 'bank_autopay' || (bool) ($validated['is_autopay'] ?? false) || (bool) $plan->is_trial;

        if ($isAutoPay) {
            $gateway = \App\Models\PaymentGateway::withoutGlobalScopes()
                ->whereNull('company_id')
                ->where('is_enabled', true)
                ->where('is_default', true)
                ->first();

            if (!$gateway) {
                return response()->json(['message' => 'No payment gateway available for AutoPay setup.'], 422);
            }

            $provider = app(\App\Services\Payments\PaymentGatewayManager::class)->provider($gateway->provider);
            
            $now = now();
            $durationType = $plan->duration_type ?: 'months';
            $durationValue = (int) ($plan->duration_value ?: $plan->duration_months ?: 1);

            if ($durationType === 'hours') {
                $expiresAt = $now->copy()->addHours($durationValue);
            } elseif ($durationType === 'days') {
                $expiresAt = $now->copy()->addDays($durationValue);
            } else {
                $expiresAt = $now->copy()->addMonths($durationValue);
            }

            // Razorpay uses timestamp for start_at (must be at least 24 hours in the future for Subscriptions, but we can set it for after trial)
            // If it's a trial, we start the subscription after the trial period. If not, we start it now (Razorpay will charge immediately if start_at is omitted).
            $startAt = $plan->is_trial ? $expiresAt->timestamp : null;

            $periodMap = [
                'hours' => 'daily', // Razorpay doesn't support hourly, minimum is daily
                'days' => 'daily',
                'months' => 'monthly'
            ];
            
            $interval = $durationType === 'hours' ? 1 : $durationValue;

            $sub = $provider->createSubscription($gateway, [
                'name' => $plan->name,
                'amount' => $plan->price,
                'currency' => $plan->currency ?? 'INR',
                'period' => $periodMap[$durationType] ?? 'monthly',
                'interval' => $interval,
            ], [
                'total_count' => 120, // 10 years recurring
                'start_at' => $startAt,
                'notes' => [
                    'company_name' => $validated['company_name'],
                    'phone' => $validated['phone'],
                ],
            ]);

            // Save pending registration in session or cache, or simply return the subscription ID to the frontend.
            // The frontend will call verifyPayment and send the full payload again along with razorpay details.
            // For statelessness, we can just return requires_payment and let the frontend keep the state, 
            // but we must store the payload securely. Actually, returning it to frontend is fine as they will submit it again.
            // Wait, we can just create the company as "pending_payment" to secure the email/phone.
        }

        $result = DB::transaction(function () use ($request, $validated, $plan, $isAutoPay, &$sub, &$gateway) {
            $id = (int) Company::withTrashed()->max('id') + 1;
            $companyCode = 'CMP-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            $now = now();
            $durationType = $plan->duration_type ?: 'months';
            $durationValue = (int) ($plan->duration_value ?: $plan->duration_months ?: 1);

            if ($durationType === 'hours') {
                $expiresAt = $now->copy()->addHours($durationValue);
            } elseif ($durationType === 'days') {
                $expiresAt = $now->copy()->addDays($durationValue);
            } else {
                $expiresAt = $now->copy()->addMonths($durationValue);
            }

            $mandateId = $isAutoPay ? ($sub['subscription_id'] ?? 'MND-' . strtoupper(Str::random(10))) : null;
            $initialCharge = $plan->is_trial ? 1.00 : (float) $plan->price;

            $settings = [
                'branding' => ['primary_color' => '#2563eb'],
                'plan_code' => $plan->code,
                'autopay' => [
                    'enabled' => $isAutoPay,
                    'mandate_id' => $mandateId,
                    'vpa' => $validated['vpa'] ?? null,
                    'bank' => $validated['autopay_bank'] ?? 'UPI AutoPay (NPCI)',
                    'status' => $isAutoPay ? 'pending' : 'inactive',
                    'auth_amount' => $initialCharge,
                    'recurring_amount' => (float) $plan->price,
                    'frequency' => $durationType === 'days' ? "Every {$durationValue} Days" : ($durationType === 'hours' ? "Every {$durationValue} Hours" : "Every {$durationValue} Month(s)"),
                    'next_debit_at' => $expiresAt->toIso8601String(),
                ],
            ];

            $company = Company::create([
                'company_code' => $companyCode,
                'name' => $validated['company_name'],
                'legal_name' => $validated['company_name'] . ' Private Limited',
                'email' => $validated['company_email'] ?: $validated['owner_email'],
                'phone' => $validated['phone'],
                'city' => $validated['city'] ?? 'City',
                'state' => $validated['state'] ?? 'State',
                'plan' => $plan->name,
                'max_devices' => $plan->device_limit,
                'max_users' => 10,
                'subscription_status' => 'active',
                'status' => $isAutoPay ? 'pending_payment' : 'active',
                'activated_at' => $isAutoPay ? null : $now,
                'expires_at' => $expiresAt,
                'trial_ends_at' => $plan->is_trial ? $expiresAt : null,
                'settings' => $settings,
            ]);

            $user = User::create([
                'company_id' => $company->id,
                'name' => $validated['owner_name'],
                'email' => $validated['owner_email'],
                'mobile_number' => $validated['owner_mobile'] ?? $validated['phone'],
                'password' => Hash::make($validated['password']),
                'status' => 'active',
                'is_platform_admin' => false,
            ]);

            try {
                $user->syncRoles(['admin']);
            } catch (\Throwable) {}

            if (!$isAutoPay) {
                // Setup default policies for the new company
                app(TenantContext::class)->run($company, function () use ($user): void {
                    LockPolicy::create([
                        'name' => 'Default Company Lock Policy',
                        'is_default' => true,
                        'is_active' => true,
                        'lock_type' => 'full',
                        'grace_period_override' => 3,
                        'unlock_on_payment_clearance' => true,
                        'offline_behavior' => 'defer',
                        'created_by' => $user->id,
                    ]);
                    app(DeviceManagementSettingsService::class)->current();
                });

                // Create initial recharge record
                $recharge = CompanyRecharge::create([
                    'recharge_code' => 'RCH-' . strtoupper(Str::random(8)),
                    'company_id' => $company->id,
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'duration_months' => $plan->duration_months,
                    'duration_type' => $durationType,
                    'duration_value' => $durationValue,
                    'device_limit' => $plan->device_limit,
                    'amount' => $initialCharge,
                    'currency' => $plan->currency ?? 'INR',
                    'payment_method' => $validated['payment_method'] ?? 'upi',
                    'payment_reference' => $validated['payment_reference'] ?? ('ONBOARD-' . time()),
                    'payment_status' => 'successful',
                    'recharge_status' => 'active',
                    'starts_at' => $now,
                    'expires_at' => $expiresAt,
                    'notes' => 'Self-service registration plan purchase',
                    'created_by' => $user->id,
                    'approved_at' => $now,
                ]);

                // Notify platform admin
                SystemAlert::create([
                    'company_id' => 1,
                    'deduplication_key' => 'new-company:' . $company->id,
                    'alert_type' => 'company_registered',
                    'severity' => 'low',
                    'title' => 'New Company Registered: ' . $company->name,
                    'message' => "{$company->name} ({$company->company_code}) purchased {$plan->name} with {$plan->device_limit} devices limit for ₹" . number_format((float) $plan->price, 2) . ". Owner: {$user->name} ({$user->email}).",
                    'entity_type' => 'company',
                    'entity_id' => $company->id,
                    'status' => 'open',
                ]);

                PlatformAuditLog::create([
                    'actor_user_id' => $user->id,
                    'action' => 'company.self_registered',
                    'entity_type' => Company::class,
                    'entity_id' => $company->id,
                    'new_values' => [
                        'company_code' => $company->company_code,
                        'name' => $company->name,
                        'plan' => $plan->name,
                        'device_limit' => $plan->device_limit,
                        'price' => $plan->price,
                    ],
                    'remarks' => 'Self-serve customer plan purchase',
                    'ip_address' => $request->ip(),
                ]);

                $token = $user->createToken('auth-token')->plainTextToken;

                return [
                    'company' => $company,
                    'user' => $user,
                    'plan' => $plan,
                    'token' => $token,
                    'recharge' => $recharge,
                ];
            }

            return [
                'requires_payment' => true,
                'company_id' => $company->id,
                'subscription_id' => $sub['subscription_id'],
                'key_id' => $gateway->public_key,
                'amount' => $plan->price,
                'currency' => $plan->currency ?? 'INR',
                'name' => $validated['company_name'],
                'email' => $validated['owner_email'],
                'contact' => $validated['phone'],
            ];
        });

        if (isset($result['requires_payment'])) {
            return response()->json([
                'message' => 'Subscription created. Payment required to complete registration.',
                'data' => $result,
            ]);
        }

        return response()->json([
            'message' => 'Company registered and plan allocated successfully.',
            'data' => $result,
        ], 201);
    }

    public function verifyPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_subscription_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $company = Company::findOrFail($validated['company_id']);
        
        if ($company->status !== 'pending_payment') {
            return response()->json(['message' => 'Company is not pending payment.'], 400);
        }

        $gateway = \App\Models\PaymentGateway::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('is_enabled', true)
            ->where('is_default', true)
            ->first();

        if (!$gateway) {
            return response()->json(['message' => 'No enabled default payment gateway is configured.'], 422);
        }

        $provider = app(\App\Services\Payments\PaymentGatewayManager::class)->provider($gateway->provider);
        $isValid = $provider->verifySubscription($gateway, [
            'razorpay_payment_id' => $validated['razorpay_payment_id'],
            'razorpay_subscription_id' => $validated['razorpay_subscription_id'],
            'razorpay_signature' => $validated['razorpay_signature'],
        ]);

        if (!$isValid) {
            return response()->json(['message' => 'Invalid payment signature. Verification failed.'], 422);
        }

        $user = User::where('company_id', $company->id)->first();
        $plan = SubscriptionPlan::where('name', $company->plan)->first();

        DB::transaction(function () use ($company, $user, $plan, $validated) {
            $now = now();
            $settings = $company->settings ?? [];
            if (isset($settings['autopay'])) {
                $settings['autopay']['status'] = 'active';
            }

            $company->update([
                'status' => 'active',
                'activated_at' => $now,
                'settings' => $settings,
            ]);

            app(TenantContext::class)->run($company, function () use ($user): void {
                LockPolicy::create([
                    'name' => 'Default Company Lock Policy',
                    'is_default' => true,
                    'is_active' => true,
                    'lock_type' => 'full',
                    'grace_period_override' => 3,
                    'unlock_on_payment_clearance' => true,
                    'offline_behavior' => 'defer',
                    'created_by' => $user->id,
                ]);
                app(DeviceManagementSettingsService::class)->current();
            });

            $initialCharge = $plan->is_trial ? 1.00 : (float) $plan->price;
            $durationType = $plan->duration_type ?: 'months';
            $durationValue = (int) ($plan->duration_value ?: $plan->duration_months ?: 1);
            
            CompanyRecharge::create([
                'recharge_code' => 'RCH-' . strtoupper(Str::random(8)),
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'duration_months' => $plan->duration_months,
                'duration_type' => $durationType,
                'duration_value' => $durationValue,
                'device_limit' => $plan->device_limit,
                'amount' => $initialCharge,
                'currency' => $plan->currency ?? 'INR',
                'payment_method' => 'bank_autopay',
                'payment_reference' => $validated['razorpay_subscription_id'],
                'payment_status' => 'successful',
                'recharge_status' => 'active',
                'starts_at' => $now,
                'expires_at' => $company->expires_at,
                'notes' => "Bank AutoPay Mandate Authorized ({$validated['razorpay_subscription_id']}). Debit: ₹{$initialCharge}. Next Recurring Debit: ₹{$plan->price} on " . $company->expires_at->format('d M Y'),
                'created_by' => $user->id,
                'approved_at' => $now,
            ]);

            SystemAlert::create([
                'company_id' => 1,
                'deduplication_key' => 'new-company:' . $company->id,
                'alert_type' => 'company_registered',
                'severity' => 'low',
                'title' => 'New Company Registered (AutoPay): ' . $company->name,
                'message' => "{$company->name} ({$company->company_code}) activated {$plan->name} with AutoPay Mandate ({$validated['razorpay_subscription_id']}).",
                'entity_type' => 'company',
                'entity_id' => $company->id,
                'status' => 'open',
            ]);
        });

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Payment verified and company activated successfully.',
            'data' => [
                'company' => $company->fresh(),
                'user' => $user,
                'plan' => $plan,
                'token' => $token,
            ]
        ]);
    }
}
