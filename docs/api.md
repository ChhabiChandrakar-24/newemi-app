# Backend API — Step 3

All endpoints use the `/api/v1` prefix, return JSON, and require a Sanctum bearer token unless marked public. Validation failures return HTTP `422`; missing authentication returns `401`; failed permission checks return `403`.

## Authentication

| Method | Route | Permission | Purpose |
|---|---|---|---|
| POST | `/auth/login` | Public | Exchange email, password, and optional `device_name` for a bearer token. |
| POST | `/auth/logout` | Authenticated | Revoke the current bearer token. |
| GET | `/me` | Authenticated | Return the current user, roles, and permissions. |

## Users

| Method | Route | Required permission |
|---|---|---|
| GET | `/users` | `users.view` |
| GET | `/users/{user}` | `users.view` |
| POST | `/users` | `users.create` |
| PUT | `/users/{user}` | `users.update` |
| PATCH | `/users/{user}/status` | `users.update` |
| DELETE | `/users/{user}` | `users.delete` |

List query parameters: `search` (name, email, or mobile), `status`, `role`, `sort` (`name`, `email`, `status`, `created_at`, `updated_at`), `direction`, and `per_page` (1–100; default 15).

Create and update fields are `name`, `email`, nullable `mobile_number`, `password`, `password_confirmation`, `status`, and `role`. Password is required on create, optional on update, and must be at least 12 characters with mixed case, a number, and a symbol. Email must remain globally unique, including against soft-deleted records. Mobile numbers use E.164-like format. Status is `active` or `inactive`.

Example resource:

```json
{
  "data": {
    "id": 12,
    "name": "Operations Manager",
    "email": "manager@example.com",
    "mobile_number": "+919876543210",
    "status": "active",
    "role": "manager",
    "created_at": "2026-08-09T06:30:00.000000Z",
    "updated_at": "2026-08-09T06:30:00.000000Z"
  }
}
```

Creates return `201`, reads/updates return `200`, and deletes return `204`. Users cannot deactivate or delete themselves. The final Super Admin cannot be demoted, deactivated, or deleted. Deactivation revokes all of that user's API tokens.

## Roles and permissions

| Method | Route | Required permission |
|---|---|---|
| GET | `/roles` | `roles.view` |
| GET | `/roles/{role}` | `roles.view` |
| POST | `/roles` | `roles.create` |
| PUT | `/roles/{role}` | `roles.update` |
| DELETE | `/roles/{role}` | `roles.delete` |
| GET | `/permissions` | `roles.view` |

Role create/update payloads contain a lowercase slug `name` and a `permissions` array whose values must exist in the permission catalog. Responses include the role's permissions. The built-in `super-admin`, `admin`, `manager`, `staff`, and `auditor` roles cannot be deleted; `super-admin` cannot be modified. A custom role assigned to users cannot be deleted.

```json
{
  "name": "collections-agent",
  "permissions": ["customers.view", "payments.view", "payments.create"]
}
```

Important user and role changes write immutable records to `audit_logs`. Passwords and password confirmations are excluded from audit payloads.

## Customers

| Method | Route | Required permission |
|---|---|---|
| GET | `/customers` | `customers.view` |
| GET | `/customers/{customer}` | `customers.view` |
| GET | `/customers/{customer}/summary` | `customers.view` |
| POST | `/customers` | `customers.create` |
| PUT | `/customers/{customer}` | `customers.update` |
| PATCH | `/customers/{customer}/status` | `customers.update` |
| DELETE | `/customers/{customer}` | `customers.delete` |

List filters are `search`, `status`, `consent_given`, `city`, `state`, `sort`, `direction`, and `per_page`. Search covers customer code, name, both mobile numbers, and email. Allowed sort fields are `created_at`, `full_name`, and `customer_code`; pagination defaults to 15 and is capped at 100.

Create and update accept `full_name`, `mobile_number`, nullable `alternate_mobile_number`, nullable `email`, address fields, `country`, optional generic `identity_type` and `identity_number`, optional past `date_of_birth`, `status`, nullable `notes`, and explicit `consent_given`. Indian mobile numbers are normalized to `+91XXXXXXXXXX`; Indian postal codes must contain six digits and cannot start with zero. Customer names and primary mobiles are intentionally not unique, so possible duplicates remain separate records for human review rather than being silently merged.

