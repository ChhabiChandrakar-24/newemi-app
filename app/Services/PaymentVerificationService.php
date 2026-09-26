<?php

namespace App\Services;

use App\Models\EmiAccount;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentVerificationService
{
    public function __construct(
        private readonly PaymentAllocationService $allocations,
        private readonly EmiOverdueService $overdue,
        private readonly AuditService $audit,
        private readonly PaymentDeviceUnlockService $deviceUnlocks,
    ) {}

    public function verify(Payment $payment, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $actor): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());
            if ($payment->status === 'verified') {
                throw ValidationException::withMessages(['payment' => ['Payment is already verified.']]);
            }
            if ($payment->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => ['Only pending payments can be verified.']]);
            }

            $account = EmiAccount::query()->lockForUpdate()->findOrFail($payment->emi_account_id);
            $this->ensurePayable($account);
            if (Money::toPaise($payment->amount) > Money::toPaise($account->outstanding_amount)) {
                throw ValidationException::withMessages(['amount' => ['Payment exceeds the current outstanding balance.']]);
            }

            $payment->update([
                'status' => 'verified',
                'receipt_number' => sprintf('RCT-%s-%06d', $payment->payment_date->format('Y'), $payment->getKey()),
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'updated_by' => $actor->getKey(),
            ]);
            $this->allocations->allocatePayment($payment, $account);
            $this->overdue->recalculate($account);
            $account->refresh();
            $this->audit->record(
                'payment.verified',
                $payment,
                ['status' => 'pending'],
                ['status' => 'verified', 'amount' => $payment->amount, 'receipt_number' => $payment->receipt_number],
            );
            $this->deviceUnlocks->queueIfCleared($account, $actor);
            if ($account->status === 'completed' || Money::toPaise($account->outstanding_amount) === 0) {
                $account->update([
                    'status' => 'completed',
                    'emi_status' => 'PAID',
                    'auto_lock_enabled' => false,
                ]);
                foreach ($account->devices as $device) {
                    // 1. Cancel pending lock commands
                    \App\Models\DeviceCommand::where('device_id', $device->id)
                        ->whereIn('command_type', ['full_lock', 'lock', 'partial_lock'])
                        ->whereIn('status', \App\Models\DeviceCommand::ACTIVE_STATUSES)
                        ->update(['status' => 'cancelled', 'remarks' => 'Cancelled due to complete payment']);

                    // 2. Queue unlock & release commands so device unlocks immediately
                    rescue(fn () => app(DeviceCommandService::class)->queue($device, 'unlock', [
                        'remarks' => 'Automatic unlock: all EMI installments paid in full.',
                        'idempotency_key' => "emi-completed-unlock:{$account->id}:{$device->id}",
                        'force' => true,
                    ], $actor, 'payment'), report: false);

                    rescue(fn () => app(DeviceCommandService::class)->queue($device, 'release', [
                        'remarks' => 'Automatic release after all installments completed.',
                        'idempotency_key' => "emi-completed-release:{$account->id}:{$device->id}",
                        'force' => true,
                    ], $actor, 'payment'), report: false);

                    // 3. Mark device released
                    rescue(fn () => app(DeviceService::class)->release($device, $actor), report: false);
                }
            } else {
                // If device is in normal mode, send a policy_sync command so the device syncs updated balance and payments
                foreach ($account->devices()->where('enrollment_status', 'enrolled')->get() as $device) {
                    if (! in_array($device->control_status, ['warning', 'partial_lock', 'full_lock'], true)) {
                        rescue(fn () => app(DeviceCommandService::class)->queue($device, 'policy_sync', [
                            'remarks' => "Payment of ₹{$payment->amount} verified. Balances synchronized.",
                            'force' => true,
                        ], $actor, 'payment'), report: false);
                    }
                }
            }

            return $payment->load(['emiAccount', 'customer', 'allocations.emiSchedule']);
        });
    }

    public function ensurePayable(EmiAccount $account): void
    {
        if (! in_array($account->status, ['active', 'overdue'], true)) {
            throw ValidationException::withMessages(['emi_account_id' => ['The EMI account is not payable.']]);
        }
    }
}
