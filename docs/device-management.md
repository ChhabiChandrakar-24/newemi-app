# Device management foundation and boundaries

## Identity and secrets

`device_code` is a human-readable business reference. `internal_device_uuid` is the primary backend device identity. Neither is a secret, and neither device code, UUID, IMEI, serial, mobile number, nor invoice number authenticates an agent.

IMEI and serial are optional because modern Android restricts hardware identifiers and their collection may be legally constrained. The API never requires them. An Android-generated installation identifier is stored only as a SHA-256 hash.

Enrollment tokens and device credentials use cryptographically secure random values. Only hashes are stored. Enrollment tokens expire, are single-use, can be revoked, and belong to one device. Credentials belong to one device, expire, can be revoked, support multiple records for future rotation, and authenticate only device-agent routes. HTTPS is assumed.

## Enrollment and consent

1. Authorized staff registers a pending/unmanaged device.
2. The customer must have current explicit consent. A linked EMI account must be active/overdue.
3. An authorized manager creates a short-lived one-time enrollment token.
4. The agent claims it with installation identity, platform/app metadata, management mode, and capability declarations.
5. The backend consumes the token, records consent verification, marks enrollment complete, and returns a device credential once.

Registration never implies consent or enrollment. Released devices cannot silently re-enroll. Failed, expired, reused, and revoked enrollment attempts are rejected and eligible failures generate security events/audit records. Claim and heartbeat routes are rate-limited.

## State separation and heartbeat

- `control_status`: active, warning, partial lock, full lock, unlocked, closed, released.
- `connectivity_status`: online, offline, unknown.
- `enrollment_status`: pending, enrolled, suspended, failed, released.
- `compliance_status`: compliant, non-compliant, attention required, unknown.

These concepts are deliberately separate. `devices:mark-offline` changes connectivity only after `DEVICE_HEARTBEAT_TIMEOUT_MINUTES`; it never overwrites control state and emits one unacknowledged offline event per transition. A valid heartbeat restores online state.

Heartbeat stores only operationally bounded telemetry: versions, SDK, battery when reportable, charging/network state, management/compliance/policy state, capabilities, and optional privacy-preserving SIM state/fingerprint. No precise location, SMS, call logs, microphone/camera data, raw credentials, or surveillance telemetry is accepted.

## Capabilities and Android management

Capabilities are declarations, not guarantees. Supported flags include warning display, lock-task/restriction support, battery/management/compliance reporting, SIM-change detection, management-removal protection, factory-reset/safe-boot/user-change/unknown-source restrictions, and DevicePolicyManager restriction support.

A later Android agent must always:

1. check Android version;
2. check management mode;
3. check the specific capability;
4. use only documented Android Enterprise, Device Owner, fully managed, or dedicated-device APIs;
5. report applied, failed, or unsupported results.

Absent/false capability means unsupported. The backend does not pretend a restriction succeeded. Universal uninstall prevention, factory-reset prevention, SIM detection, safe-boot restriction, or policy enforcement is not promised. Ordinary agent removal and factory-reset restrictions are possible only where the documented management mode/API permits them.

## Security and tamper events

The agent may report only allow-listed events it can legitimately observe: management/policy/permission changes, compliance loss, supported SIM marker changes, outdated agent, enrollment/authentication failures, and possible tamper. Backend-generated online/offline, enrollment, credential-revocation-use, and lifecycle events use the same immutable stream.

Severity is `info`, `warning`, `high`, or `critical`. Authorized staff can filter and acknowledge events. Unacknowledged high/critical events and summary counts form the dashboard alert feed; no separate fabricated alert is created. Event metadata is allow-listed and excludes secrets, location, and raw SIM identifiers.

## Release and command boundary

Release requires the linked EMI account to be completed/closed, revokes all active credentials and enrollment tokens, and marks backend states released. It performs no wipe, policy removal, or Android deprovisioning.

Step 7 stores control-status labels and future capability foundations only. It does not enqueue or execute warning, partial-lock, full-lock, unlock, release, DevicePolicyManager, FCM, or Android commands. Those belong to Step 8 and later, remain capability-driven, and may not use root, exploits, undocumented bypasses, stealth surveillance, or hidden tracking.

## Configuration

```dotenv
DEVICE_HEARTBEAT_TIMEOUT_MINUTES=30
DEVICE_ENROLLMENT_TOKEN_TTL_MINUTES=30
DEVICE_CREDENTIAL_TTL_DAYS=365
DEVICE_MIN_AGENT_VERSION=
```
