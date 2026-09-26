<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(array $data, User $actor): Customer
    {
        return DB::transaction(function () use ($data, $actor): Customer {
            $company = app(TenantContext::class)->company();
            if (! $actor->is_platform_admin && $company?->expires_at && $company->expires_at->isPast()) {
                throw ValidationException::withMessages(['company' => ['Your company subscription has expired. Please recharge your plan to add new customers.']]);
            }

            $data['customer_code'] = 'TMP-'.Str::ulid();
            $data['created_by'] = $actor->getKey();
            $data['updated_by'] = $actor->getKey();
            $data['consent_given'] = (bool) ($data['consent_given'] ?? true);
            $data['consent_given_at'] = $data['consent_given'] ? now() : null;

            $customer = Customer::query()->create($data);
            $customer->updateQuietly(['customer_code' => sprintf('CUS-%06d', $customer->getKey())]);
            $customer->refresh();

            $this->audit->record('customer.created', $customer, null, $this->auditSnapshot($customer));
            if ($customer->consent_given) {
                $this->audit->record(
                    'customer.consent_granted',
                    $customer,
                    ['consent_given' => false, 'consent_given_at' => null],
                    $this->consentSnapshot($customer),
                );
            }

            return $customer;
        });
    }

    public function update(Customer $customer, array $data, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $data, $actor): Customer {
            $old = $this->auditSnapshot($customer);
            $oldStatus = $customer->status;
            $oldConsent = $customer->consent_given;
            $oldConsentAt = $customer->consent_given_at;
            $newConsent = (bool) $data['consent_given'];

            if (! $oldConsent && $newConsent) {
                $data['consent_given_at'] = now();
            } elseif ($oldConsent && ! $newConsent) {
                $data['consent_given_at'] = $oldConsentAt;
            }

            $data['updated_by'] = $actor->getKey();
            $customer->update($data);
            $customer->refresh();
            $this->audit->record('customer.updated', $customer, $old, $this->auditSnapshot($customer));

            if ($oldStatus !== $customer->status) {
                $this->audit->record(
                    'customer.status_changed',
                    $customer,
                    ['status' => $oldStatus],
                    ['status' => $customer->status],
                );
            }

            if ($oldConsent !== $newConsent) {
                $this->audit->record(
                    $newConsent ? 'customer.consent_granted' : 'customer.consent_withdrawn',
                    $customer,
                    ['consent_given' => $oldConsent, 'consent_given_at' => $oldConsentAt?->toISOString()],
                    $this->consentSnapshot($customer),
                );
            }

            return $customer;
        });
    }

    public function changeStatus(Customer $customer, string $status, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $status, $actor): Customer {
            $old = ['status' => $customer->status];
            $customer->update(['status' => $status, 'updated_by' => $actor->getKey()]);
            $this->audit->record('customer.status_changed', $customer, $old, ['status' => $status]);

            return $customer;
        });
    }

    public function delete(Customer $customer, User $actor): void
    {
        DB::transaction(function () use ($customer, $actor): void {
            $customer->update(['updated_by' => $actor->getKey()]);
            $old = $this->auditSnapshot($customer);
            $customer->delete();
            $this->audit->record('customer.deleted', $customer, $old, null);
        });
    }

    public function restore(Customer $customer, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $actor): Customer {
            $customer->restore();
            $customer->update(['updated_by' => $actor->getKey()]);
            $this->audit->record('customer.restored', $customer, null, $this->auditSnapshot($customer));

            return $customer;
        });
    }

    private function auditSnapshot(Customer $customer): array
    {
        return [
            'customer_code' => $customer->customer_code,
            'full_name' => $customer->full_name,
            'mobile_number' => $customer->mobile_number,
            'email' => $customer->email,
            'status' => $customer->status,
            ...$this->consentSnapshot($customer),
        ];
    }

    private function consentSnapshot(Customer $customer): array
    {
        return [
            'consent_given' => $customer->consent_given,
            'consent_given_at' => $customer->consent_given_at?->toISOString(),
        ];
    }
}
