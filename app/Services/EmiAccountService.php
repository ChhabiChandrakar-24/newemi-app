<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\EmiAccount;
use App\Models\User;
use App\Support\Money;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmiAccountService
{
    private const FINANCIAL_FIELDS = [
        'customer_id', 'financed_amount', 'down_payment', 'total_installments',
        'emi_start_date', 'due_day', 'grace_period_days', 'interest_amount',
        'processing_fee', 'other_charges', 'emi_frequency', 'emi_frequency_days',
    ];

    private const TRANSITIONS = [
        'draft' => ['active', 'cancelled'],
        'active' => ['overdue', 'completed', 'cancelled', 'closed'],
        'overdue' => ['active', 'completed', 'cancelled', 'closed'],
        'completed' => ['closed'],
        'cancelled' => ['closed'],
        'closed' => [],
    ];

    public function __construct(
        private readonly EmiScheduleGenerator $scheduleGenerator,
        private readonly AuditService $audit,
    ) {}

    public function create(array $data, User $actor): EmiAccount
    {
        return DB::transaction(function () use ($data, $actor): EmiAccount {
            $company = app(TenantContext::class)->company();
            if (! $actor->is_platform_admin && $company?->expires_at && $company->expires_at->isPast()) {
                throw ValidationException::withMessages(['company' => ['Your company subscription has expired. Please recharge your plan to create new EMI accounts.']]);
            }

            $this->ensureActiveCustomer((int) $data['customer_id']);
            $calculated = $this->calculate($data);
            $account = EmiAccount::query()->create([
                ...$data,
                ...$calculated,
                'emi_account_code' => 'TMP-'.Str::ulid(),
                'total_paid' => '0.00',
                'outstanding_amount' => $calculated['total_payable'],
                'overdue_amount' => '0.00',
                'overdue_installments' => 0,
                'next_due_date' => $data['emi_start_date'],
                'due_day' => $data['due_day'] ?? CarbonImmutable::parse($data['emi_start_date'])->day,
                'auto_lock_enabled' => (bool) ($data['auto_lock_enabled'] ?? false),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $account->updateQuietly(['emi_account_code' => sprintf('EMI-%06d', $account->getKey())]);
            $account->refresh();
            $this->scheduleGenerator->generate($account);
            $this->audit->record('emi_account.created', $account, null, $this->snapshot($account));
            $this->audit->record(
                'emi_schedule.generated',
                $account,
                null,
                ['total_installments' => $account->total_installments, 'total_payable' => $account->total_payable],
            );

            return $account->load('customer');
        });
    }

    public function update(EmiAccount $account, array $data, User $actor): EmiAccount
    {
        return DB::transaction(function () use ($account, $data, $actor): EmiAccount {
            $old = $this->snapshot($account);
            $financialChanged = $this->financialTermsChanged($account, $data);

            if ($account->status !== 'draft' && $financialChanged) {
                throw ValidationException::withMessages([
                    'account' => ['Financial terms can only be changed while the EMI account is in draft status.'],
                ]);
            }

            if ($financialChanged) {
                $this->ensureActiveCustomer((int) $data['customer_id']);
                $calculated = $this->calculate($data);
                $data = [
                    ...$data,
                    ...$calculated,
                    'outstanding_amount' => $calculated['total_payable'],
                    'next_due_date' => $data['emi_start_date'],
                    'due_day' => $data['due_day'] ?? CarbonImmutable::parse($data['emi_start_date'])->day,
                ];
            } else {
                $data = Arr::only($data, [
                    'invoice_number', 'invoice_date', 'product_description', 'auto_lock_enabled', 'notes',
                ]);
            }

            $data['updated_by'] = $actor->getKey();
            $account->update($data);

            if ($financialChanged) {
                $account->schedules()->delete();
                $account->refresh();
                $this->scheduleGenerator->generate($account);
                $this->audit->record(
                    'emi_schedule.regenerated',
                    $account,
                    null,
                    ['total_installments' => $account->total_installments, 'total_payable' => $account->total_payable],
                );
            }

            $account->refresh();
            $this->audit->record('emi_account.updated', $account, $old, $this->snapshot($account));

            return $account->load('customer');
        });
    }

    public function changeStatus(EmiAccount $account, string $status, User $actor): EmiAccount
    {
        if ($status === $account->status) {
            return $account;
        }

        if (! in_array($status, self::TRANSITIONS[$account->status] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => ["Transition from {$account->status} to {$status} is not allowed."],
            ]);
        }

        if ($status === 'completed' && Money::toPaise($account->outstanding_amount) > 0) {
            throw ValidationException::withMessages([
                'status' => ['An EMI account with an outstanding balance cannot be completed.'],
            ]);
        }

        return DB::transaction(function () use ($account, $status, $actor): EmiAccount {
            $oldStatus = $account->status;
            $account->update(['status' => $status, 'updated_by' => $actor->getKey()]);
            $this->audit->record(
                'emi_account.status_changed',
                $account,
                ['status' => $oldStatus],
                ['status' => $status],
            );

            if (in_array($status, ['cancelled', 'closed'], true)) {
                $this->audit->record("emi_account.{$status}", $account, ['status' => $oldStatus], ['status' => $status]);
            }

            return $account;
        });
    }

    private function calculate(array $data): array
    {
        $financed = Money::toPaise($data['financed_amount']);
        $downPayment = Money::toPaise($data['down_payment'] ?? '0');
        $principal = $financed - $downPayment;
        $total = $principal
            + Money::toPaise($data['interest_amount'] ?? '0')
            + Money::toPaise($data['processing_fee'] ?? '0')
            + Money::toPaise($data['other_charges'] ?? '0');

        if ($principal < 0 || $total <= 0) {
            throw ValidationException::withMessages(['financed_amount' => ['Calculated payable amount must be greater than zero.']]);
        }

        $installments = (int) $data['total_installments'];
        $frequency = $data['emi_frequency'] ?? 'monthly';
        $start = CarbonImmutable::parse($data['emi_start_date']);
        
        if ($frequency === 'weekly') {
            $endDate = $start->addWeeks($installments - 1);
        } elseif ($frequency === 'daily') {
            $endDate = $start->addDays($installments - 1);
        } elseif ($frequency === 'hourly') {
            $endDate = $start->addHours($installments - 1);
        } elseif ($frequency === 'minutes') {
            $endDate = $start->addMinutes($installments - 1);
        } elseif ($frequency === 'custom_minutes') {
            $mins = max(1, (int) ($data['emi_frequency_days'] ?? 1));
            $endDate = $start->addMinutes(($installments - 1) * $mins);
        } elseif ($frequency === 'custom_hours') {
            $hrs = max(1, (int) ($data['emi_frequency_days'] ?? 1));
            $endDate = $start->addHours(($installments - 1) * $hrs);
        } elseif ($frequency === 'custom_days') {
            $days = max(1, (int) ($data['emi_frequency_days'] ?? 1));
            $endDate = $start->addDays(($installments - 1) * $days);
        } else {
            $endDate = $start->addMonthsNoOverflow($installments - 1);
        }

        return [
            'principal_amount' => Money::fromPaise($principal),
            'total_payable' => Money::fromPaise($total),
            'installment_amount' => Money::fromPaise(intdiv($total, $installments)),
            'emi_end_date' => $endDate->toDateTimeString(),
        ];
    }

    private function ensureActiveCustomer(int $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);
        if ($customer->status !== 'active') {
            throw ValidationException::withMessages(['customer_id' => ['The customer must be active.']]);
        }
    }

    private function financialTermsChanged(EmiAccount $account, array $data): bool
    {
        foreach (self::FINANCIAL_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $incoming = $data[$field];
            $current = $account->{$field};
            if ($field === 'due_day') {
                $incoming ??= CarbonImmutable::parse($data['emi_start_date'] ?? $account->emi_start_date)->day;
            }
            if (in_array($field, ['financed_amount', 'down_payment', 'interest_amount', 'processing_fee', 'other_charges'], true)) {
                if (Money::toPaise($incoming ?? '0') !== Money::toPaise($current ?? '0')) {
                    return true;
                }
            } elseif ($field === 'emi_start_date') {
                if ($incoming !== null && CarbonImmutable::parse($incoming)->toDateString() !== $account->emi_start_date->toDateString()) {
                    return true;
                }
            } elseif ((string) $incoming !== (string) $current) {
                return true;
            }
        }

        return false;
    }

    private function snapshot(EmiAccount $account): array
    {
        return Arr::only($account->toArray(), [
            'emi_account_code', 'customer_id', 'invoice_number', 'financed_amount',
            'down_payment', 'principal_amount', 'total_installments', 'installment_amount',
            'emi_start_date', 'grace_period_days', 'interest_amount', 'processing_fee',
            'other_charges', 'total_payable', 'outstanding_amount', 'status',
        ]);
    }
}
