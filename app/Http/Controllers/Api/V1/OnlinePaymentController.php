<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\EmiAccount;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\DeviceCommandService;
use App\Services\PaymentVerificationService;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnlinePaymentController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $gatewayManager,
        private readonly PaymentVerificationService $verificationService,
        private readonly DeviceCommandService $commandService,
    ) {}

    /**
     * Create Razorpay order for Android Device
     */
    public function createDeviceOrder(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $account = $device->emiAccount;

        if (! $account) {
            return response()->json(['message' => 'No active EMI account associated with this device.'], 404);
        }

        return $this->processOrderCreation($account, (float) ($request->input('amount') ?? $account->outstanding_amount));
    }

    /**
     * Verify payment for Android Device
     */
    public function verifyDevicePayment(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $account = $device->emiAccount;

        if (! $account) {
            return response()->json(['message' => 'No active EMI account associated with this device.'], 404);
        }

        return $this->processPaymentVerification($request, $account);
    }

    /**
     * Create Razorpay order from Web Console
     */
    public function createWebOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'emi_account_id' => ['required', 'integer', 'exists:emi_accounts,id'],
            'amount' => ['nullable', 'numeric', 'min:1'],
        ]);

        $account = EmiAccount::query()->findOrFail($validated['emi_account_id']);
        $amount = (float) ($validated['amount'] ?? $account->outstanding_amount);

        return $this->processOrderCreation($account, $amount);
    }

    /**
     * Verify payment from Web Console
     */
    public function verifyWebPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'emi_account_id' => ['required', 'integer', 'exists:emi_accounts,id'],
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
            'amount' => ['nullable', 'numeric'],
        ]);

        $account = EmiAccount::query()->findOrFail($validated['emi_account_id']);

        return $this->processPaymentVerification($request, $account);
    }

    private function processOrderCreation(EmiAccount $account, float $amount): JsonResponse
    {
        if (Money::toPaise($account->outstanding_amount) <= 0) {
            return response()->json(['message' => 'This account has no outstanding balance.'], 422);
        }

        $gateway = PaymentGateway::query()
            ->where('is_enabled', true)
            ->where('is_default', true)
            ->first();

        if (! $gateway) {
            return response()->json(['message' => 'No enabled default payment gateway is configured.'], 422);
        }

        $payAmount = min($amount, (float) $account->outstanding_amount);
        if ($payAmount <= 0) {
            $payAmount = (float) $account->outstanding_amount;
        }

        try {
            $provider = $this->gatewayManager->provider($gateway->provider);
            $order = $provider->createPaymentOrder($gateway, [
                'amount' => $payAmount,
                'currency' => 'INR',
                'receipt' => 'rcpt_' . $account->id . '_' . time(),
                'emi_account_id' => $account->id,
                'customer_id' => $account->customer_id,
                'purpose' => 'EMI Payment - ' . $account->emi_account_code,
            ]);

            $customer = $account->customer;

            return response()->json([
                'success' => true,
                'order_id' => $order['order_id'],
                'amount' => $payAmount,
                'currency' => 'INR',
                'key_id' => $gateway->public_key,
                'gateway_provider' => $gateway->provider,
                'display_name' => $gateway->display_name,
                'emi_account_id' => $account->id,
                'account_code' => $account->emi_account_code,
                'customer' => [
                    'name' => $customer?->full_name,
                    'mobile' => $customer?->mobile_number,
                    'email' => $customer?->email,
                ],
            ]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Payment order creation failed: ' . $e->getMessage()], 500);
        }
    }

    private function processPaymentVerification(Request $request, EmiAccount $account): JsonResponse
    {
        $gateway = PaymentGateway::query()
            ->where('is_enabled', true)
            ->where('is_default', true)
            ->first();

        if (! $gateway) {
            return response()->json(['message' => 'No enabled default payment gateway is configured.'], 422);
        }

        $provider = $this->gatewayManager->provider($gateway->provider);
        $isValid = $provider->verifyPayment($gateway, [
            'razorpay_order_id' => $request->input('razorpay_order_id'),
            'razorpay_payment_id' => $request->input('razorpay_payment_id'),
            'razorpay_signature' => $request->input('razorpay_signature'),
        ]);

        if (! $isValid) {
            return response()->json(['message' => 'Invalid payment signature. Verification failed.'], 422);
        }

        $amount = (float) ($request->input('amount') ?? $account->outstanding_amount);
        if ($amount <= 0) {
            $amount = (float) $account->outstanding_amount;
        }

        $actor = $request->user() ?? User::query()->first();

        $payment = DB::transaction(function () use ($account, $amount, $request, $actor) {
            $payment = Payment::query()->create([
                'company_id' => $account->company_id,
                'emi_account_id' => $account->id,
                'customer_id' => $account->customer_id,
                'payment_code' => 'TMP-' . Str::ulid(),
                'payment_date' => now(),
                'amount' => $amount,
                'payment_method' => 'upi',
                'payment_type' => 'emi',
                'status' => 'pending',
                'transaction_reference' => $request->input('razorpay_payment_id'),
                'notes' => 'Online payment verified via Razorpay. Order ID: ' . $request->input('razorpay_order_id'),
                'collected_by' => $actor?->id,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $payment->updateQuietly(['payment_code' => sprintf('PAY-%06d', $payment->getKey())]);

            return $this->verificationService->verify($payment, $actor);
        });

        $account->refresh();

        // Auto-unlock any locked devices linked to this account
        $devices = Device::query()->where('emi_account_id', $account->id)->get();
        foreach ($devices as $d) {
            if (in_array($d->control_status, ['partial_lock', 'full_lock', 'lock', 'warning'], true) || in_array($d->desired_control_status, ['partial_lock', 'full_lock', 'lock'], true)) {
                try {
                    $this->commandService->queue($d, 'unlock', [
                        'remarks' => 'Automatic unlock upon successful online EMI payment via Razorpay',
                        'force' => true,
                    ], $actor, 'automatic');
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Could not auto-unlock device {$d->id} on payment: " . $e->getMessage());
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment verified successfully! EMI account balance has been updated.',
            'payment' => [
                'id' => $payment->id,
                'payment_code' => $payment->payment_code,
                'receipt_number' => $payment->receipt_number,
                'amount' => $payment->amount,
                'status' => $payment->status,
                'transaction_reference' => $payment->transaction_reference,
            ],
            'account' => [
                'id' => $account->id,
                'code' => $account->emi_account_code,
                'outstanding_amount' => $account->outstanding_amount,
                'total_paid' => $account->total_paid,
                'status' => $account->status,
                'emi_status' => $account->emi_status,
            ],
        ]);
    }
}
