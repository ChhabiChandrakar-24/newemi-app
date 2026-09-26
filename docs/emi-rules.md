# EMI calculation and lifecycle rules

## Financial fields

- `financed_amount` is the item amount submitted for financing.
- `down_payment` is deducted from financed amount.
- `principal_amount = financed_amount - down_payment`.
- `total_payable = principal_amount + interest_amount + processing_fee + other_charges`.
- `total_paid`, outstanding values, overdue values, and next/last payment dates are server-managed. Step 5 creates no payments and exposes no payment endpoint.

All calculations convert decimal input to integer paise before arithmetic. Binary floating-point arithmetic is not used for money.

## Schedule allocation and rounding

The base installment is integer division of total payable paise by the installment count. Every installment except the last receives that base amount. The final installment receives the remaining paise, guaranteeing:

`sum(schedule.installment_amount) == account.total_payable`

For ₹10,000 over three installments, the amounts are ₹3,333.33, ₹3,333.33, and ₹3,333.34. Principal and stated interest are allocated similarly; processing fees and other charges are included in the overall installment amount rather than mislabeled as interest.

## Monthly dates

Each date is calculated from the original `emi_start_date` with Carbon's no-overflow month addition. This preserves the intended anchor where possible: January 31 produces January 31, February 28 (or 29), March 31, and so on. `due_day` records the preferred day and defaults to the start-date day; generated schedule dates remain authoritative.

## Grace periods and overdue recalculation

`grace_until` is the due date plus `grace_period_days`, inclusive. Recalculation applies these rules as of the supplied/current date:

- before due date: `pending` (or `partially_paid` when a partial amount exists);
- due date through `grace_until`: `due` (or `partially_paid`);
- after `grace_until`: `overdue`, with unpaid paise included in overdue totals;
- fully paid remains `paid`;
- waived and cancelled installments are not made overdue.

The account becomes `overdue` when any installment is overdue and returns to `active` when no overdue installment remains. With zero outstanding it becomes `completed`. The reusable command is:

```shell
php artisan emi:recalculate-overdue
```

For deterministic operations/tests, `--date=YYYY-MM-DD` is supported. The command processes only active/overdue accounts, creates no rows, is idempotent, and performs no device or lock action.

## Account status transitions

- `draft` → `active`, `cancelled`
- `active` → `overdue`, `completed`, `cancelled`, `closed`
- `overdue` → `active`, `completed`, `cancelled`, `closed`
- `completed` → `closed`
- `cancelled` → `closed`
- `closed` → no further state

Completion is rejected while any outstanding balance remains. Status changes, cancellation, closure, account changes, and schedule generation/regeneration are audited.

## Editing and history

Draft accounts may change schedule-affecting financial terms; the old schedule is replaced inside the same transaction and a regeneration audit event is recorded. Once active or overdue, financial terms and customer association are protected, while non-financial metadata remains editable. EMI accounts are soft-deletable at the model level, but Step 5 deliberately provides no delete API. Schedule foreign keys restrict hard deletion so financial history is not cascaded away.

`auto_lock_enabled` is stored only as future policy metadata. Step 5 has no devices, enrollment, lock policy, remote command, or Android behavior.
