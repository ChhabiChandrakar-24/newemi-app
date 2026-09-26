<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CompanyRecharge;
use App\Models\PaymentGateway;
use App\Models\PromoCode;
use App\Models\SubscriptionPlan;
use App\Services\CompanyRechargeService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\SubscriptionPlanService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionPlanService $planService,
        private readonly CompanyRechargeService $rechargeService,
        private readonly PaymentGatewayManager $gatewayManager
    ) {}

    public function current(): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company, 404, 'Company context not found.');

        return response()->json($this->rechargeService->getCurrentSubscription($company));
    }

    public function plans(): JsonResponse
    {
        return response()->json([
            'plans' => $this->planService->getActivePlans(),
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'duration_months' => ['required', 'integer', 'min:1', 'max:36'],
            'device_limit' => ['required', 'integer', 'min:5', 'max:5000'],
        ]);

        $quote = $this->planService->calculateCustomQuote(
            (int) $validated['duration_months'],
            (int) $validated['device_limit']
        );

        return response()->json($quote);
    }

    public function applyPromo(Request $request): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company, 404, 'Company context not found.');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'device_limit' => ['nullable', 'integer', 'min:5', 'max:5000'],
        ]);

        $code = strtoupper(trim($validated['code']));
        $promo = PromoCode::where('code', $code)->first();

        if (! $promo) {
            return response()->json([
                'valid' => false,
                'message' => "Promo code '{$code}' not found or does not exist.",
                'errors' => ['code' => ["Promo code '{$code}' not found."]],
            ], 404);
        }

        $amount = 0.0;
        if (!empty($validated['plan_id'])) {
            $plan = SubscriptionPlan::where('is_active', true)->findOrFail($validated['plan_id']);
            $amount = (float) $plan->price;
        } elseif (!empty($validated['duration_months']) && !empty($validated['device_limit'])) {
            $quote = $this->planService->calculateCustomQuote(
                (int) $validated['duration_months'],
                (int) $validated['device_limit']
            );
            $amount = (float) $quote['total_price'];
        } else {
            return response()->json([
                'valid' => false,
                'message' => 'Please select a plan or configure custom devices & duration first.',
            ], 422);
        }

        try {
            $result = $promo->calculateDiscount($amount, $validated['plan_id'] ?? null, $company->id);
            return response()->json($result);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'valid' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Promo code cannot be applied.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function recharge(Request $request): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company, 404, 'Company context not found.');

        $validated = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'device_limit' => ['nullable', 'integer', 'min:5', 'max:5000'],
            'payment_method' => ['required', 'string', Rule::in(['razorpay', 'razorpay_autopay', 'bank_autopay', 'online_upi', 'upi', 'bank_transfer', 'cash', 'card', 'qr_code'])],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'promo_code' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
            'auto_approve' => ['nullable', 'boolean'],
        ]);

        if (empty($validated['plan_id']) && (empty($validated['duration_months']) || empty($validated['device_limit']))) {
            return response()->json([
                'message' => 'Either choose a subscription plan or specify duration_months and device_limit.',
                'errors' => ['plan_id' => ['Plan selection or custom duration & device limit is required.']],
            ], 422);
        }

        // In demo / test environments, simulated instant payments auto-approve; online methods can also auto-approve
        $autoApprove = $request->boolean('auto_approve', true);
        $validated['auto_approve'] = $autoApprove;

        $recharge = $this->rechargeService->initiateShopOwnerRecharge($company, $request->user(), $validated);

        return response()->json([
            'message' => $autoApprove ? 'Recharge successful and plan activated!' : 'Recharge submitted successfully. Awaiting platform verification.',
            'recharge' => $recharge,
            'subscription' => $this->rechargeService->getCurrentSubscription($company),
        ], 201);
    }

    public function createOrder(Request $request): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company, 404, 'Company context not found.');

        $validated = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'device_limit' => ['nullable', 'integer', 'min:5', 'max:5000'],
            'promo_code' => ['nullable', 'string', 'max:50'],
            'is_autopay' => ['nullable', 'boolean'],
        ]);

        if (empty($validated['plan_id']) && (empty($validated['duration_months']) || empty($validated['device_limit']))) {
            return response()->json([
                'message' => 'Either choose a subscription plan or specify custom duration & device limit.',
            ], 422);
        }

        $plan = null;
        if (!empty($validated['plan_id'])) {
            $plan = SubscriptionPlan::query()->where('is_active', true)->findOrFail($validated['plan_id']);
            $amount = (float) $plan->price;
            $planName = $plan->name;
            $durationMonths = $plan->duration_months;
            $deviceLimit = $plan->device_limit;
        } else {
            $durationMonths = (int) $validated['duration_months'];
            $deviceLimit = (int) $validated['device_limit'];
            $quote = $this->planService->calculateCustomQuote($durationMonths, $deviceLimit);
            $amount = (float) $quote['total_price'];
            $planName = "Custom Plan ({$durationMonths}M / {$deviceLimit} Devices)";
        }

        $discountAmount = 0.0;
        $promoCodeStr = null;
        if (!empty($validated['promo_code'])) {
            $codeStr = strtoupper(trim($validated['promo_code']));
            $promo = PromoCode::where('code', $codeStr)->first();
            if ($promo) {
                try {
                    $discountRes = $promo->calculateDiscount($amount, $plan?->id, $company->id);
                    $discountAmount = (float) $discountRes['discount_amount'];
                    $amount = (float) $discountRes['final_amount'];
                    $promoCodeStr = $promo->code;
                } catch (\Illuminate\Validation\ValidationException $e) {
                    return response()->json([
                        'message' => collect($e->errors())->flatten()->first() ?: 'Promo code cannot be applied.',
                    ], 422);
                }
            }
        }

        $isAutopay = $request->boolean('is_autopay', false);

        $gateway = PaymentGateway::withoutGlobalScopes()
            ->where('is_enabled', true)
            ->where('provider', 'razorpay')
            ->where('is_default', true)
            ->first()
            ?? PaymentGateway::withoutGlobalScopes()
            ->where('is_enabled', true)
            ->where('provider', 'razorpay')
            ->first();

        if (! $gateway) {
            return response()->json([
                'message' => 'No enabled Razorpay gateway found. Please configure Razorpay in Payment Gateways.'
            ], 422);
        }

        try {
            $provider = $this->gatewayManager->provider('razorpay');
            $receipt = 'sub_' . $company->id . '_' . time();
            $order = $provider->createPaymentOrder($gateway, [
                'amount' => $amount,
                'currency' => 'INR',
                'receipt' => $receipt,
                'purpose' => ($isAutopay ? 'AutoPay Subscription - ' : 'Plan Subscription - ') . $planName . ($promoCodeStr ? " (Promo: {$promoCodeStr})" : ''),
                'notes' => [
                    'company_id' => (string) $company->id,
                    'company_name' => (string) $company->name,
                    'plan_id' => (string) ($plan?->id ?? ''),
                    'plan_name' => $planName,
                    'promo_code' => (string) ($promoCodeStr ?? ''),
                    'discount_amount' => (string) $discountAmount,
                    'is_autopay' => $isAutopay ? '1' : '0',
                    'duration_months' => (string) $durationMonths,
                    'device_limit' => (string) $deviceLimit,
                ],
            ]);

            return response()->json([
                'success' => true,
                'order_id' => $order['order_id'],
                'amount' => $amount,
                'amount_paise' => (int) round($amount * 100),
                'discount_amount' => $discountAmount,
                'promo_code' => $promoCodeStr,
                'currency' => 'INR',
                'key_id' => $gateway->public_key,
                'gateway_provider' => 'razorpay',
                'display_name' => $gateway->display_name,
                'plan_name' => $planName,
                'is_autopay' => $isAutopay,
                'company' => [
                    'name' => $company->name,
                    'code' => $company->company_code,
                    'email' => $request->user()?->email ?? $company->email,
                    'mobile' => $request->user()?->mobile ?? $company->phone,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create Razorpay subscription order: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function verifyPayment(Request $request): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company, 404, 'Company context not found.');

        $validated = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'device_limit' => ['nullable', 'integer', 'min:5', 'max:5000'],
            'promo_code' => ['nullable', 'string', 'max:50'],
            'is_autopay' => ['nullable', 'boolean'],
        ]);

        $gateway = PaymentGateway::withoutGlobalScopes()
            ->where('is_enabled', true)
            ->where('provider', 'razorpay')
            ->where('is_default', true)
            ->first()
            ?? PaymentGateway::withoutGlobalScopes()
            ->where('is_enabled', true)
            ->where('provider', 'razorpay')
            ->first();

        if (! $gateway) {
            return response()->json(['message' => 'No active Razorpay payment gateway found.'], 422);
        }

        $provider = $this->gatewayManager->provider('razorpay');
        $isValid = $provider->verifyPayment($gateway, [
            'razorpay_order_id' => $validated['razorpay_order_id'],
            'razorpay_payment_id' => $validated['razorpay_payment_id'],
            'razorpay_signature' => $validated['razorpay_signature'],
        ]);

        if (! $isValid) {
            return response()->json(['message' => 'Razorpay payment signature verification failed. Untrusted payment.'], 422);
        }

        $isAutopay = (bool) ($validated['is_autopay'] ?? false);
        $method = $isAutopay ? 'razorpay_autopay' : 'razorpay';
        $notePrefix = $isAutopay ? 'Razorpay AutoPay Mandate Verified' : 'Razorpay Verified Payment';

        $rechargeData = [
            'plan_id' => $validated['plan_id'] ?? null,
            'duration_months' => $validated['duration_months'] ?? null,
            'device_limit' => $validated['device_limit'] ?? null,
            'promo_code' => $validated['promo_code'] ?? null,
            'payment_method' => $method,
            'payment_reference' => $validated['razorpay_payment_id'],
            'notes' => "{$notePrefix}. Order ID: {$validated['razorpay_order_id']}, Payment ID: {$validated['razorpay_payment_id']}",
            'auto_approve' => true,
        ];

        $recharge = $this->rechargeService->initiateShopOwnerRecharge($company, $request->user(), $rechargeData);

        return response()->json([
            'success' => true,
            'message' => $isAutopay
                ? 'Razorpay AutoPay mandate activated & plan renewed successfully!'
                : 'Payment verified via Razorpay! Your plan has been activated.',
            'recharge' => $recharge,
            'subscription' => $this->rechargeService->getCurrentSubscription($company),
        ], 200);
    }

    public function history(Request $request): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company, 404, 'Company context not found.');

        $perPage = min($request->integer('per_page', 15), 100);
        $history = $this->rechargeService->getCompanyHistory($company, $perPage);

        return response()->json($history);
    }

    public function receipt(CompanyRecharge $recharge): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company || $recharge->company_id !== $company->id, 403, 'Unauthorized access to this recharge receipt.');

        return response()->json([
            'receipt' => $recharge->load(['company', 'creator', 'approver']),
        ]);
    }

    public function sendNotification(Request $request, CompanyRecharge $recharge): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company || $recharge->company_id !== $company->id, 403, 'Unauthorized access to this recharge.');

        $channel = $request->string('channel', 'sms')->value();
        $recipient = $request->string('recipient')->value() ?: ($channel === 'email' ? ($company->email ?? 'admin@shop.com') : ($company->phone ?? 'Registered Mobile'));

        return response()->json([
            'message' => "Recharge confirmation voucher successfully sent via " . strtoupper($channel) . " to {$recipient}.",
            'channel' => $channel,
            'recipient' => $recipient,
            'sent_at' => now()->toIso8601String(),
        ]);
    }

    public function activate(CompanyRecharge $recharge, Request $request): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company || $recharge->company_id !== $company->id, 403, 'Unauthorized access to this recharge.');

        if ($recharge->recharge_status !== 'queued' && $recharge->recharge_status !== 'pending_approval') {
            return response()->json([
                'message' => "Recharge is already {$recharge->recharge_status} and cannot be activated.",
            ], 422);
        }

        $mode = $request->string('mode', 'stack')->value();
        if (!in_array($mode, ['stack', 'switch', 'replace'], true)) {
            $mode = 'stack';
        }

        $activated = $this->rechargeService->activateQueuedRecharge($recharge, $request->user(), false, $mode);

        $msg = $mode === 'switch'
            ? "Primary subscription switched to {$recharge->plan_name}! Plan is now actively running."
            : "Recharge {$recharge->recharge_code} activated successfully! Quota and validity have been stacked to your account.";

        return response()->json([
            'success' => true,
            'message' => $msg,
            'recharge' => $activated,
            'subscription' => $this->rechargeService->getCurrentSubscription($company),
        ]);
    }

    public function pause(CompanyRecharge $recharge, Request $request): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company || $recharge->company_id !== $company->id, 403, 'Unauthorized access to this recharge.');

        if ($recharge->recharge_status !== 'active') {
            return response()->json(['message' => 'Only active recharge plans can be paused.'], 422);
        }

        $paused = $this->rechargeService->pauseOrDeactivateRecharge($recharge, $request->user());

        return response()->json([
            'success' => true,
            'message' => "Plan {$recharge->plan_name} paused successfully. You can resume it anytime.",
            'recharge' => $paused,
            'subscription' => $this->rechargeService->getCurrentSubscription($company),
        ]);
    }

    public function resume(CompanyRecharge $recharge, Request $request): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company || $recharge->company_id !== $company->id, 403, 'Unauthorized access to this recharge.');

        if ($recharge->recharge_status !== 'paused') {
            return response()->json(['message' => 'Only paused recharge plans can be resumed.'], 422);
        }

        $resumed = $this->rechargeService->resumeRecharge($recharge, $request->user());

        return response()->json([
            'success' => true,
            'message' => "Plan {$recharge->plan_name} resumed and is now active!",
            'recharge' => $resumed,
            'subscription' => $this->rechargeService->getCurrentSubscription($company),
        ]);
    }

    public function transfer(CompanyRecharge $recharge, Request $request): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        abort_if(!$company || $recharge->company_id !== $company->id, 403, 'Unauthorized access to this recharge.');

        $validated = $request->validate([
            'target_company' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->rechargeService->transferQueuedRecharge(
            $recharge,
            $validated['target_company'],
            $request->user(),
            $validated['notes'] ?? null
        );

        $message = $result['message'] ?? (
            isset($result['target_company'])
                ? "Recharge voucher {$recharge->recharge_code} transferred successfully to {$result['target_company']['name']} ({$result['target_company']['company_code']})!"
                : "Transfer request for voucher {$recharge->recharge_code} has been submitted. It will be transferred once approved by System Admin."
        );

        return response()->json([
            'success' => true,
            'message' => $message,
            'transfer' => $result,
            'subscription' => $this->rechargeService->getCurrentSubscription($company),
        ]);
    }

    public function refund(Request $request, CompanyRecharge $recharge): JsonResponse
    {
        abort_unless(
            $request->user()?->is_platform_admin || $request->user()?->hasRole('Super Admin'),
            403,
            'Unauthorized. Revert/Refund options are strictly restricted to System Admins.'
        );

        $company = app(TenantContext::class)->company();
        abort_if(!$company || $recharge->company_id !== $company->id, 403, 'Unauthorized access to this recharge.');

        if ($recharge->payment_status === 'refunded') {
            return response()->json(['message' => 'This recharge has already been refunded.'], 422);
        }

        $reason = $request->string('reason', 'Requested by system administrator')->value();
        $result = $this->rechargeService->refundRecharge($recharge, $request->user(), $reason);

        return response()->json($result);
    }

    public function webhookLogs(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->is_platform_admin || $request->user()?->hasRole('Super Admin'),
            403,
            'Unauthorized. Razorpay Webhook logs are strictly restricted to System Admins.'
        );

        $perPage = min($request->integer('per_page', 20), 100);
        $logs = \App\Models\PaymentWebhookLog::query()
            ->latest('id')
            ->paginate($perPage);

        return response()->json($logs);
    }
}
