<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmiSchedule;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmiBypassController extends Controller
{
    /**
     * Bypasses an EMI schedule (marks it as paid for testing purposes).
     */
    public function bypass(Request $request, EmiSchedule $schedule): JsonResponse
    {
        $account = $schedule->emiAccount;

        if ($schedule->status === 'paid') {
            return response()->json(['message' => 'Schedule is already paid.'], 400);
        }

        $amountToBypass = $schedule->outstanding_amount;

        $schedule->update([
            'status' => 'paid',
            'paid_amount' => $schedule->installment_amount,
            'outstanding_amount' => 0,
            'overdue_amount' => 0,
            'paid_at' => now(),
        ]);

        $account->update([
            'total_paid' => $account->total_paid + $amountToBypass,
            'outstanding_amount' => max(0, $account->outstanding_amount - $amountToBypass),
            // We do not decrease overdue_amount here because overdue recalculation might be complex,
            // but normally bypassing an overdue schedule should fix the overdue amount.
            'overdue_amount' => max(0, $account->overdue_amount - $schedule->overdue_amount),
            'last_payment_date' => now(),
        ]);

        // Create a dummy payment record for audit
        Payment::create([
            'payment_code' => 'PAY-BYPASS-' . strtoupper(Str::random(8)),
            'emi_account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'company_id' => $account->company_id,
            'payment_date' => now(),
            'amount' => 0, // 0 actual payment, it's a bypass
            'payment_method' => 'cash',
            'payment_type' => 'installment',
            'status' => 'verified',
            'notes' => 'Bypassed by Super Admin for testing.',
            'verified_at' => now(),
        ]);

        return response()->json([
            'message' => 'Installment bypassed successfully.',
            'schedule' => $schedule->refresh()
        ]);
    }

    /**
     * Changes the due date of an EMI schedule to test delays.
     */
    public function delay(Request $request, EmiSchedule $schedule): JsonResponse
    {
        $validated = $request->validate([
            'days_in_past' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $newDueDate = now()->subDays($validated['days_in_past'])->startOfDay();

        $schedule->update([
            'due_date' => $newDueDate,
            'grace_until' => $newDueDate->copy()->addDays(2), // Example grace period
            'status' => 'overdue',
        ]);

        // We also need to update the account's next_due_date if this was the pending one
        if ($schedule->emiAccount->next_due_date && $schedule->emiAccount->next_due_date->isAfter($newDueDate)) {
            $schedule->emiAccount->update(['next_due_date' => $newDueDate]);
        }

        return response()->json([
            'message' => "Installment due date moved to {$validated['days_in_past']} days in the past.",
            'schedule' => $schedule->refresh()
        ]);
    }
}
