<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyRecharge;
use App\Models\Device;
use App\Models\PaymentGateway;
use App\Models\PaymentWebhookLog;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\SubscriptionPlan;
use App\Models\SystemAlert;
use App\Models\User;
use App\Services\Payments\PaymentGatewayManager;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyRechargeService
{
    public function __construct(
        private readonly SubscriptionPlanService $planService,
        private readonly AuditService $audit,
        private readonly PaymentGatewayManager $gatewayManager
    ) {}

    public function getCurrentSubscription(Company $company): array
    {
        // 1. Auto-check & expire past recharges
        CompanyRecharge::query()
            ->where('company_id', $company->id)
            ->where('recharge_status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['recharge_status' => 'expired']);

        // 2. Auto-activate next queued plan if current subscription is expired
        $expiresAt = $company->expires_at;
        $isExpired = $expiresAt ? $expiresAt->isPast() : true;

        if ($isExpired) {
            $nextQueued = CompanyRecharge::query()
                ->where('company_id', $company->id)
                ->where('recharge_status', 'queued')
                ->where('payment_status', 'successful')
                ->oldest('id')
                ->first();

            if ($nextQueued) {
                $this->activateQueuedRecharge($nextQueued, null, true);
                $company->refresh();
                $expiresAt = $company->expires_at;
                $isExpired = $expiresAt ? $expiresAt->isPast() : true;
            }
        }

        $deviceCount = Device::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->count();

        $daysRemaining = ($expiresAt && !$isExpired) ? (int) ceil(now()->floatDiffInDays($expiresAt)) : 0;

        $latestRecharge = CompanyRecharge::query()
            ->where('company_id', $company->id)
            ->where('payment_status', 'successful')
            ->latest('id')
            ->first();

        $queuedRecharges = CompanyRecharge::query()
            ->where('company_id', $company->id)
            ->where('recharge_status', 'queued')
            ->where('payment_status', 'successful')
            ->oldest('id')
            ->get();

        $activeRecharges = CompanyRecharge::query()
            ->where('company_id', $company->id)
            ->where('recharge_status', 'active')
            ->latest('id')
            ->get();

        $pausedRecharges = CompanyRecharge::query()
            ->where('company_id', $company->id)
            ->where('recharge_status', 'paused')
            ->latest('id')
            ->get();

        $expiringAlert = [
            'show_popup' => ($isExpired || $daysRemaining <= 3),
            'days_remaining' => $daysRemaining,
            'is_expired' => $isExpired,
            'is_last_day' => (!$isExpired && $daysRemaining <= 1),
            'has_queued_plan' => $queuedRecharges->isNotEmpty(),
            'next_queued_plan' => $queuedRecharges->first(),
            'title' => $isExpired
                ? 'Subscription Expired!'
                : ($daysRemaining <= 1
                    ? '⚠️ Last Day of Plan Validity!'
                    : '🔔 Subscription Ending Soon'),
            'message' => $isExpired
                ? ($queuedRecharges->isNotEmpty()
                    ? "Your active plan has ended. Queued plan '{$queuedRecharges->first()->plan_name}' is ready to activate."
                    : "Your device management validity has ended. Recharge now to keep device locks active.")
                : ($daysRemaining <= 1
                    ? ($queuedRecharges->isNotEmpty()
                        ? "Today is the last day of your active plan! Your queued plan '{$queuedRecharges->first()->plan_name}' ({$queuedRecharges->first()->device_limit} devices) will auto-activate immediately upon expiry, or you can activate it right now."
                        : "Your plan expires today! Please recharge immediately to maintain uninterrupted EMI protection.")
                    : ($queuedRecharges->isNotEmpty()
                        ? "Your plan expires in {$daysRemaining} days. You have queued plan '{$queuedRecharges->first()->plan_name}' in reserve."
                        : "Your plan expires in {$daysRemaining} days. Top up early to avoid service interruption.")),
        ];

        return [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'company_code' => $company->company_code,
            'plan_name' => $company->plan ?? 'Free Trial',
            'subscription_status' => $company->subscription_status ?? ($isExpired ? 'expired' : 'active'),
            'device_usage' => $deviceCount,
            'max_devices' => (int) ($company->max_devices ?? 0),
            'device_capacity_percentage' => ($company->max_devices && $company->max_devices > 0)
                ? min(100, round(($deviceCount / $company->max_devices) * 100, 1))
                : 0,
            'expires_at' => $expiresAt?->toIso8601String(),
            'activated_at' => $company->activated_at?->toIso8601String(),
            'days_remaining' => $daysRemaining,
            'is_expired' => $isExpired,
            'platform_upi_id' => config('services.upi.vpa', 'emicontrol@upi'),
            'platform_upi_name' => config('services.upi.name', 'EMI Control Platform'),
            'latest_recharge' => $latestRecharge,
            'queued_recharges' => $queuedRecharges,
            'queued_recharges_count' => $queuedRecharges->count(),
            'active_recharges' => $activeRecharges,
            'active_recharges_count' => $activeRecharges->count(),
            'paused_recharges' => $pausedRecharges,
            'paused_recharges_count' => $pausedRecharges->count(),
            'expiring_alert' => $expiringAlert,
        ];
    }

    public function initiateShopOwnerRecharge(Company $company, User $actor, array $data): CompanyRecharge
    {
        return DB::transaction(function () use ($company, $actor, $data): CompanyRecharge {
            $plan = null;
            if (!empty($data['plan_id'])) {
                $plan = SubscriptionPlan::query()->where('is_active', true)->findOrFail($data['plan_id']);
                $planName = $plan->name;
                $durationMonths = $plan->duration_months;
                $deviceLimit = $plan->device_limit;
                $amount = $plan->price;
            } else {
                $durationMonths = (int) ($data['duration_months'] ?? 1);
                $deviceLimit = (int) ($data['device_limit'] ?? 25);
                $quote = $this->planService->calculateCustomQuote($durationMonths, $deviceLimit);
                $amount = $quote['total_price'];
                $planName = "Custom Plan ({$durationMonths}M / {$deviceLimit} Devices)";
            }

            $durationType = $plan ? ($plan->duration_type ?: 'months') : ($data['duration_type'] ?? 'months');
            $durationValue = $plan ? (int) ($plan->duration_value ?: $plan->duration_months ?: 1) : (int) ($data['duration_value'] ?? $durationMonths);

            $originalAmount = (float) $amount;
            $discountAmount = 0.0;
            $promoCodeModel = null;

            if (!empty($data['promo_code'])) {
                $codeStr = strtoupper(trim((string) $data['promo_code']));
                $promoCodeModel = PromoCode::where('code', $codeStr)->first();
                if ($promoCodeModel) {
                    $discountResult = $promoCodeModel->calculateDiscount($originalAmount, $plan?->id, $company->id);
                    $discountAmount = (float) $discountResult['discount_amount'];
                    $amount = (float) $discountResult['final_amount'];
                }
            }

            $paymentMethod = $data['payment_method'] ?? 'online_upi';
            $paymentRef = $data['payment_reference'] ?? null;
            $autoApprove = (bool) ($data['auto_approve'] ?? false);
            $activateNow = (bool) ($data['activate_now'] ?? $data['activate_immediately'] ?? false);

            $rechargeCode = $this->generateRechargeCode();

            $currentExpiresAt = $company->expires_at;
            $isCurrentlyActive = ($currentExpiresAt && $currentExpiresAt->isFuture() && $company->subscription_status === 'active');

            // Determine initial recharge_status
            if ($autoApprove) {
                if ($isCurrentlyActive && !$activateNow) {
                    $initialRechargeStatus = 'queued';
                } else {
                    $initialRechargeStatus = 'active';
                }
                $initialPaymentStatus = 'successful';
            } else {
                $initialRechargeStatus = 'pending_approval';
                $initialPaymentStatus = 'pending';
            }

            $recharge = CompanyRecharge::create([
                'recharge_code' => $rechargeCode,
                'company_id' => $company->id,
                'plan_id' => $plan?->id,
                'plan_name' => $planName,
                'duration_months' => $durationMonths,
                'duration_type' => $durationType,
                'duration_value' => $durationValue,
                'device_limit' => $deviceLimit,
                'amount' => $amount,
                'promo_code' => $promoCodeModel?->code,
                'discount_amount' => $discountAmount,
                'original_amount' => $promoCodeModel ? $originalAmount : null,
                'currency' => 'INR',
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentRef,
                'payment_status' => $initialPaymentStatus,
                'recharge_status' => $initialRechargeStatus,
                'notes' => $data['notes'] ?? 'Shop Owner Online Recharge',
                'created_by' => $actor->id,
            ]);

            if ($promoCodeModel && $discountAmount > 0) {
                $promoCodeModel->increment('times_used');
                PromoCodeUsage::create([
                    'promo_code_id' => $promoCodeModel->id,
                    'company_id' => $company->id,
                    'user_id' => $actor->id,
                    'company_recharge_id' => $recharge->id,
                    'original_amount' => $originalAmount,
                    'discount_amount' => $discountAmount,
                    'final_amount' => $amount,
                ]);
            }

            if ($autoApprove) {
                if ($initialRechargeStatus === 'active') {
                    $this->applyRechargeToCompany($recharge, $actor, $activateNow);
                } else {
                    SystemAlert::create([
                        'company_id' => $company->id,
                        'deduplication_key' => 'recharge_queued:' . $recharge->id,
                        'alert_type' => 'subscription_queued',
                        'severity' => 'low',
                        'title' => 'Recharge Queued - ' . $recharge->plan_name,
                        'message' => "Recharge {$recharge->recharge_code} of ₹" . number_format((float) $recharge->amount, 2) . " has been paid and queued. It will auto-activate when your current plan ends, or you can activate it anytime to stack quota & days.",
                        'entity_type' => 'subscription',
                        'entity_id' => $recharge->id,
                        'status' => 'open',
                    ]);
                }
            }

            $this->audit->record('company.recharge_initiated', $company, null, [
                'recharge_code' => $rechargeCode,
                'amount' => $amount,
                'duration_months' => $durationMonths,
                'device_limit' => $deviceLimit,
                'payment_method' => $paymentMethod,
                'status' => $initialRechargeStatus,
            ]);

            return $recharge->fresh();
        });
    }

    public function activateQueuedRecharge(CompanyRecharge $recharge, ?User $actor = null, bool $isAuto = false, string $mode = 'stack'): CompanyRecharge
    {
        return DB::transaction(function () use ($recharge, $actor, $isAuto, $mode): CompanyRecharge {
            if ($recharge->recharge_status !== 'queued' && $recharge->recharge_status !== 'pending_approval') {
                return $recharge;
            }

            $company = $recharge->company;
            $now = now();
            $currentExpiresAt = $company->expires_at;
            $isCurrentlyActive = ($currentExpiresAt && $currentExpiresAt->isFuture() && $company->subscription_status === 'active');

            $durationType = $recharge->duration_type ?: 'months';
            $durationValue = (int) ($recharge->duration_value ?: $recharge->duration_months ?: 1);

            if ($mode === 'switch' || $mode === 'replace') {
                // SWITCH PRIMARY PLAN:
                // Supersede / archive previous active plans and make this one the sole active plan
                CompanyRecharge::query()
                    ->where('company_id', $company->id)
                    ->where('id', '!=', $recharge->id)
                    ->where('recharge_status', 'active')
                    ->update([
                        'recharge_status' => 'superseded',
                    ]);

                $newExpiresAt = match ($durationType) {
                    'hours' => $now->copy()->addHours($durationValue),
                    'days' => $now->copy()->addDays($durationValue),
                    default => $now->copy()->addMonths($durationValue),
                };

                $recharge->update([
                    'starts_at' => $now,
                    'expires_at' => $newExpiresAt,
                    'recharge_status' => 'active',
                    'payment_status' => 'successful',
                    'approved_at' => $now,
                    'approved_by' => $actor?->id,
                ]);

                $company->update([
                    'plan' => $recharge->plan_name,
                    'max_devices' => $recharge->device_limit,
                    'subscription_status' => 'active',
                    'expires_at' => $newExpiresAt,
                    'activated_at' => $now,
                    'status' => 'active',
                    'suspended_at' => null,
                    'closed_at' => null,
                ]);

                SystemAlert::create([
                    'company_id' => $company->id,
                    'deduplication_key' => 'recharge_switched:' . $recharge->id,
                    'alert_type' => 'subscription_activated',
                    'severity' => 'low',
                    'title' => "Primary Plan Switched - {$recharge->plan_name}",
                    'message' => "Switched active subscription to {$recharge->plan_name} with {$recharge->device_limit} devices until " . $newExpiresAt->format('d M Y') . ".",
                    'entity_type' => 'subscription',
                    'entity_id' => $recharge->id,
                    'status' => 'open',
                ]);
            } elseif ($isCurrentlyActive) {
                // MID-TERM ACTIVATION (STACKING):
                // 1. Device quota PLUSES (stacks)
                $stackedDevices = (int) $company->max_devices + (int) $recharge->device_limit;

                // 2. Days / Validity PLUSES (extends from current expiry)
                $newExpiresAt = match ($durationType) {
                    'hours' => $currentExpiresAt->copy()->addHours($durationValue),
                    'days' => $currentExpiresAt->copy()->addDays($durationValue),
                    default => $currentExpiresAt->copy()->addMonths($durationValue),
                };

                $recharge->update([
                    'starts_at' => $now,
                    'expires_at' => $newExpiresAt,
                    'recharge_status' => 'active',
                    'payment_status' => 'successful',
                    'approved_at' => $now,
                    'approved_by' => $actor?->id,
                ]);

                $company->update([
                    'max_devices' => $stackedDevices,
                    'expires_at' => $newExpiresAt,
                    'subscription_status' => 'active',
                    'plan' => $company->plan ? "{$company->plan} + {$recharge->plan_name}" : $recharge->plan_name,
                ]);

                $actionType = $isAuto ? 'Auto-Activated' : 'Stacked & Activated';
                SystemAlert::create([
                    'company_id' => $company->id,
                    'deduplication_key' => 'recharge_stacked:' . $recharge->id,
                    'alert_type' => 'subscription_stacked',
                    'severity' => 'low',
                    'title' => "Recharge {$actionType} (Quota & Days Added)",
                    'message' => "Recharge {$recharge->recharge_code} is now active! Device quota increased to {$stackedDevices} devices (+{$recharge->device_limit}), and validity extended to " . $newExpiresAt->format('d M Y') . ".",
                    'entity_type' => 'subscription',
                    'entity_id' => $recharge->id,
                    'status' => 'open',
                ]);
            } else {
                // NORMAL ACTIVATION (Current plan expired or fresh)
                $newExpiresAt = match ($durationType) {
                    'hours' => $now->copy()->addHours($durationValue),
                    'days' => $now->copy()->addDays($durationValue),
                    default => $now->copy()->addMonths($durationValue),
                };

                $recharge->update([
                    'starts_at' => $now,
                    'expires_at' => $newExpiresAt,
                    'recharge_status' => 'active',
                    'payment_status' => 'successful',
                    'approved_at' => $now,
                    'approved_by' => $actor?->id,
                ]);

                $company->update([
                    'plan' => $recharge->plan_name,
                    'max_devices' => $recharge->device_limit,
                    'subscription_status' => 'active',
                    'expires_at' => $newExpiresAt,
                    'activated_at' => $company->activated_at ?? $now,
                    'status' => 'active',
                    'suspended_at' => null,
                    'closed_at' => null,
                ]);

                $actionType = $isAuto ? 'Auto-Activated (Queued Plan)' : 'Activated';
                SystemAlert::create([
                    'company_id' => $company->id,
                    'deduplication_key' => 'recharge_active:' . $recharge->id,
                    'alert_type' => 'subscription_activated',
                    'severity' => 'low',
                    'title' => "Recharge {$actionType} - {$recharge->plan_name}",
                    'message' => "Recharge {$recharge->recharge_code} is now active until " . $newExpiresAt->format('d M Y') . " with {$recharge->device_limit} devices quota.",
                    'entity_type' => 'subscription',
                    'entity_id' => $recharge->id,
                    'status' => 'open',
                ]);
            }

            $this->audit->record('company.recharge_activated', $company, null, [
                'recharge_code' => $recharge->recharge_code,
                'is_auto' => $isAuto,
                'mode' => $mode,
                'actor_id' => $actor?->id,
            ]);

            return $recharge->fresh();
        });
    }

    public function pauseOrDeactivateRecharge(CompanyRecharge $recharge, ?User $actor = null): CompanyRecharge
    {
        return DB::transaction(function () use ($recharge, $actor): CompanyRecharge {
            if ($recharge->recharge_status !== 'active') {
                return $recharge;
            }

            $company = $recharge->company;
            $now = now();

            $recharge->update([
                'recharge_status' => 'paused',
                'paused_at' => $now,
            ]);

            $hasOtherActive = CompanyRecharge::query()
                ->where('company_id', $company->id)
                ->where('recharge_status', 'active')
                ->exists();

            if (!$hasOtherActive) {
                $company->update([
                    'subscription_status' => 'paused',
                ]);
            }

            SystemAlert::create([
                'company_id' => $company->id,
                'deduplication_key' => 'recharge_paused:' . $recharge->id,
                'alert_type' => 'subscription_paused',
                'severity' => 'low',
                'title' => 'Subscription Plan Paused / Deactivated',
                'message' => "Recharge {$recharge->recharge_code} ({$recharge->plan_name}) has been temporarily paused.",
                'entity_type' => 'subscription',
                'entity_id' => $recharge->id,
                'status' => 'open',
            ]);

            $this->audit->record('company.recharge_paused', $company, null, [
                'recharge_code' => $recharge->recharge_code,
                'actor_id' => $actor?->id,
            ]);

            return $recharge->fresh();
        });
    }

    public function resumeRecharge(CompanyRecharge $recharge, ?User $actor = null): CompanyRecharge
    {
        return DB::transaction(function () use ($recharge, $actor): CompanyRecharge {
            if ($recharge->recharge_status !== 'paused') {
                return $recharge;
            }

            $company = $recharge->company;

            $recharge->update([
                'recharge_status' => 'active',
                'paused_at' => null,
            ]);

            $company->update([
                'subscription_status' => 'active',
                'plan' => $recharge->plan_name,
                'max_devices' => max((int) $company->max_devices, (int) $recharge->device_limit),
            ]);

            SystemAlert::create([
                'company_id' => $company->id,
                'deduplication_key' => 'recharge_resumed:' . $recharge->id,
                'alert_type' => 'subscription_resumed',
                'severity' => 'low',
                'title' => 'Subscription Plan Resumed',
                'message' => "Recharge {$recharge->recharge_code} ({$recharge->plan_name}) has been resumed and is now active.",
                'entity_type' => 'subscription',
                'entity_id' => $recharge->id,
                'status' => 'open',
            ]);

            $this->audit->record('company.recharge_resumed', $company, null, [
                'recharge_code' => $recharge->recharge_code,
                'actor_id' => $actor?->id,
            ]);

            return $recharge->fresh();
        });
    }

    public function requestTransferQueuedRecharge(CompanyRecharge $recharge, string $targetIdentifier, User $actor, ?string $notes = null): CompanyRecharge
    {
        return DB::transaction(function () use ($recharge, $targetIdentifier, $actor, $notes): CompanyRecharge {
            if ($recharge->recharge_status !== 'queued') {
                throw ValidationException::withMessages([
                    'recharge' => ['Only queued, unused recharge plans can be requested for transfer to another shop.'],
                ]);
            }

            $sourceCompany = $recharge->company;

            $targetCompany = Company::query()
                ->where('company_code', trim($targetIdentifier))
                ->orWhere('id', trim($targetIdentifier))
                ->first();

            if (!$targetCompany) {
                throw ValidationException::withMessages([
                    'target_company' => ["Shop with Company Code or ID '{$targetIdentifier}' was not found. Please verify the recipient code."],
                ]);
            }

            if ($targetCompany->id === $sourceCompany->id) {
                throw ValidationException::withMessages([
                    'target_company' => ['Cannot transfer a recharge voucher to your own shop.'],
                ]);
            }

            $now = now();

            // Mark recharge as pending transfer approval
            $recharge->update([
                'recharge_status' => 'transfer_pending',
                'transfer_status' => 'pending_approval',
                'transferred_to_company_id' => $targetCompany->id,
                'transfer_requested_at' => $now,
                'transfer_notes' => $notes,
            ]);

            // Notify System Admins
            SystemAlert::create([
                'company_id' => $sourceCompany->id,
                'deduplication_key' => 'voucher_transfer_requested:' . $recharge->id,
                'alert_type' => 'subscription_transfer_pending',
                'severity' => 'medium',
                'title' => 'Transfer Approval Requested',
                'message' => "Transfer of voucher {$recharge->recharge_code} to {$targetCompany->name} ({$targetCompany->company_code}) has been submitted and is awaiting System Admin approval.",
                'entity_type' => 'subscription',
                'entity_id' => $recharge->id,
                'status' => 'open',
            ]);

            $this->audit->record('company.recharge_transfer_requested', $sourceCompany, null, [
                'from_company' => $sourceCompany->company_code,
                'to_company' => $targetCompany->company_code,
                'recharge_code' => $recharge->recharge_code,
                'notes' => $notes,
                'actor_id' => $actor->id,
            ]);

            return $recharge->fresh();
        });
    }

    public function approveTransferQueuedRecharge(CompanyRecharge $recharge, User $admin): array
    {
        return DB::transaction(function () use ($recharge, $admin): array {
            $sourceCompany = $recharge->company;
            $targetCompany = $recharge->transferredToCompany;

            if (!$targetCompany) {
                throw ValidationException::withMessages([
                    'target_company' => ['Destination shop is not specified for this voucher transfer.'],
                ]);
            }

            $now = now();

            // 1. Mark source recharge as transferred
            $recharge->update([
                'recharge_status' => 'transferred',
                'transfer_status' => 'approved',
                'transferred_at' => $now,
                'transfer_approved_by' => $admin->id,
                'notes' => trim(($recharge->notes ? $recharge->notes . ' | ' : '') . "Transfer approved by System Admin ({$admin->name}) to {$targetCompany->name} ({$targetCompany->company_code}) on " . $now->format('d M Y')),
            ]);

            // 2. Create newly assigned queued voucher for target shop
            $newRecharge = CompanyRecharge::create([
                'recharge_code' => $this->generateRechargeCode(),
                'company_id' => $targetCompany->id,
                'plan_id' => $recharge->plan_id,
                'plan_name' => $recharge->plan_name,
                'duration_months' => $recharge->duration_months,
                'duration_type' => $recharge->duration_type ?: 'months',
                'duration_value' => $recharge->duration_value ?: $recharge->duration_months,
                'device_limit' => $recharge->device_limit,
                'amount' => $recharge->amount,
                'currency' => $recharge->currency,
                'payment_method' => 'voucher_transfer',
                'payment_reference' => 'TRANSFERRED_FROM_' . $recharge->recharge_code,
                'payment_status' => 'successful',
                'recharge_status' => 'queued',
                'transferred_from_company_id' => $sourceCompany->id,
                'transferred_at' => $now,
                'notes' => "Transferred voucher from {$sourceCompany->name} ({$sourceCompany->company_code}) - Approved by Admin",
                'created_by' => $admin->id,
            ]);

            SystemAlert::create([
                'company_id' => $sourceCompany->id,
                'deduplication_key' => 'voucher_transferred_out:' . $recharge->id,
                'alert_type' => 'subscription_transferred',
                'severity' => 'low',
                'title' => 'Recharge Plan Transferred Out (Admin Approved)',
                'message' => "Voucher {$recharge->recharge_code} ({$recharge->plan_name}) transfer to {$targetCompany->name} ({$targetCompany->company_code}) was approved by System Admin.",
                'entity_type' => 'subscription',
                'entity_id' => $recharge->id,
                'status' => 'open',
            ]);

            SystemAlert::create([
                'company_id' => $targetCompany->id,
                'deduplication_key' => 'voucher_transferred_in:' . $newRecharge->id,
                'alert_type' => 'subscription_transferred',
                'severity' => 'low',
                'title' => 'New Recharge Voucher Received!',
                'message' => "You received a queued recharge voucher {$newRecharge->recharge_code} ({$newRecharge->plan_name} - {$newRecharge->device_limit} devices) from {$sourceCompany->name}.",
                'entity_type' => 'subscription',
                'entity_id' => $newRecharge->id,
                'status' => 'open',
            ]);

            $this->audit->record('company.recharge_transfer_approved', $sourceCompany, null, [
                'from_company' => $sourceCompany->company_code,
                'to_company' => $targetCompany->company_code,
                'original_code' => $recharge->recharge_code,
                'new_code' => $newRecharge->recharge_code,
                'admin_id' => $admin->id,
            ]);

            return [
                'original_recharge' => $recharge->fresh(),
                'new_recharge' => $newRecharge->fresh(),
                'target_company' => [
                    'id' => $targetCompany->id,
                    'name' => $targetCompany->name,
                    'company_code' => $targetCompany->company_code,
                ],
            ];
        });
    }

    public function rejectTransferQueuedRecharge(CompanyRecharge $recharge, User $admin, string $reason): CompanyRecharge
    {
        return DB::transaction(function () use ($recharge, $admin, $reason): CompanyRecharge {
            $sourceCompany = $recharge->company;

            $recharge->update([
                'recharge_status' => 'queued',
                'transfer_status' => 'rejected',
                'transfer_rejection_reason' => $reason,
                'transferred_to_company_id' => null,
            ]);

            SystemAlert::create([
                'company_id' => $sourceCompany->id,
                'deduplication_key' => 'voucher_transfer_rejected:' . $recharge->id,
                'alert_type' => 'subscription_transfer_rejected',
                'severity' => 'medium',
                'title' => 'Voucher Transfer Request Rejected',
                'message' => "Transfer request for voucher {$recharge->recharge_code} was rejected by System Admin. Reason: {$reason}",
                'entity_type' => 'subscription',
                'entity_id' => $recharge->id,
                'status' => 'open',
            ]);

            $this->audit->record('company.recharge_transfer_rejected', $sourceCompany, null, [
                'recharge_code' => $recharge->recharge_code,
                'admin_id' => $admin->id,
                'reason' => $reason,
            ]);

            return $recharge->fresh();
        });
    }

    public function transferQueuedRecharge(CompanyRecharge $recharge, string $targetIdentifier, User $actor, ?string $notes = null): array
    {
        // If actor is platform admin, directly approve transfer; otherwise request it
        if ($actor->is_platform_admin || $actor->hasRole('Super Admin')) {
            $this->requestTransferQueuedRecharge($recharge, $targetIdentifier, $actor, $notes);
            return $this->approveTransferQueuedRecharge($recharge->fresh(), $actor);
        }

        $pending = $this->requestTransferQueuedRecharge($recharge, $targetIdentifier, $actor, $notes);
        return [
            'original_recharge' => $pending,
            'new_recharge' => null,
            'message' => 'Voucher transfer request submitted. Awaiting System Admin approval.',
        ];
    }

    public function approveRecharge(CompanyRecharge $recharge, User $admin): CompanyRecharge
    {
        return DB::transaction(function () use ($recharge, $admin): CompanyRecharge {
            if ($recharge->recharge_status === 'active' && $recharge->payment_status === 'successful') {
                return $recharge;
            }

            $company = $recharge->company;
            $currentExpiresAt = $company->expires_at;
            $isCurrentlyActive = ($currentExpiresAt && $currentExpiresAt->isFuture() && $company->subscription_status === 'active');

            if ($isCurrentlyActive) {
                // If company is currently active, approve it into QUEUED status (ready for auto or manual activation)
                $recharge->update([
                    'payment_status' => 'successful',
                    'recharge_status' => 'queued',
                    'approved_by' => $admin->id,
                    'approved_at' => now(),
                ]);

                SystemAlert::create([
                    'company_id' => $company->id,
                    'deduplication_key' => 'recharge_approved_queued:' . $recharge->id,
                    'alert_type' => 'subscription_queued',
                    'severity' => 'low',
                    'title' => 'Recharge Approved & Queued',
                    'message' => "Recharge {$recharge->recharge_code} has been approved and placed in queue. It will auto-activate when the current plan finishes, or you can activate it anytime.",
                    'entity_type' => 'subscription',
                    'entity_id' => $recharge->id,
                    'status' => 'open',
                ]);
            } else {
                $recharge->update([
                    'payment_status' => 'successful',
                    'recharge_status' => 'active',
                    'approved_by' => $admin->id,
                    'approved_at' => now(),
                ]);

                $this->applyRechargeToCompany($recharge, $admin, false);
            }

            $this->audit->record('company.recharge_approved', $company, null, [
                'recharge_code' => $recharge->recharge_code,
                'approved_by' => $admin->id,
            ]);

            return $recharge->fresh();
        });
    }

    public function rejectRecharge(CompanyRecharge $recharge, User $admin, string $reason): CompanyRecharge
    {
        $recharge->update([
            'payment_status' => 'failed',
            'recharge_status' => 'rejected',
            'notes' => trim(($recharge->notes ? $recharge->notes . " | " : "") . "Rejected: {$reason}"),
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        $this->audit->record('company.recharge_rejected', $recharge->company, null, [
            'recharge_code' => $recharge->recharge_code,
            'reason' => $reason,
        ]);

        return $recharge->fresh();
    }

    public function refundRecharge(CompanyRecharge $recharge, ?User $admin = null, ?string $reason = null): array
    {
        return DB::transaction(function () use ($recharge, $admin, $reason): array {
            // 1. Authorization: Only System Admin can revert
            if ($admin && !$admin->is_platform_admin && !$admin->hasRole('Super Admin')) {
                throw ValidationException::withMessages([
                    'recharge' => ['Unauthorized. Only System Admins can revert subscriptions and process refunds.'],
                ]);
            }

            // 2. 6-Day Window Check
            $purchaseDate = $recharge->created_at ?? $recharge->approved_at;
            if ($purchaseDate && $purchaseDate->copy()->addDays(6)->isPast()) {
                throw ValidationException::withMessages([
                    'recharge' => ['Revert window expired. Subscriptions can only be reverted within 6 days of purchase.'],
                ]);
            }

            $company = $recharge->company;

            // 3. Usage Check: If subscription is active, ensure devices have not utilized the quota beyond remaining limit
            if ($recharge->recharge_status === 'active') {
                $currentUsage = (int) Device::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->whereNull('released_at')
                    ->count();
                $remainingMax = max(0, (int) $company->max_devices - (int) $recharge->device_limit);
                if ($currentUsage > $remainingMax && $currentUsage > 0) {
                    throw ValidationException::withMessages([
                        'recharge' => ["Cannot revert subscription: Plan quota is actively utilized by {$currentUsage} enrolled devices. Remaining capacity after revert would only be {$remainingMax}."],
                    ]);
                }
            }

            $gatewayRefund = null;

            // 4. If payment reference exists and starts with 'pay_', call Razorpay refund API for 100% refund
            if (!empty($recharge->payment_reference) && str_starts_with($recharge->payment_reference, 'pay_')) {
                $gateway = PaymentGateway::withoutGlobalScopes()
                    ->where('is_enabled', true)
                    ->where('provider', 'razorpay')
                    ->first();

                if ($gateway) {
                    try {
                        $provider = $this->gatewayManager->provider('razorpay');
                        $gatewayRefund = $provider->refundPayment(
                            $gateway,
                            $recharge->payment_reference,
                            (float) $recharge->amount,
                            ['recharge_code' => $recharge->recharge_code, 'reason' => $reason ?? 'System Admin Revert & 100% Refund']
                        );
                    } catch (\Throwable $e) {
                        // Log gateway error but continue internal revert
                    }
                }
            }

            $wasActive = ($recharge->recharge_status === 'active');
            $now = now();

            // 5. Mark recharge as refunded and reverted
            $recharge->update([
                'payment_status' => 'refunded',
                'recharge_status' => 'refunded',
                'reverted_at' => $now,
                'reverted_by' => $admin?->id,
                'revert_reason' => $reason,
                'notes' => trim(($recharge->notes ? $recharge->notes . " | " : "") . "Reverted by System Admin: " . ($reason ?? '100% Refund processed')),
            ]);

            // 6. Immediate revocation of subscription access & modules
            if ($wasActive) {
                $newMaxDevices = max(0, (int) $company->max_devices - (int) $recharge->device_limit);

                // Check if any other active recharge exists
                $remainingActive = CompanyRecharge::query()
                    ->where('company_id', $company->id)
                    ->where('recharge_status', 'active')
                    ->where('id', '!=', $recharge->id)
                    ->latest('expires_at')
                    ->first();

                if ($remainingActive && $remainingActive->expires_at && $remainingActive->expires_at->isFuture()) {
                    $company->update([
                        'max_devices' => $newMaxDevices,
                        'expires_at' => $remainingActive->expires_at,
                        'plan' => $remainingActive->plan_name,
                    ]);
                } else {
                    // Revoke active subscription: company credentials remain intact for login & viewing, but active modules are disabled
                    $company->update([
                        'max_devices' => $newMaxDevices,
                        'subscription_status' => 'expired',
                        'expires_at' => $now,
                    ]);
                }
            }

            // 7. Record Webhook Log for the refund event
            PaymentWebhookLog::create([
                'provider' => 'razorpay',
                'event' => 'refund.processed',
                'payment_id' => $recharge->payment_reference,
                'order_id' => $gatewayRefund['id'] ?? null,
                'payload' => [
                    'event' => 'refund.processed',
                    'recharge_code' => $recharge->recharge_code,
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'company_code' => $company->company_code,
                    'amount' => (float) $recharge->amount,
                    'currency' => $recharge->currency ?? 'INR',
                    'reason' => $reason,
                    'admin_id' => $admin?->id,
                    'reverted_at' => $now->toIso8601String(),
                    'gateway_refund' => $gatewayRefund,
                ],
                'status' => 'processed',
            ]);

            SystemAlert::create([
                'company_id' => $company->id,
                'deduplication_key' => 'recharge_refunded:' . $recharge->id,
                'alert_type' => 'subscription_refunded',
                'severity' => 'high',
                'title' => 'Subscription Reverted & 100% Refunded',
                'message' => "Recharge {$recharge->recharge_code} of ₹" . number_format((float) $recharge->amount, 2) . " has been reverted by System Admin. Subscription quota has been revoked.",
                'entity_type' => 'subscription',
                'entity_id' => $recharge->id,
                'status' => 'open',
            ]);

            $this->audit->record('company.recharge_refunded', $company, null, [
                'recharge_code' => $recharge->recharge_code,
                'amount' => $recharge->amount,
                'reason' => $reason,
                'admin_id' => $admin?->id,
                'gateway_refund' => $gatewayRefund,
            ]);

            return [
                'success' => true,
                'message' => "Recharge {$recharge->recharge_code} has been successfully reverted and 100% refund processed.",
                'recharge' => $recharge->fresh(),
                'gateway_refund' => $gatewayRefund,
                'subscription' => $this->getCurrentSubscription($company),
            ];
        });
    }

    public function grantSubscriptionByAdmin(Company $company, User $admin, array $data): CompanyRecharge
    {
        return DB::transaction(function () use ($company, $admin, $data): CompanyRecharge {
            $durationMonths = (int) ($data['duration_months'] ?? 1);
            $deviceLimit = (int) ($data['device_limit'] ?? 50);
            $amount = (float) ($data['amount'] ?? 0.00);
            $planName = $data['plan_name'] ?? "Admin Direct Grant ({$durationMonths}M / {$deviceLimit} Devices)";
            $activateNow = (bool) ($data['activate_now'] ?? true);

            $recharge = CompanyRecharge::create([
                'recharge_code' => $this->generateRechargeCode(),
                'company_id' => $company->id,
                'plan_id' => null,
                'plan_name' => $planName,
                'duration_months' => $durationMonths,
                'duration_type' => 'months',
                'duration_value' => $durationMonths,
                'device_limit' => $deviceLimit,
                'amount' => $amount,
                'currency' => 'INR',
                'payment_method' => 'admin_grant',
                'payment_reference' => $data['reference'] ?? 'ADMIN-GRANT-' . strtoupper(bin2hex(random_bytes(3))),
                'payment_status' => 'successful',
                'recharge_status' => 'active',
                'notes' => $data['notes'] ?? 'Granted directly by platform super admin.',
                'created_by' => $admin->id,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);

            $this->applyRechargeToCompany($recharge, $admin, $activateNow);

            $this->audit->record('company.subscription_granted', $company, null, [
                'duration_months' => $durationMonths,
                'device_limit' => $deviceLimit,
                'admin_id' => $admin->id,
            ]);

            return $recharge->fresh();
        });
    }

    public function applyRechargeToCompany(CompanyRecharge $recharge, User $actor, bool $isStacking = false): void
    {
        $company = $recharge->company;
        $currentExpiresAt = $company->expires_at;
        $now = now();
        $isCurrentlyActive = ($currentExpiresAt && $currentExpiresAt->isFuture() && $company->subscription_status === 'active');

        $durationType = $recharge->duration_type ?: 'months';
        $durationValue = (int) ($recharge->duration_value ?: $recharge->duration_months ?: 1);

        if ($isCurrentlyActive && $isStacking) {
            // Stacking devices and extending expiry
            $baseDate = $currentExpiresAt->copy();
            $newExpiresAt = match ($durationType) {
                'hours' => $baseDate->addHours($durationValue),
                'days' => $baseDate->addDays($durationValue),
                default => $baseDate->addMonths($durationValue),
            };
            $newMaxDevices = (int) $company->max_devices + (int) $recharge->device_limit;

            $recharge->update([
                'starts_at' => $now,
                'expires_at' => $newExpiresAt,
                'recharge_status' => 'active',
            ]);

            $company->update([
                'plan' => "{$company->plan} + {$recharge->plan_name}",
                'max_devices' => $newMaxDevices,
                'subscription_status' => 'active',
                'expires_at' => $newExpiresAt,
                'status' => 'active',
                'suspended_at' => null,
                'closed_at' => null,
            ]);
        } else {
            $baseDate = ($isCurrentlyActive) ? $currentExpiresAt->copy() : $now->copy();
            $newExpiresAt = match ($durationType) {
                'hours' => $baseDate->addHours($durationValue),
                'days' => $baseDate->addDays($durationValue),
                default => $baseDate->addMonths($durationValue),
            };

            $recharge->update([
                'starts_at' => ($isCurrentlyActive) ? $currentExpiresAt : $now,
                'expires_at' => $newExpiresAt,
                'recharge_status' => 'active',
            ]);

            $company->update([
                'plan' => $recharge->plan_name,
                'max_devices' => $recharge->device_limit,
                'subscription_status' => 'active',
                'expires_at' => $newExpiresAt,
                'activated_at' => $company->activated_at ?? $now,
                'status' => 'active',
                'suspended_at' => null,
                'closed_at' => null,
            ]);
        }

        SystemAlert::create([
            'company_id' => $company->id,
            'deduplication_key' => 'recharge:' . $recharge->id,
            'alert_type' => 'subscription_recharged',
            'severity' => 'low',
            'title' => 'Recharge Successful - ' . $recharge->plan_name,
            'message' => "Recharge {$recharge->recharge_code} of ₹" . number_format((float) $recharge->amount, 2) . " active. Validity extended to " . $newExpiresAt->format('d M Y') . " ({$company->max_devices} devices quota).",
            'entity_type' => 'subscription',
            'entity_id' => $recharge->id,
            'status' => 'open',
        ]);
    }

    public function checkAndAutoActivateQueuedPlans(): int
    {
        $expiredCompanies = Company::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereHas('recharges', function ($q) {
                $q->where('recharge_status', 'queued')->where('payment_status', 'successful');
            })
            ->get();

        $activatedCount = 0;
        foreach ($expiredCompanies as $company) {
            $nextQueued = CompanyRecharge::query()
                ->where('company_id', $company->id)
                ->where('recharge_status', 'queued')
                ->where('payment_status', 'successful')
                ->oldest('id')
                ->first();

            if ($nextQueued) {
                $this->activateQueuedRecharge($nextQueued, null, true);
                $activatedCount++;
            }
        }

        return $activatedCount;
    }

    public function getCompanyHistory(Company $company, int $perPage = 15): LengthAwarePaginator
    {
        return CompanyRecharge::query()
            ->where('company_id', $company->id)
            ->latest('id')
            ->paginate($perPage);
    }

    public function getAllRecharges(int $perPage = 20, ?int $companyId = null, ?string $status = null, ?string $search = null): LengthAwarePaginator
    {
        $query = CompanyRecharge::query()->with(['company', 'creator', 'approver']);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($status) {
            $query->where('recharge_status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search): void {
                $q->where('recharge_code', 'like', "%{$search}%")
                    ->orWhere('payment_reference', 'like', "%{$search}%")
                    ->orWhere('plan_name', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('company_code', 'like', "%{$search}%"));
            });
        }

        return $query->latest('id')->paginate($perPage);
    }

    private function generateRechargeCode(): string
    {
        $id = (int) CompanyRecharge::max('id') + 1;
        return 'RCH-' . date('Ym') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }
}
