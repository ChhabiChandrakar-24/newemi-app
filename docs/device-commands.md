# Device command backend

## Security boundary

Commands are a validated business protocol for legitimately enrolled `device_owner` or `fully_managed` Android agents. They are not shell commands, Android intents, executable URLs, wipe requests, exploits, or bypasses. A device code, IMEI, or serial number is never accepted as authentication; only the separate hashed, revocable device credential can use agent endpoints.

The backend verifies current consent, enrollment, release state, management mode, and reported capability before queueing. Unsupported operations fail with a stable business code such as `unsupported_capability`, `unsupported_management_mode`, `consent_required`, `device_not_enrolled`, or `device_released`.

## Desired versus actual state

`devices.desired_control_status` records the state requested by an active command. `devices.control_status` is the last state successfully reported by the agent. Queueing or dispatching never changes actual state. Only a valid successful result for that device changes it. Failed, expired, or cancelled commands leave actual state unchanged.

## Types and lifecycle

Allowed types are `show_warning`, `partial_lock`, `full_lock`, `unlock`, `policy_sync`, `refresh_status`, and `release_prepare`. Destructive wipe is intentionally absent.

Normal lifecycle:

`queued -> dispatched -> received -> acknowledged -> applied`

An application failure can terminate a received or acknowledged command as `failed`. A queued or dispatched command can be cancelled. Active commands become `expired` after their deadline. Invalid arbitrary transitions are rejected.

## Delivery and retry

The agent calls `GET /api/v1/device/commands/next`. Selection uses a transaction and row lock, is scoped to the authenticated device, skips expired/unavailable commands, and orders by priority then request time. A stale dispatch is retried only until `DEVICE_COMMAND_MAX_RETRIES`; exhaustion becomes `failed`. `DEVICE_COMMAND_ACK_TIMEOUT_MINUTES` controls receipt timeout and `DEVICE_COMMAND_TTL_MINUTES` controls default expiry.

Polling and the database remain authoritative. The notifier abstraction selects the polling no-op when Firebase is disabled and the queued FCM notifier when configured; neither replaces command records or acknowledgements.

When Firebase is enabled, the notifier queues an HTTP v1 wake-up job. Its push attempt status is deliberately separate from command status. FCM failure, throttling, or device offline state never removes or applies the command; authenticated polling and reconciliation remain authoritative.

The agent reports receipt, acknowledgement, and a strict result containing only application outcome, resulting control state, safe result code/message, capability snapshot, and policy version. It cannot update another device's command.

## Safe payload

Payloads may contain company name, minimal customer display name, overdue amount, support contact/message, issue time, and command type. Passwords, KYC, staff details, executable content, arbitrary privileged URLs, and internal secrets are excluded.

`policy_sync` additionally permits only a bounded policy version, validated package-name allowlist, and four explicit boolean restriction flags (factory reset, safe boot, user changes, and unknown sources). Unknown settings are discarded and the Android agent must still capability-check every requested restriction.

## Audit and events

Queueing, cancellation, failed application, policy-generated actions, and payment-generated unlocks are audited. Device events include command queued/received/applied/failed plus warning, partial lock, full lock, and unlock application events.
