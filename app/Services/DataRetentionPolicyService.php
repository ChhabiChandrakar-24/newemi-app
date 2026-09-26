<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DataRetentionPolicy;
use App\Models\DeviceCommand;
use App\Models\DeviceEvent;
use App\Models\DeviceLocation;
use App\Models\DeviceNotification;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class DataRetentionPolicyService
{
    public const DEFAULT_RETENTION_DAYS = [
        'device_events' => 90,
        'device_notifications' => 180,
        'device_locations' => 30,
        'device_commands' => 365,
        'device_consents' => 0,
        'audit_logs' => 0,
    ];

    public function __construct(private readonly AuditService $audit) {}

    /** Creates per-company defaults for every supported data type (idempotent). */
    public function ensureDefaults(Company $company): int
    {
        $created = 0;
        foreach (DataRetentionPolicy::DATA_TYPES as $dataType) {
            $defaultDays = DataRetentionPolicyService::DEFAULT_RETENTION_DAYS[$dataType] ?? 90;
            $existing = DataRetentionPolicy::where('company_id', $company->id)->where('data_type', $dataType)->first();
            if (! $existing) {
                DataRetentionPolicy::create([
                    'company_id' => $company->id,
                    'data_type' => $dataType,
                    'retention_period_days' => $defaultDays,
                    'action' => $defaultDays > 0 ? 'delete' : 'keep',
                    'reason' => 'Default retention policy ('.$dataType.')',
                    'is_active' => true,
                ]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * Applies all active policies for a company. Consent and audit records are
     * always retained (legal/financial records) regardless of policy, matching
     * the "keep financial/consent/audit" business rule.
     *
     * @return array{processed: int, deleted: int, anonymized: int, kept: int}
     */
    public function apply(Company $company, ?DateTimeInterface $asOf = null): array
    {
        return DB::transaction(function () use ($company, $asOf): array {
            DataRetentionPolicyService::ensureDefaults($company);
            $cutoff = $asOf ? Carbon::instance($asOf) : now();
            $deleted = 0;
            $anonymized = 0;
            $kept = 0;

            $policies = DataRetentionPolicy::where('company_id', $company->id)->where('is_active', true)->get();
            foreach ($policies as $policy) {
                $period = (int) $policy->retention_period_days;
                if ($period <= 0 || $policy->action === 'keep') {
                    $kept++;

                    continue;
                }
                $threshold = $cutoff->copy()->subDays($period);
                $fields = $this->applyForType($policy->data_type, $company->id, $threshold, $policy->action);
                $deleted += $fields['deleted'];
                $anonymized += $fields['anonymized'];
                $policy->update(['last_applied_at' => now()]);
            }

            $this->audit->record('retention.policy_applied', $company, null, [
                'deleted' => $deleted, 'anonymized' => $anonymized, 'kept' => $kept, 'as_of' => $cutoff->toDateString(),
            ]);

            return ['processed' => $policies->count(), 'deleted' => $deleted, 'anonymized' => $anonymized, 'kept' => $kept];
        });
    }

    private function applyForType(string $dataType, int $companyId, DateTimeInterface $threshold, string $action): array
    {
        if ($dataType === 'device_locations') {
            $rows = DeviceLocation::query()
                ->whereHas('device', fn ($device) => $device->where('company_id', $companyId))
                ->where('captured_at', '<', $threshold);
            if ($action === 'delete') {
                return ['deleted' => $rows->delete(), 'anonymized' => 0];
            }

            return ['deleted' => 0, 'anonymized' => $rows->update([
                'latitude' => 0, 'longitude' => 0, 'accuracy_meters' => null, 'source' => 'anonymized',
            ])];
        }

        if ($dataType === 'device_events') {
            $rows = DeviceEvent::query()
                ->whereHas('device', fn ($device) => $device->where('company_id', $companyId))
                ->where('event_time', '<', $threshold);
            if ($action === 'delete') {
                return ['deleted' => $rows->delete(), 'anonymized' => 0];
            }

            return ['deleted' => 0, 'anonymized' => $rows->update(['payload' => null])];
        }

        if ($dataType === 'device_notifications') {
            $rows = DeviceNotification::where('company_id', $companyId)->where('created_at', '<', $threshold);
            if ($action === 'delete') {
                return ['deleted' => $rows->delete(), 'anonymized' => 0];
            }

            return ['deleted' => 0, 'anonymized' => $rows->update(['message' => null, 'metadata' => null])];
        }

        if ($dataType === 'device_commands') {
            $rows = DeviceCommand::query()
                ->whereHas('device', fn ($device) => $device->where('company_id', $companyId))
                ->where('requested_at', '<', $threshold);
            if ($action === 'delete') {
                return ['deleted' => $rows->delete(), 'anonymized' => 0];
            }

            return ['deleted' => 0, 'anonymized' => $rows->update(['payload' => null])];
        }

        // Device consents and audit logs are always kept for legal/compliance reasons.
        return ['deleted' => 0, 'anonymized' => 0];
    }
}