```json
{
  "full_name": "Aarav Sharma",
  "mobile_number": "98765 43210",
  "email": "aarav@example.com",
  "city": "Jaipur",
  "state": "Rajasthan",
  "postal_code": "302001",
  "country": "India",
  "status": "active",
  "consent_given": false
}
```

```json
{
  "data": {
    "id": 1,
    "customer_code": "CUS-000001",
    "full_name": "Aarav Sharma",
    "mobile_number": "+919876543210",
    "alternate_mobile_number": null,
    "email": "aarav@example.com",
    "address": {
      "line_1": null,
      "line_2": null,
      "city": "Jaipur",
      "state": "Rajasthan",
      "postal_code": "302001",
      "country": "India"
    },
    "status": "active",
    "consent": {"given": false, "given_at": null},
    "created_at": "2026-08-09T07:00:00.000000Z",
    "updated_at": "2026-08-09T07:00:00.000000Z"
  }
}
```

Customer creation never assumes consent. Granting consent records its timestamp; withdrawing consent changes the current flag while retaining the prior grant timestamp and immutable audit history. Deletes are soft deletes. The summary currently reports only the customer resource, audit event count, and days as a customer; it does not invent EMI or device statistics. Identity numbers are stored only when explicitly provided, never returned by the API, and excluded from audit snapshots.

## EMI accounts and schedules

| Method | Route | Required permission |
|---|---|---|
| GET | `/emi-accounts` | `emi.view` |
| GET | `/emi-accounts/{emiAccount}` | `emi.view` |
| GET | `/emi-accounts/{emiAccount}/summary` | `emi.view` |
| GET | `/emi-accounts/{emiAccount}/schedule` | `emi.view` |
| GET | `/customers/{customer}/emi-accounts` | `emi.view` |
| POST | `/emi-accounts` | `emi.create` |
| PUT | `/emi-accounts/{emiAccount}` | `emi.update` |
| PATCH | `/emi-accounts/{emiAccount}/status` | `emi.close` |

The list accepts `search`, `customer_id`, `status`, `invoice_number`, `emi_account_code`, `overdue`, `due_from`, `due_to`, `created_from`, `created_to`, `sort`, `direction`, and `per_page`. Search covers account code, invoice number, customer name, and customer mobile. Sort is restricted to `created_at`, `emi_start_date`, `next_due_date`, `outstanding_amount`, and `overdue_amount`.

Create fields are `customer_id`, `invoice_number`, nullable `invoice_date`, nullable `product_description`, `financed_amount`, optional `down_payment`, `total_installments`, `emi_start_date`, nullable `due_day`, optional `grace_period_days`, optional `interest_amount`, optional `processing_fee`, optional `other_charges`, optional `status` (`draft` or `active`), optional `auto_lock_enabled`, and nullable `notes`. The customer must be active. All calculated balance and total fields are prohibited in client input.

```json
{
  "customer_id": 1,
  "invoice_number": "INV-2026-001",
  "financed_amount": "10000.00",
  "down_payment": "1000.00",
  "total_installments": 3,
  "emi_start_date": "2026-01-31",
  "grace_period_days": 2,
  "interest_amount": "300.00",
  "processing_fee": "100.00",
  "other_charges": "50.00",
  "status": "draft",
  "auto_lock_enabled": false
}
```

Creation returns HTTP `201` with an `EMI-000001`-style code and generates the complete monthly schedule transactionally. Account details and list responses include server-calculated totals. The summary contains the account/customer data and actual paid, pending, and overdue schedule counts. The schedule endpoint returns installments in numeric order and does not fabricate payment data.

PUT requires the complete editable account input. Draft accounts may change financial terms and regenerate their schedule. Active/overdue accounts may update only invoice metadata, product description, the future-facing `auto_lock_enabled` flag, and notes; the flag performs no device action in Step 5. Status changes use the PATCH endpoint and validated transitions.

## Payments and settlement

