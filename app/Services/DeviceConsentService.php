<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceConsent;
use App\Models\DeviceEnrollment;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeviceConsentService
{
    public function __construct(
        private readonly DeviceEventService $events,
        private readonly AuditService $audit,
    ) {}

    /** Contract summary shown to the customer before they accept consent on the device. */
    public function preview(Company $company, ?string $enrollmentToken = null): array
    {
        $details = [
            'company' => ['id' => (int) $company->id, 'name' => $company->name],
            'terms_version' => config('devices.consent.terms_version', '1.0'),
            'privacy_version' => config('devices.consent.privacy_version', '1.0'),
            'summary' => [
                'enrollment' => 'The device is enrolled into the EMI monitoring service so EMI status, overdue risk and lock policies can be applied.',
                'monitoring' => 'Anonymized device status, connectivity and compliance events are monitored while the EMI contract is active.',
                'location' => 'Location reporting is only enabled with your separate, explicit location consent and can be switched off at any time.',
                'release' => 'When the EMI is fully paid the device is automatically released from enforcement and retained data is processed per the retention policy.',
                'withdrawal' => 'You may withdraw consent at any time, which disables remote management and stops monitoring of the device.',
            ],
            'device_management_conditions' => [
                'Authorized device management is active during the financed contract period to verify device integrity, compliance, and payment status.',
                'Tamper protection, factory reset prevention, and persistent enterprise security remain enforced while financed balance is outstanding.',
                'Device health telemetry (battery, network status, heartbeat) is monitored to maintain active sync with payment records.',
            ],
            'restriction_conditions' => [
                'Device restrictions (warnings, partial lock, or full lock) apply strictly when contractual EMI payments are overdue and the configured grace period has expired.',
                'Emergency dialer (112) and EMI installment payment access remain permanently available even during lock mode.',
                'Device lock is immediately and automatically removed upon verified installment clearance or contract release.',
            ],
            'terms_text' => "1. Financed Device Agreement: The device is financed under an installment agreement.\n2. Customer Authorization: The customer explicitly authorizes enterprise management for the duration of the EMI schedule.\n3. Automated Release: Upon verified completion of the final EMI payment, device management is automatically released and monitoring ends.\n4. Transparency: The customer receives payment warnings and grace period notifications before any restriction.",
            'privacy_policy_text' => "1. Privacy First: We collect only the minimum necessary device telemetry (battery level, network status, compliance status, app version).\n2. No Personal Content: We do NOT collect personal photos, messages, calls, browsing history, or keystrokes.\n3. Hardware Identifiers: Device identifiers are collected only where permitted by Android Enterprise APIs.\n4. Data Retention: Retention policies ensure temporary device logs are deleted or anonymized, preserving only legally required financial and audit records.",
            'privacy_text' => "1. Privacy First: We collect only the minimum necessary device telemetry (battery level, network status, compliance status, app version).\n2. No Personal Content: We do NOT collect personal photos, messages, calls, browsing history, or keystrokes.\n3. Hardware Identifiers: Device identifiers are collected only where permitted by Android Enterprise APIs.\n4. Data Retention: Retention policies ensure temporary device logs are deleted or anonymized, preserving only legally required financial and audit records.",
        ];

        if (is_string($enrollmentToken) && $enrollmentToken !== '') {
            $candidate = DeviceEnrollment::withoutGlobalScopes()
                ->where('token_hash', hash('sha256', $enrollmentToken))
                ->with(['device' => fn ($q) => $q->withoutGlobalScopes()->with([
                    'customer' => fn ($q) => $q->withoutGlobalScopes(),
                    'emiAccount' => fn ($q) => $q->withoutGlobalScopes()->with(['schedules' => fn ($q) => $q->withoutGlobalScopes()]),
                ])])
                ->first();

            if ($candidate && $candidate->device) {
                $device = $candidate->device;
                $customer = $device->customer;
                $account = $device->emiAccount;

                $installmentAmount = $account?->installment_amount
                    ?? $account?->schedules?->first()?->amount
                    ?? ($account && $account->total_installments > 0 ? round($account->total_payable / $account->total_installments, 2) : 0);

                $details['customer'] = [
                    'name' => $customer?->full_name ?? 'Valued Customer',
                    'mobile' => $customer?->mobile_number ?? '—',
                    'customer_code' => $customer?->customer_code ?? '—',
                    'code' => $customer?->customer_code ?? '—',
                ];

                $details['device'] = [
                    'brand' => $device->brand ?? 'Android',
                    'model' => $device->model ?? 'Financed Device',
                    'device_code' => $device->device_code,
                    'device_identifier' => $device->internal_device_uuid,
                    'identifier' => $device->internal_device_uuid,
                    'imei1' => $device->imei1 ?: 'Restricted by Android',
                ];

                $details['emi_contract'] = [
                    'account_code' => $account?->emi_account_code ?? '—',
                    'invoice_number' => $account?->invoice_number ?? $device->invoice_number ?? '—',
                    'installment_amount' => (string) $installmentAmount,
                    'frequency' => 'Monthly',
                    'next_due_date' => $account?->next_due_date?->toDateString() ?? $account?->schedules?->first()?->due_date?->toDateString() ?? '—',
                    'total_installments' => $account?->total_installments ?? $account?->schedules?->count() ?? 0,
                    'paid_installments' => $account?->paid_installments ?? $account?->schedules?->where('status', 'paid')->count() ?? 0,
                    'pending_installments' => $account?->remaining_installments ?? max(0, ($account?->total_installments ?? 0) - ($account?->paid_installments ?? 0)),
                    'outstanding_amount' => (string) ($account?->outstanding_amount ?? '0.00'),
                    'overdue_amount' => (string) ($account?->overdue_amount ?? '0.00'),
                    'grace_period_days' => (int) ($account?->grace_period_days ?? 3),
                ];
                $details['emi'] = $details['emi_contract'];
                $details['conditions'] = $details['device_management_conditions'];
            }
        }

        return $details;
    }

    /**
     * Records explicit customer consent supplied together with an enrollment token.
     *
     * The consent is captured before the enrollment token is consumed, so the
     * claim step can proceed immediately afterwards.
     */
    public function acceptWithToken(string $token, array $data, ?string $ipAddress = null): DeviceConsent
    {
        $candidate = DeviceEnrollment::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))
            ->with(['device' => fn ($query) => $query->withoutGlobalScopes(), 'company'])->first();
        if (! $candidate) {
            throw ValidationException::withMessages(['enrollment_token' => ['Enrollment token is invalid.']]);
        }
        $context = app(TenantContext::class);
        $context->set($candidate->company);
        try {
            if ($candidate->status !== 'pending' || $candidate->used_at || $candidate->revoked_at || $candidate->expires_at?->isPast()) {
                throw ValidationException::withMessages(['enrollment_token' => ['Enrollment token is no longer usable.']]);
            }
            $device = $candidate->device;

            return DB::transaction(function () use ($candidate, $data, $ipAddress, $device): DeviceConsent {
                return $this->recordAccepted(
                    (int) $candidate->company_id,
                    $device,
                    [
                        'enrollment_id' => $candidate->id,
                        'terms_version' => $data['terms_version'] ?? config('devices.consent.terms_version'),
                        'privacy_version' => $data['privacy_version'] ?? config('devices.consent.privacy_version'),
                        'consent_device_id' => $data['consent_device_id'] ?? null,
                        'consent_ip_address' => $ipAddress,
                        'accepted_terms' => (bool) ($data['accepted_terms'] ?? false),
                        'accepted_conditions' => (bool) ($data['accepted_conditions'] ?? false),
                        'accepted_privacy' => (bool) ($data['accepted_privacy'] ?? false),
                        'metadata' => ['source' => 'enrollment_token', 'agent' => $data['agent'] ?? null],
                    ],
                );
            });
        } finally {
            $context->clear();
        }
    }

    public function recordAccepted(int $companyId, Device $device, array $attributes): DeviceConsent
    {
        if (! $attributes['accepted_terms'] || ! $attributes['accepted_conditions'] || ! $attributes['accepted_privacy']) {
            throw ValidationException::withMessages(['consent' => ['Terms, EMI conditions and privacy policy must all be explicitly accepted.']]);
        }
        $customer = $device->customer_id ? Customer::query()->find($device->customer_id) : null;

        $consent = DeviceConsent::create([
            'company_id' => $companyId,
            'customer_id' => $customer?->id,
            'device_id' => $device->id,
            'enrollment_id' => $attributes['enrollment_id'] ?? null,
            'emi_account_id' => $device->emi_account_id,
            'consent_status' => 'accepted',
            'consent_timestamp' => now(),
            'terms_version' => $attributes['terms_version'] ?? config('devices.consent.terms_version'),
            'privacy_version' => $attributes['privacy_version'] ?? config('devices.consent.privacy_version'),
            'consent_device_id' => $attributes['consent_device_id'] ?? null,
            'consent_ip_address' => $attributes['consent_ip_address'] ?? null,
            'accepted_terms' => $attributes['accepted_terms'],
            'accepted_conditions' => $attributes['accepted_conditions'],
            'accepted_privacy' => $attributes['accepted_privacy'],
            'metadata' => $attributes['metadata'] ?? null,
        ]);

        $customer?->updateQuietly([
            'consent_given' => true,
            'consent_given_at' => now(),
        ]);

        $device->update([
            'consent_verified_at' => now(),
            'management_status' => 'ACTIVE',
            'enrollment_status' => $device->enrollment_status === 'unenrolled' ? 'enrolled' : $device->enrollment_status,
        ]);

        $this->events->record($device, 'customer_consent_accepted', 'info', [
            'terms_version' => $consent->terms_version, 'privacy_version' => $consent->privacy_version,
        ]);
        $this->audit->record('device.consent_accepted', $device, null, [
            'consent_id' => $consent->id, 'terms_version' => $consent->terms_version,
        ]);

        return $consent;
    }

    /** Withdraws customer consent: disables remote management, revokes credentials and flags the device UNENROLLED. */
    public function withdraw(Device $device, ?User $actor = null, string $reason = 'customer_request'): DeviceConsent
    {
        return DB::transaction(function () use ($device, $actor, $reason): DeviceConsent {
            $device = Device::query()->with(['emiAccount', 'customer'])->lockForUpdate()->findOrFail($device->getKey());

            $consent = $device->consents()
                ->where('consent_status', 'accepted')
                ->latest('consent_timestamp')
                ->first();

            if ($consent) {
                $consent->update(['consent_status' => 'withdrawn', 'withdrawn_at' => now()]);
            }

            $device->customer?->updateQuietly(['consent_given' => false, 'consent_given_at' => null]);
            $device->credentials()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $device->enrollments()->where('status', 'pending')->update(['status' => 'revoked', 'revoked_at' => now()]);

            $device->update([
                'enrollment_status' => 'unenrolled',
                'management_status' => 'UNENROLLED',
                'consent_verified_at' => null,
                'connectivity_status' => 'unknown',
            ]);

            $this->events->record($device, 'management_removed', 'warning', [
                'reason' => $reason, 'consent_id' => $consent?->id,
            ]);
            $this->audit->record('device.consent_withdrawn', $device, $actor, [
                'consent_id' => $consent?->id, 'reason' => $reason,
            ]);

            return $consent ?? $device->consents()->latest('consent_timestamp')->first();
        });
    }

    public function latestFor(Device $device): ?DeviceConsent
    {
        return $device->consents()->latest('consent_timestamp')->first();
    }
}
