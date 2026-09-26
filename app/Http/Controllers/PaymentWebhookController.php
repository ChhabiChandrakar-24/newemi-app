<?php

namespace App\Http\Controllers;

use App\Models\CompanyRecharge;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PaymentWebhookLog;
use App\Services\CompanyRechargeService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        PaymentGatewayManager $manager,
        CompanyRechargeService $rechargeService
    ): JsonResponse {
        $rawContent = $request->getContent();
        $payload = json_decode($rawContent, true) ?? [];
        $event = $payload['event'] ?? $request->input('event') ?? 'unknown';

        $key = $request->header('X-Gateway-Key');
        $gateway = null;

        if ($key) {
            $gateway = PaymentGateway::withoutGlobalScopes()
                ->where('provider', $provider)
                ->where('webhook_key', $key)
                ->where('is_enabled', true)
                ->first();
        }

        if (!$gateway) {
            $gateway = PaymentGateway::withoutGlobalScopes()
                ->where('provider', $provider)
                ->where('is_enabled', true)
                ->first();
        }

        abort_unless($gateway, 404, 'Payment gateway not found.');

        // Verify webhook signature (supports both X-Razorpay-Signature and X-Payment-Signature)
        $signature = $request->header('X-Razorpay-Signature')
            ?? $request->header('X-Payment-Signature')
            ?? $request->header('X-Hub-Signature-256');

        if (filled($gateway->encrypted_webhook_secret)) {
            $isSignatureValid = $manager->provider($provider)->verifyWebhook($gateway, $rawContent, $signature);
            abort_unless($isSignatureValid, 401, 'Invalid webhook signature.');
        }

        // Extract identifiers from payload
        $paymentId = null;
        $orderId = null;

        if (isset($payload['payload']['payment']['entity'])) {
            $paymentEntity = $payload['payload']['payment']['entity'];
            $paymentId = $paymentEntity['id'] ?? null;
            $orderId = $paymentEntity['order_id'] ?? null;
        } elseif (isset($payload['payload']['order']['entity'])) {
            $orderEntity = $payload['payload']['order']['entity'];
            $orderId = $orderEntity['id'] ?? null;
        } elseif (isset($payload['payload']['refund']['entity'])) {
            $refundEntity = $payload['payload']['refund']['entity'];
            $paymentId = $refundEntity['payment_id'] ?? null;
        }

        // 1. Log incoming webhook
        $webhookLog = PaymentWebhookLog::create([
            'provider' => $provider,
            'event' => $event,
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'payload' => $payload,
            'status' => 'received',
        ]);

        try {
            // 2. Process events
            switch ($event) {
                case 'payment.captured':
                case 'order.paid':
                    $this->handlePaymentSuccess($payload, $paymentId, $orderId, $rechargeService);
                    break;

                case 'payment.failed':
                    $this->handlePaymentFailed($payload, $paymentId, $orderId);
                    break;

                case 'refund.created':
                case 'refund.processed':
                case 'payment.refunded':
                    $this->handlePaymentRefund($payload, $paymentId, $rechargeService);
                    break;

                default:
                    // Other informational events (subscription.activated, etc.)
                    break;
            }

            $webhookLog->update(['status' => 'processed']);
        } catch (\Throwable $e) {
            Log::error("Webhook processing error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $webhookLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'accepted' => true,
            'event' => $event,
            'status' => 'processed',
            'message' => 'Signature verified. Webhook event processed successfully.',
        ], 202);
    }

    private function handlePaymentSuccess(array $payload, ?string $paymentId, ?string $orderId, CompanyRechargeService $rechargeService): void
    {
        $paymentEntity = $payload['payload']['payment']['entity'] ?? [];
        $notes = $paymentEntity['notes'] ?? [];

        // Check if this payment matches a CompanyRecharge
        $recharge = null;

        if ($paymentId) {
            $recharge = CompanyRecharge::query()
                ->where('payment_reference', $paymentId)
                ->first();
        }

        if (!$recharge && $orderId) {
            $recharge = CompanyRecharge::query()
                ->where('notes', 'like', "%{$orderId}%")
                ->first();
        }

        if (!$recharge && !empty($notes['recharge_code'])) {
            $recharge = CompanyRecharge::query()
                ->where('recharge_code', $notes['recharge_code'])
                ->first();
        }

        if ($recharge) {
            if ($recharge->payment_status !== 'successful') {
                $recharge->update([
                    'payment_status' => 'successful',
                    'payment_reference' => $paymentId ?? $recharge->payment_reference,
                ]);

                $company = $recharge->company;
                $currentExpiresAt = $company->expires_at;
                $isCurrentlyActive = ($currentExpiresAt && $currentExpiresAt->isFuture() && $company->subscription_status === 'active');

                if ($isCurrentlyActive) {
                    $recharge->update(['recharge_status' => 'queued']);
                } else {
                    $rechargeService->activateQueuedRecharge($recharge, null, true);
                }
            }
            return;
        }

        // Check if this matches a customer EMI installment payment
        if ($paymentId) {
            $payment = Payment::query()
                ->where('gateway_transaction_id', $paymentId)
                ->first();

            if ($payment && $payment->status !== 'completed') {
                $payment->update([
                    'status' => 'completed',
                    'verified_at' => now(),
                ]);
            }
        }
    }

    private function handlePaymentFailed(array $payload, ?string $paymentId, ?string $orderId): void
    {
        if ($paymentId) {
            CompanyRecharge::query()
                ->where('payment_reference', $paymentId)
                ->where('payment_status', 'pending')
                ->update([
                    'payment_status' => 'failed',
                    'recharge_status' => 'rejected',
                ]);
        }
    }

    private function handlePaymentRefund(array $payload, ?string $paymentId, CompanyRechargeService $rechargeService): void
    {
        if (!$paymentId) return;

        $recharge = CompanyRecharge::query()
            ->where('payment_reference', $paymentId)
            ->first();

        if ($recharge && $recharge->payment_status !== 'refunded') {
            $rechargeService->refundRecharge($recharge, null, 'Automated Razorpay Webhook Refund');
        }
    }
}
