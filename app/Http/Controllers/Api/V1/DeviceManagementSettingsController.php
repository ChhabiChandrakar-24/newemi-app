<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DeviceManagementSetting;
use App\Models\LockPolicy;
use App\Services\DeviceManagementSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceManagementSettingsController extends Controller
{
    private const SENSITIVE = ['factory_reset_restriction_enabled', 'safe_boot_restriction_enabled', 'unknown_sources_restriction_enabled', 'require_device_owner_for_lock', 'allowed_management_modes'];

    public function __construct(private readonly DeviceManagementSettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->current()->load('updater'), 'defaults' => DeviceManagementSettingsService::DEFAULTS]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        if (array_intersect(array_keys($data), self::SENSITIVE)) {
            abort_unless($request->user()->can('settings.security.manage'), 403);
        }

        return response()->json(['message' => 'Device Management Settings updated.', 'data' => $this->settings->update($data, $request->user())]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate(['keys' => ['nullable', 'array'], 'keys.*' => ['string', Rule::in(array_keys(DeviceManagementSettingsService::DEFAULTS))]]);
        $keys = $data['keys'] ?? [];
        if (! $keys || array_intersect($keys, self::SENSITIVE)) {
            abort_unless($request->user()->can('settings.security.manage'), 403);
        }

        return response()->json(['message' => 'Selected settings reset to safe defaults.', 'data' => $this->settings->reset($keys, $request->user())]);
    }

    public function history(Request $request): JsonResponse
    {
        $id = $this->settings->current()->id;
        $history = AuditLog::query()->where('entity_type', (new DeviceManagementSetting)->getMorphClass())->where('entity_id', (string) $id)->latest()->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($history);
    }

    private function rules(): array
    {
        $integer = ['sometimes', 'integer', 'min:1', 'max:10080'];
        $boolean = ['sometimes', 'boolean'];

        return [
            'enrollment_token_ttl_minutes' => $integer, 'heartbeat_interval_minutes' => $integer,
            'heartbeat_timeout_minutes' => $integer, 'offline_alert_after_minutes' => $integer,
            'command_ttl_minutes' => $integer, 'command_max_retries' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'command_retry_delay_seconds' => ['sometimes', 'integer', 'min:1', 'max:3600'],
            'polling_fallback_enabled' => $boolean, 'fcm_wakeup_enabled' => $boolean,
            'minimum_android_version' => ['nullable', 'string', 'max:30'], 'minimum_agent_version' => ['nullable', 'string', 'max:30'],
            'recommended_agent_version' => ['nullable', 'string', 'max:30'], 'require_device_management_consent' => $boolean,
            'require_device_owner_for_lock' => ['nullable', 'boolean'], 'allowed_management_modes' => ['sometimes', 'array', 'min:1'],
            'allowed_management_modes.*' => [Rule::in(['device_owner', 'fully_managed', 'profile_owner', 'unmanaged', 'unknown'])],
            'default_lock_policy_id' => ['nullable', Rule::exists(LockPolicy::class, 'id')],
            'auto_unlock_after_payment' => $boolean, 'require_reason_for_warning' => $boolean,
            'require_reason_for_partial_lock' => $boolean, 'require_reason_for_full_lock' => $boolean,
            'require_reason_for_unlock' => $boolean, 'warning_message' => ['nullable', 'string', 'max:2000'],
            'partial_lock_message' => ['nullable', 'string', 'max:2000'], 'full_lock_message' => ['nullable', 'string', 'max:2000'],
            'support_phone' => ['nullable', 'string', 'max:32'], 'support_email' => ['nullable', 'email', 'max:255'],
            'factory_reset_restriction_enabled' => $boolean, 'safe_boot_restriction_enabled' => $boolean,
            'unknown_sources_restriction_enabled' => $boolean, 'compliance_monitoring_enabled' => $boolean,
            'management_loss_alert_enabled' => $boolean, 'sim_change_alert_enabled' => $boolean,
            'agent_outdated_alert_enabled' => $boolean, 'release_requires_completed_emi' => $boolean,
            'release_requires_admin_approval' => $boolean, 'location_feature_enabled' => $boolean,
            'foreground_location_allowed' => $boolean, 'background_location_allowed' => $boolean,
            'progressive_warning_enabled' => $boolean,
            'progressive_warning_count' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'warning_escalation_interval_unit' => ['sometimes', Rule::in(['minutes', 'hours', 'days'])],
            'warning_escalation_interval_value' => ['sometimes', 'integer', 'min:1', 'max:10080'],
            'auto_lock_action' => ['sometimes', Rule::in(['full_lock', 'partial_lock'])],
        ];
    }
}
