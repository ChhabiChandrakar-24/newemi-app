<?php

return [
    'heartbeat_timeout_minutes' => (int) env('DEVICE_HEARTBEAT_TIMEOUT_MINUTES', 30),
    'enrollment_token_ttl_minutes' => (int) env('DEVICE_ENROLLMENT_TOKEN_TTL_MINUTES', 30),
    'credential_ttl_days' => (int) env('DEVICE_CREDENTIAL_TTL_DAYS', 365),
    'minimum_agent_version' => env('DEVICE_MIN_AGENT_VERSION'),
    'command_ttl_minutes' => (int) env('DEVICE_COMMAND_TTL_MINUTES', 60),
    'command_max_retries' => (int) env('DEVICE_COMMAND_MAX_RETRIES', 3),
    'command_ack_timeout_minutes' => (int) env('DEVICE_COMMAND_ACK_TIMEOUT_MINUTES', 5),
    'location_retention_days' => (int) env('DEVICE_LOCATION_RETENTION_DAYS', 30),
    'location_min_interval_minutes' => (int) env('LOCATION_MIN_INTERVAL_MINUTES', 60),
    'location_min_distance_meters' => (int) env('LOCATION_MIN_DISTANCE_METERS', 250),
    'capabilities' => [
        'can_show_warning', 'can_enter_lock_task', 'can_apply_restrictions',
        'can_report_battery', 'can_detect_sim_change', 'can_report_management_mode',
        'can_report_policy_compliance', 'can_prevent_management_removal',
        'can_restrict_factory_reset', 'can_restrict_safe_boot', 'can_restrict_user_changes',
        'can_restrict_unknown_sources', 'can_enforce_lock_task', 'can_apply_device_restrictions',
        'can_report_foreground_location', 'can_report_background_location',
    ],
    'event_types' => [
        'enrollment_completed', 'enrollment_failed', 'enrollment_failure',
        're_enroll_requested',
        'heartbeat_restored', 'device_offline', 'device_online', 'app_permission_changed',
        'management_status_changed', 'management_removed', 'management_changed',
        'policy_removed', 'permission_removed', 'sim_changed', 'compliance_changed',
        'device_non_compliant', 'agent_outdated', 'app_update_required',
        'credential_revoked', 'possible_tamper',
        'command_queued', 'command_received', 'command_applied', 'command_failed',
        'warning_applied', 'partial_lock_applied', 'full_lock_applied', 'unlock_applied',
        'APP_REMOVED', 'ENROLLMENT_REMOVED', 'MANAGEMENT_LOST', 'SIM_CHANGED',
        'app_removed', 'enrollment_removed', 'management_lost',
        'customer_consent_accepted', 'customer_consent_withdrawn',
        'device_released', 'emi_completed', 'data_retention_started', 'heartbeat_ping',
    ],

    // Consent-to-manage: versions and document locations shown on the device before enrollment.
    'consent' => [
        'required' => (bool) env('DEVICE_CONSENT_REQUIRED', true),
        'terms_version' => env('DEVICE_CONSENT_TERMS_VERSION', '1.0'),
        'privacy_version' => env('DEVICE_CONSENT_PRIVACY_VERSION', '1.0'),
        'terms_url' => env('DEVICE_CONSENT_TERMS_URL'),
        'privacy_url' => env('DEVICE_CONSENT_PRIVACY_URL'),
    ],

    // Data retention applied automatically after EMI completion.
    'retention' => [
        'apply_enabled' => (bool) env('DEVICE_RETENTION_ENABLED', true),
    ],

    // Re-enrollment issued to an already-enrolled device (short-lived, single use).
    're_enroll' => [
        'token_ttl_minutes' => (int) env('DEVICE_RE_ENROLL_TOKEN_TTL_MINUTES', 15),
    ],
];