| Method | Route | Required permission |
|---|---|---|
| GET | `/payments` | `payments.view` |
| GET | `/payments/{payment}` | `payments.view` |
| POST | `/payments` | `payments.create` |
| PATCH | `/payments/{payment}/verify` | `payments.verify` |
| PATCH | `/payments/{payment}/cancel` | `payments.update` |
| POST | `/payments/{payment}/reverse` | `payments.reverse` |
| GET | `/payments/{payment}/receipt` | `payments.view` |
| GET | `/emi-accounts/{emiAccount}/payments` | `payments.view` |
| GET | `/customers/{customer}/payments` | `payments.view` |
| POST | `/emi-accounts/{emiAccount}/settlement-preview` | `payments.settlement` |
| POST | `/emi-accounts/{emiAccount}/settle` | `payments.settlement` |

Payment creation accepts `emi_account_id`, `payment_date`, `amount`, `payment_method`, nullable `transaction_reference`, nullable `external_reference`, `payment_type`, optional `status` (`pending` or `verified`), and nullable `notes`. A direct verified payment additionally requires `payments.verify`. Customer, receipt, verification identity, and all balances are server-managed. Supported methods are `cash`, `upi`, `bank_transfer`, `card`, `cheque`, and `other`; no card credentials or UPI PIN fields exist.

```json
{
  "emi_account_id": 10,
  "payment_date": "2026-08-09",
  "amount": "3000.00",
  "payment_method": "upi",
  "transaction_reference": "UPI-REFERENCE-123",
  "payment_type": "emi",
  "status": "pending",
  "notes": null
}
```

`Idempotency-Key` is an optional request header (maximum 100 characters). Repeating the same compatible request returns the original payment with HTTP `200`; first creation returns `201`. Pending payments create no allocations and do not change balances. Verification generates an `RCT-YYYY-000001`-style receipt and allocates funds. Overpayments above current outstanding are rejected.

Verification has no request body. Cancellation accepts optional `remarks` and is limited to pending payments. Reversal requires a `reason` of 5–2000 characters. Reversed payments and allocation rows remain historically visible; their monetary effect is removed transactionally.

Payment list filters are `search`, `payment_code`, `receipt_number`, `emi_account_id`, `customer_id`, `payment_method`, `payment_type`, `status`, `collected_by`, payment/created date ranges, `sort`, `direction`, and `per_page`. Search covers payment/receipt/reference identifiers, EMI account code, invoice, customer name, and mobile. Sorting is limited to `payment_date`, `amount`, and `created_at`.

Settlement preview accepts optional `discount_amount`. Settlement accepts `settlement_amount`, optional `discount_amount`, `payment_method`, `payment_date`, nullable `transaction_reference`, and required `remarks`. Discounts require `payments.discount`, cannot reach/exceed outstanding, and are capped at 50%. Submitted cash must exactly equal outstanding minus discount.

Receipts return real payment, customer, account, collector, allocation, and remaining-balance data. No company block is fabricated because Company Settings are not implemented.

## Devices and enrollment

| Method | Route | Authentication / permission |
|---|---|---|
| GET | `/devices` | Sanctum + `devices.view` |
| GET | `/devices/{device}` | Sanctum + `devices.view` |
| GET | `/devices/{device}/summary` | Sanctum + `devices.view` |
| POST | `/devices` | Sanctum + `devices.create` |
| PUT | `/devices/{device}` | Sanctum + `devices.update` |
| PATCH | `/devices/{device}/status` | Sanctum + `devices.update` |
| DELETE | `/devices/{device}` | Sanctum + `devices.update` |
| POST | `/devices/{device}/release` | Sanctum + `devices.release` |
| GET | `/customers/{customer}/devices` | Sanctum + `devices.view` |
| GET | `/emi-accounts/{emiAccount}/devices` | Sanctum + `devices.view` |
| POST | `/devices/{device}/enrollment-token` | Sanctum + `devices.enroll` |
| DELETE | `/device-enrollments/{enrollment}` | Sanctum + `devices.enroll` |
| POST | `/device-enrollment/claim` | One-time enrollment token; throttled |
| POST | `/device/heartbeat` | Device credential only; throttled |
| GET | `/devices/{device}/events` | Sanctum + `devices.events.view` |
| PATCH | `/device-events/{event}/acknowledge` | Sanctum + `devices.events.manage` |

