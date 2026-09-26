# Lock policies

## Resolution hierarchy

An active device-specific policy takes precedence over the active global default. With no applicable policy, automation takes no action. `policy_automation_paused` is the per-device manual override for automatic evaluation; it does not grant command permissions.

Policies define warning, optional partial-lock, and optional full-lock overdue-day thresholds, optional grace override, offline behavior, and whether payment clearance should queue an unlock. Threshold order is validated and no threshold is hardcoded in controllers.

## Pure evaluation

`DeviceLockPolicyService` reads real EMI overdue amount and the oldest overdue schedule, applies the configured grace, and returns `warning`, `partial_lock`, `full_lock`, `active` (unlock recommendation), or `no_action`. It never creates or applies a device command.

`AutomaticDeviceCommandService` converts a recommendation into a strictly typed command only after enrollment, consent, management-mode, capability, release, manual-pause, and duplicate checks. The idempotent `devices:evaluate-lock-policies` command processes eligible devices and prints a summary. A scheduler run alone cannot lock a device unless the stored financial state and active policy require it.

## Payment clearance

After a verified payment recalculates overdue state, an active policy with `unlock_on_payment_clearance` can queue an unlock for warning/partially/full locked devices. This changes only desired state. The device remains actually locked until its authenticated agent reports successful application.

## Manual actions

Manual warning, partial lock, full lock, and unlock are separately permission protected and audited. Active equivalent commands are deduplicated. `Idempotency-Key` is unique per device. Applied lock commands cannot be cancelled; an authorized unlock must be queued instead.

All enforcement on Android must remain capability-driven and use documented Android Enterprise, Device Owner, fully managed, and `DevicePolicyManager` behavior. Unsupported restrictions must be reported, never simulated.
