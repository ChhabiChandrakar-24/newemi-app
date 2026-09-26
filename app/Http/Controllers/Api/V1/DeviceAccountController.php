<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceAccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device')->load(['customer', 'emiAccount.schedules', 'emiAccount.payments']);
        $account = $device->emiAccount;
        $customer = $device->customer;
        $gateway = PaymentGateway::query()->where('is_enabled', true)->where('is_default', true)->first();

        return response()->json(['data' => [
            'enrollment' => [
                'status' => $device->enrollment_status,
                'device_code' => $device->device_code,
                'management_mode' => $device->management_mode,
                'connectivity_status' => $device->connectivity_status,
            ],
            'customer' => $customer ? [
                'name' => $customer->full_name,
                'customer_code' => $customer->customer_code,
                'mobile_number' => $customer->mobile_number,
            ] : null,
            'account' => $account ? [
                'account_code' => $account->emi_account_code,
                'product' => $account->product_description,
                'total_installments' => $account->total_installments,
                'installment_amount' => $account->installment_amount,
                'total_payable' => $account->total_payable,
                'total_paid' => $account->total_paid,
                'outstanding_amount' => $account->outstanding_amount,
                'overdue_amount' => $account->overdue_amount,
                'overdue_installments' => $account->overdue_installments,
                'next_due_date' => $account->next_due_date?->toDateString(),
                'status' => $account->status,
            ] : null,
            'installments' => $account?->schedules->sortBy('installment_number')->values()->map(fn ($schedule) => [
                'number' => $schedule->installment_number,
                'due_date' => $schedule->due_date?->toDateString(),
                'amount' => $schedule->installment_amount,
                'paid_amount' => $schedule->paid_amount,
                'outstanding_amount' => $schedule->outstanding_amount,
                'overdue_amount' => $schedule->overdue_amount,
                'status' => $schedule->status,
            ])->all() ?? [],
            'payments' => $account?->payments->sortByDesc('payment_date')->take(20)->values()->map(fn ($payment) => [
                'code' => $payment->payment_code,
                'date' => ($payment->payment_date ?? $payment->created_at)?->toDateString(),
                'amount' => $payment->amount,
                'method' => $payment->payment_method,
                'status' => $payment->status,
                'receipt_number' => $payment->receipt_number,
            ])->all() ?? [],
            'payment' => [
                'online_available' => $gateway ? true : false,
                'provider' => $gateway?->provider,
                'display_name' => $gateway?->display_name,
                'public_key' => $gateway?->public_key,
                'currency' => 'INR',
                'amount_due' => $account?->outstanding_amount ?? 0,
                'message' => $gateway
                    ? 'Online payment is active via ' . $gateway->display_name . '.'
                    : 'Online payment is not configured. Please contact your finance provider.',
            ],
        ]]);
    }
}
