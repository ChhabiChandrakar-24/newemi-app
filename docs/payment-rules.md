# Payment, allocation, reversal, and settlement rules

## Status and verification

Payments begin as `pending` unless an actor with `payments.verify` explicitly creates them verified. Pending payments have no allocations and do not change EMI balances. Only `pending → verified` is supported for verification; repeat verification is rejected without duplicate allocation. Pending payments may be cancelled. Verified payments are never deleted and may only be neutralized through reversal.

Verification generates a unique receipt number in `RCT-YYYY-000001` format. Receipt data remains available after reversal and clearly reports reversed status and reason.

## Deterministic allocation

All arithmetic converts decimal strings to integer paise. A verified payment is allocated in this order:

1. overdue schedules;
2. due schedules;
3. partially paid schedules;
4. remaining pending schedules by due date and installment number.

Each allocation is capped at the schedule's outstanding amount. Payment creation and verification both reject amounts above current account outstanding. The sum of non-adjustment allocations must equal actual payment amount; no wallet or credit balance is created.

Fully funded schedules become `paid` and receive `paid_at`; partially funded schedules become `partially_paid`. The existing overdue service then recalculates due/overdue values, account totals, last/next dates, and status.

## Concurrency and duplicate protection

Account, payment, and affected schedule rows use database transactions and `SELECT ... FOR UPDATE` while money is allocated or reversed. Payment creation supports a persisted, unique optional `Idempotency-Key`; compatible retries return the existing payment. Database unique constraints remain the final concurrent duplicate barrier.

Payment codes and receipt numbers are display/reference identifiers, never authorization credentials.

## Reversal

Only verified payments can be reversed, and a reason is mandatory. Reversal keeps the payment, receipt, and allocation rows, changes payment status to `reversed`, removes cash effects from schedules, disables that payment's adjustment effects, and recalculates the account. A completed account explicitly reopens to financial `active`/`overdue` state when reversal restores a balance. Reversing twice is rejected.

## Settlement and discounts

Settlement is available only for active/overdue accounts. The server computes:

`final settlement payable = current outstanding - approved discount`

Submitted settlement cash must match this value exactly. Discounts require `payments.discount`, must be below outstanding, and are capped at 50% of outstanding. Cash becomes ordinary `installment` allocations. Discount is recorded separately as explicit `adjustment` allocations; it is never reported as payment cash.

The invariant is:

`cash allocations + adjustment allocations = pre-settlement outstanding`

Successful settlement leaves outstanding at zero and status `completed`, never `closed`. Both settlement and discount are audited with cash and adjustment amounts. Reversing the settlement removes both effects while preserving ledger history.

## Reconciliation and security

`php artisan payments:reconcile` reports mismatches between verified payments, cash allocations, and account `total_paid`. It is deliberately report-only and never repairs data.

No gateway integration, raw card data, UPI PIN, device control, lock command, Android behavior, customer wallet, PDF generation, or location tracking exists in Step 6.
