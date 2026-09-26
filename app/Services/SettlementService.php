<?php

namespace App\Services;

use App\Models\EmiAccount;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettlementService
{
    public const MAX_DISCOUNT_PERCENT = 50;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentAllocationService $allocations,
        private readonly EmiOverdueService $overdue,
        private readonly AuditService $audit,
        private readonly PaymentDeviceUnlockService $deviceUnlocks,
    ) {}

    public function preview(EmiAccount $account, string $discountAmount, User $actor): array
    {
        $outstanding = Money::toPaise($account->outstanding_amount);
        $discount = Money::toPaise($discountAmount);
        $this->validateDiscount($outstanding, $discount, $actor);

        return [
            'current_outstanding_amount' => Money::fromPaise($outstanding),
            'overdue_amount' => $account->overdue_amount,
            'remaining_installment_count' => $account->schedules()->where('outstanding_amount', '>', 0)->count(),
            'proposed_settlement_amount' => Money::fromPaise($outstanding - $discount),
            'discount_amount' => Money::fromPaise($discount),
            'final_settlement_payable' => Money::fromPaise($outstanding - $discount),
        ];
    }

    public function settle(EmiAccount $account, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($account, $data, $actor): Payment {
            $account = EmiAccount::query()->lockForUpdate()->findOrFail($account->getKey());
            if (! in_array($account->status, ['active', 'overdue'], true)) {
                throw ValidationException::withMessages(['emi_account' => ['The EMI account is not eligible for settlement.']]);
            }

            $outstanding = Money::toPaise($account->outstanding_amount);
            $discount = Money::toPaise($data['discount_amount'] ?? '0');
            $settlement = Money::toPaise($data['settlement_amount']);
            $this->validateDiscount($outstanding, $discount, $actor);
            if ($settlement !== $outstanding - $discount) {
                throw ValidationException::withMessages([
                    'settlement_amount' => ['Settlement amount must equal outstanding amount minus the approved discount.'],
                ]);
            }

            $result = $this->payments->create([
                'emi_account_id' => $account->getKey(),
                'payment_date' => $data['payment_date'],
                'amount' => Money::fromPaise($settlement),
                'payment_method' => $data['payment_method'],
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'payment_type' => 'settlement',
                'status' => 'verified',
                'notes' => $data['remarks'],
            ], $actor, $data['idempotency_key'] ?? null);
            $payment = $result['payment'];

            if ($discount > 0) {
                $account->refresh();
                $this->allocations->allocateAdjustment($payment, $account, $discount);
                $this->overdue->recalculate($account);
                $this->audit->record(
                    'settlement.discount_applied',
                    $payment,
                    ['outstanding_amount' => Money::fromPaise($outstanding)],
                    ['discount_amount' => Money::fromPaise($discount), 'cash_amount' => $payment->amount],
                    $data['remarks'],
                );
            }

            $account->refresh();
            if (Money::toPaise($account->outstanding_amount) !== 0 || $account->status !== 'completed') {
                throw ValidationException::withMessages(['settlement' => ['Settlement did not reconcile the account to zero.']]);
            }

            $this->audit->record(
                'settlement.completed',
                $account,
                ['outstanding_amount' => Money::fromPaise($outstanding)],
                ['cash_amount' => $payment->amount, 'discount_amount' => Money::fromPaise($discount), 'status' => 'completed'],
                $data['remarks'],
            );
            $this->deviceUnlocks->queueIfCleared($account, $actor);
            $accountId = $account->id;
            DB::afterCommit(function () use ($accountId, $actor): void {
                EmiAccount::query()->with('devices')->find($accountId)?->devices
                    ->where('enrollment_status', 'enrolled')
                    ->each(fn ($device) => rescue(fn () => app(DeviceCommandService::class)->queue($device, 'release_prepare', [
                        'remarks' => 'Automatic release after completed settlement.',
                        'idempotency_key' => "emi-completed-release:{$accountId}:{$device->id}",
                    ], $actor, 'payment'), report: false));
            });

            return $payment->load(['emiAccount', 'customer', 'allocations.emiSchedule']);
        });
    }

    private function validateDiscount(int $outstanding, int $discount, User $actor): void
    {
        if ($discount < 0 || $discount >= $outstanding || ($discount * 100) > ($outstanding * self::MAX_DISCOUNT_PERCENT)) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Discount must be non-negative, below outstanding, and no more than 50% of outstanding.'],
            ]);
        }

        if ($discount > 0 && ! $actor->can('payments.discount')) {
            throw new AuthorizationException('You are not allowed to approve settlement discounts.');
        }
    }
}