Registration accepts `customer_id`, nullable `emi_account_id`, display/hardware metadata, optional IMEI/serial, invoice reference, and notes. It returns a pending/unmanaged record with server-generated `DEV-000001` code and UUID. Neither value is an authentication secret. The customer/EMI relationship is validated and cancelled/closed accounts are rejected.

The device list supports search across device/customer/invoice/brand/model and optional IMEI, filters for customer, account, enrollment/control/connectivity/compliance/management states, offline-only and last-seen ranges, bounded pagination, and sorting limited to `created_at`, `last_seen_at`, `device_code`, `brand`, and `model`.

Enrollment-token creation returns plaintext only once; only its SHA-256 hash persists. Claim requires token, installation identifier, Android/app versions, management mode, and boolean capability declarations. Successful claim consumes the token and returns a separate scoped bearer credential once. The installation identifier and device credential are hashed server-side.

Heartbeat accepts only bounded device/app versions, battery, network, management/compliance/policy state, capabilities, privacy-preserving SIM marker, and supported security events. Location, hardware identity overwrite, and control/enrollment state are prohibited. Unsupported fields return validation errors.

Administrative status updates change backend state only and explicitly issue no warning/lock/unlock command. Release requires a completed/closed linked EMI account, revokes credentials/enrollment tokens, and prepares backend release only.
# Step 8 device commands and lock policies

# Step 10 push and consent-based location

| Method | Endpoint | Authentication / purpose |
|---|---|---|
| POST | `/device/push-token` | Device credential; register/rotate encrypted FCM token |
| DELETE | `/device/push-token` | Device credential; revoke active token |
| POST | `/device/location` | Device credential and active consent; bounded location report |
| PUT | `/devices/{device}/location-settings` | `devices.location.manage`; consent/mode change |
| GET | `/devices/{device}/location` | `devices.location.view`; settings and last known point |
| GET | `/devices/{device}/locations` | `devices.location.view`; bounded filtered history |

Heartbeat additionally accepts `location_permission_state` and `location_feature_enabled`; only permission state updates device telemetry because backend consent remains authoritative. Raw push tokens never appear in resources. Command resources expose separate `delivery` and `execution` objects.

All routes below are under `/api/v1`. Admin routes require Sanctum and their named RBAC permission. Agent routes require the separate device credential.

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/devices/{device}/commands/warning` | Queue safe warning |
| POST | `/devices/{device}/commands/partial-lock` | Queue supported partial restriction |
| POST | `/devices/{device}/commands/full-lock` | Queue supported full restricted/lock-task state |
| POST | `/devices/{device}/commands/unlock` | Queue restoration of permitted usage |
| POST | `/devices/{device}/commands/policy-sync` | Queue policy synchronization |
| POST | `/devices/{device}/commands/refresh-status` | Request current status |
| GET | `/devices/{device}/commands` | Filtered command history |
| GET | `/device-commands/{command}` | Command detail |
| POST | `/device-commands/{command}/cancel` | Cancel eligible command |
| GET | `/device/commands/next` | Atomically dispatch next command to its device |
| POST | `/device/commands/{command}/received` | Confirm receipt |
| POST | `/device/commands/{command}/acknowledge` | Confirm processing acceptance |
| POST | `/device/commands/{command}/result` | Report safe applied/failed result |
| GET/POST | `/lock-policies` | List/create policies |
| GET/PUT | `/lock-policies/{policy}` | Read/update policy |
| PATCH | `/lock-policies/{policy}/status` | Activate/deactivate policy |
| PUT | `/devices/{device}/lock-policy` | Assign override or automation pause |

Command creation accepts remarks, reason, optional expiry, an `Idempotency-Key` header, and allowlisted warning/support display fields. Arbitrary payloads, command names, executable URLs, intents, and priority manipulation are rejected. History filters support command type, status, requester, date range, and pagination. See `device-commands.md` and `lock-policies.md` for lifecycle and policy semantics.
