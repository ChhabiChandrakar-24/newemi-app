<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceEnrollment;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceEnrollmentService
{
    public function __construct(
        private readonly DeviceEventService $events,
        private readonly AuditService $audit,
        private readonly DeviceManagementSettingsService $managementSettings,
    ) {}

    /** @return array{enrollment: DeviceEnrollment, token: string} */
    public function createToken(Device $device, User $actor): array
    {
        return DB::transaction(function () use ($device, $actor): array {
            $device = Device::query()->lockForUpdate()->findOrFail($device->getKey());
            $this->ensureEligible($device);
            $device->enrollments()->where('status', 'pending')->update(['status' => 'revoked', 'revoked_at' => now()]);
            $enrollment = $device->enrollments()->create([
                'company_id' => $device->company_id,
                'enrollment_code' => 'TMP-'.Str::ulid(),
                'token_hash' => hash('sha256', Str::random(80)),
                'expires_at' => now()->addMinutes($this->managementSettings->forCompany($device->company_id)->enrollment_token_ttl_minutes),
                'status' => 'pending',
                'created_by' => $actor->getKey(),
            ]);
            $token = $enrollment->getKey().'|'.Str::random(80);
            $enrollment->updateQuietly([
                'enrollment_code' => sprintf('ENR-%06d', $enrollment->getKey()),
                'token_hash' => hash('sha256', $token),
            ]);
            $this->audit->record('device.enrollment_token_created', $device, null, [
                'enrollment_id' => $enrollment->getKey(), 'expires_at' => $enrollment->expires_at->toISOString(),
            ]);

            return ['enrollment' => $enrollment->refresh(), 'token' => $token];
        });
    }

    public function revoke(DeviceEnrollment $enrollment, User $actor): DeviceEnrollment
    {
        if ($enrollment->status !== 'pending') {
            throw ValidationException::withMessages(['enrollment' => ['Only pending enrollment tokens can be revoked.']]);
        }
        $enrollment->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->audit->record('device.enrollment_token_revoked', $enrollment->device, null, ['enrollment_id' => $enrollment->id]);

        return $enrollment;
    }

    /**
     * Issues a short-lived, single-use enrollment token to an already-enrolled device.
     *
     * Used for supported recovery flows (management re-establishment). The device must
     * still be enrolled, under an active customer consent and linked to an active EMI.
     *
     * @return array{enrollment: DeviceEnrollment, token: string}
     */
    public function createReEnrollToken(Device $device, ?string $reason = null): array
    {
        return DB::transaction(function () use ($device, $reason): array {
            $device = Device::query()->with('emiAccount')->lockForUpdate()->findOrFail($device->getKey());

            if ($device->enrollment_status !== 'enrolled') {
                throw ValidationException::withMessages(['device' => ['Only enrolled devices can request re-enrollment.']]);
            }
            if ($device->released_at) {
                throw ValidationException::withMessages(['device' => ['Released devices cannot be re-enrolled.']]);
            }
            $consent = $device->consents()->where('consent_status', 'accepted')->latest('consent_timestamp')->first();
            if (! $consent) {
                throw ValidationException::withMessages(['consent' => ['Active customer consent is required for re-enrollment.']]);
            }
            if ($device->emiAccount && ! in_array($device->emiAccount->status, ['active', 'overdue'], true)) {
                throw ValidationException::withMessages(['emi_account' => ['Linked EMI account must be active or overdue for re-enrollment.']]);
            }

            $device->enrollments()->where('status', 'pending')->update(['status' => 'revoked', 'revoked_at' => now()]);
            $enrollment = $device->enrollments()->create([
                'company_id' => $device->company_id,
                'enrollment_code' => 'TMP-'.Str::ulid(),
                'token_hash' => hash('sha256', Str::random(80)),
                'expires_at' => now()->addMinutes((int) config('devices.re_enroll.token_ttl_minutes')),
                'status' => 'pending',
                'metadata' => ['re_enroll' => true, 'reason' => $reason ?? null],
            ]);
            $token = $enrollment->getKey().'|'.Str::random(80);
            $enrollment->updateQuietly([
                'enrollment_code' => sprintf('REN-%06d', $enrollment->getKey()),
                'token_hash' => hash('sha256', $token),
            ]);
            $this->events->record($device, 're_enroll_requested', 'info', [
                'code' => 're_enroll_token_issued', 'reason' => $reason ?? null,
            ]);
            $this->audit->record('device.re_enroll_token_created', $device, null, [
                'enrollment_id' => $enrollment->getKey(), 'expires_at' => $enrollment->expires_at->toISOString(),
            ]);

            return ['enrollment' => $enrollment->refresh(), 'token' => $token];
        });
    }

    /** @return array{device: Device, credential: string} */
    public function claim(string $token, array $data): array
    {
        $candidate = DeviceEnrollment::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))
            ->with(['device' => fn ($query) => $query->withoutGlobalScopes(), 'company'])->first();
        if (! $candidate) {
            throw ValidationException::withMessages(['enrollment_token' => ['Enrollment token is invalid.']]);
        }
        $context = app(TenantContext::class);
        $context->set($candidate->company);
        try {
            if ($candidate->status !== 'pending' || $candidate->used_at || $candidate->revoked_at) {
                $this->recordFailure($candidate->device, 'token_not_pending');
                throw ValidationException::withMessages(['enrollment_token' => ['Enrollment token is no longer usable.']]);
            }
            if ($candidate->expires_at->isPast()) {
                $candidate->update(['status' => 'expired']);
                $this->recordFailure($candidate->device, 'token_expired');
                throw ValidationException::withMessages(['enrollment_token' => ['Enrollment token has expired.']]);
            }

            return DB::transaction(function () use ($token, $data): array {
                $enrollment = DeviceEnrollment::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
                if (! $enrollment) {
                    throw ValidationException::withMessages(['enrollment_token' => ['Enrollment token is invalid.']]);
                }
                $device = Device::query()->lockForUpdate()->findOrFail($enrollment->device_id);
                if ($enrollment->status !== 'pending' || $enrollment->used_at || $enrollment->revoked_at) {
                    throw ValidationException::withMessages(['enrollment_token' => ['Enrollment token is no longer usable.']]);
                }
                if ($enrollment->expires_at->isPast()) {
                    throw ValidationException::withMessages(['enrollment_token' => ['Enrollment token has expired.']]);
                }
                $this->ensureEligible($device);

                $device->credentials()->whereNull('revoked_at')->update(['revoked_at' => now()]);
                $credential = $this->issueCredential($device);
                $capabilities = collect(config('devices.capabilities'))
                    ->mapWithKeys(fn (string $key): array => [$key => (bool) ($data['capabilities'][$key] ?? false)])
                    ->all();
                $device->update([
                    'installation_id_hash' => hash('sha256', $data['installation_identifier']),
                    'brand' => $data['brand'] ?? $device->brand,
                    'model' => $data['model'] ?? $device->model,
                    'manufacturer' => $data['manufacturer'] ?? $device->manufacturer,
                    'android_version' => $data['android_version'],
                    'sdk_version' => $data['sdk_version'],
                    'app_version' => $data['app_version'],
                    'management_mode' => $data['management_mode'],
                    'capabilities' => $capabilities,
                    'enrollment_status' => 'enrolled',
                    'connectivity_status' => 'online',
                    'compliance_status' => 'unknown',
                    'consent_verified_at' => now(),
                    'activated_at' => now(),
                    'last_seen_at' => now(),
                ]);
                $enrollment->update([
                    'status' => 'used',
                    'used_at' => now(),
                    'metadata' => ['app_version' => $data['app_version'], 'management_mode' => $data['management_mode']],
                ]);
                $this->events->record($device, 'enrollment_completed', 'info', [
                    'management_mode' => $data['management_mode'], 'app_version' => $data['app_version'],
                ]);
                $this->audit->record('device.enrollment_completed', $device, null, [
                    'enrollment_id' => $enrollment->id, 'management_mode' => $data['management_mode'],
                ]);

                return ['device' => $device->refresh(), 'credential' => $credential];
            });
        } finally {
            $context->clear();
        }
    }

    private function issueCredential(Device $device): string
    {
        $record = $device->credentials()->create([
            'company_id' => $device->company_id,
            'token_hash' => hash('sha256', Str::random(80)),
            'name' => 'android-agent',
            'expires_at' => now()->addDays(config('devices.credential_ttl_days')),
        ]);
        $token = $record->getKey().'|'.Str::random(80);
        $record->updateQuietly(['token_hash' => hash('sha256', $token)]);

        return $token;
    }

    private function ensureEligible(Device $device): void
    {
        if ($device->enrollment_status === 'released') {
            throw ValidationException::withMessages(['device' => ['Released devices cannot be enrolled.']]);
        }
        if ($device->emiAccount && ! in_array($device->emiAccount->status, ['active', 'overdue'], true)) {
            throw ValidationException::withMessages(['device' => ['Linked EMI account must be active or overdue for enrollment.']]);
        }
    }

    private function recordFailure(Device $device, string $code): void
    {
        $this->events->record($device, 'enrollment_failure', 'warning', ['code' => $code]);
        $this->audit->record('device.enrollment_failed', $device, null, ['code' => $code]);
    }
}
