<?php

namespace App\Services;

use App\Models\EmiAccount;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly PaymentVerificationService $verification,
        private readonly AuditService $audit,
    ) {}

    /** @return array{payment: Payment, created: bool} */
    public function create(array $data, User $actor, ?string $idempotencyKey = null): array
    {
        return DB::transaction(function () use ($data, $actor, $idempotencyKey): array {
            if ($idempotencyKey) {
                $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    $this->ensureIdempotentMatch($existing, $data);

                    return ['payment' => $existing->load(['emiAccount', 'customer', 'allocations.emiSchedule']), 'created' => false];
                }
            }

            $account = EmiAccount::query()->lockForUpdate()->findOrFail($data['emi_account_id']);
            $this->verification->ensurePayable($account);
            if (Money::toPaise($data['amount']) > Money::toPaise($account->outstanding_amount)) {
                throw ValidationException::withMessages(['amount' => ['Payment exceeds the current outstanding balance.']]);
            }

            $requestedStatus = $data['status'] ?? 'pending';
            if ($requestedStatus === 'verified' && ! $actor->can('payments.verify')) {
                throw new AuthorizationException('You are not allowed to create verified payments.');
            }

            $payment = Payment::query()->create([
                ...$data,
                'payment_code' => 'TMP-'.Str::ulid(),
                'customer_id' => $account->customer_id,
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
                'collected_by' => $actor->getKey(),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $payment->updateQuietly(['payment_code' => sprintf('PAY-%06d', $payment->getKey())]);
            $payment->refresh();
            $this->audit->record('payment.created', $payment, null, [
                'payment_code' => $payment->payment_code,
                'emi_account_id' => $payment->emi_account_id,
                'amount' => $payment->amount,
                'status' => 'pending',
            ]);

            if ($requestedStatus === 'verified') {
                $payment = $this->verification->verify($payment, $actor);
            }

            return ['payment' => $payment->load(['emiAccount', 'customer', 'allocations.emiSchedule']), 'created' => true];
        });
    }

    public function cancel(Payment $payment, User $actor, ?string $remarks): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $remarks): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());
            if ($payment->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => ['Only pending payments can be cancelled.']]);
            }
            $payment->update(['status' => 'cancelled', 'notes' => $remarks ?? $payment->notes, 'updated_by' => $actor->getKey()]);
            $this->audit->record('payment.cancelled', $payment, ['status' => 'pending'], ['status' => 'cancelled']);

            return $payment;
        });
    }

    private function ensureIdempotentMatch(Payment $payment, array $data): void
    {
        if ($payment->emi_account_id !== (int) $data['emi_account_id']
            || Money::toPaise($payment->amount) !== Money::toPaise($data['amount'])
            || $payment->payment_method !== $data['payment_method']) {
            throw ValidationException::withMessages(['idempotency_key' => ['This idempotency key was used for a different payment.']]);
        }
    }
}
