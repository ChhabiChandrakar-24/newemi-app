<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayProviderInterface;
use App\Models\PaymentGateway;
use DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RazorpayGatewayProvider implements PaymentGatewayProviderInterface
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function getProviderName(): string
    {
        return 'razorpay';
    }

    public function testConnection(PaymentGateway $gateway): array
    {
        $keyId = trim((string) $gateway->public_key);
        $keySecret = trim((string) $gateway->encrypted_secret);

        if (blank($keyId) || blank($keySecret)) {
            return [
                'success' => false,
                'status' => 'not_configured',
                'message' => 'Both Key ID and Key Secret are required to test the Razorpay connection.',
            ];
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(12)
                ->withBasicAuth($keyId, $keySecret)
                ->get(self::BASE_URL . '/orders', ['count' => 1]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => 'live_verified',
                    'message' => 'Connection to Razorpay API verified successfully. Credentials are valid.',
                    'details' => [
                        'http_status' => $response->status(),
                        'environment' => $gateway->environment,
                    ],
                ];
            }

            $errorDescription = $response->json('error.description')
                ?? $response->json('error.code')
                ?? 'Authentication or request failed.';

            return [
                'success' => false,
                'status' => 'authentication_failed',
                'message' => "Razorpay connection failed ({$response->status()}): {$errorDescription}",
                'details' => [
                    'http_status' => $response->status(),
                    'error' => $response->json('error'),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'connection_error',
                'message' => 'Could not connect to Razorpay API: ' . $e->getMessage(),
            ];
        }
    }

    public function createPaymentOrder(PaymentGateway $gateway, array $order): array
    {
        $keyId = trim((string) $gateway->public_key);
        $keySecret = trim((string) $gateway->encrypted_secret);

        if (blank($keyId) || blank($keySecret)) {
            throw new DomainException('Razorpay Key ID and Key Secret are not configured.');
        }

        $amountInPaise = (int) round(((float) ($order['amount'] ?? 0)) * 100);
        if ($amountInPaise <= 0) {
            throw new DomainException('Payment order amount must be greater than zero.');
        }

        $notes = is_array($order['notes'] ?? null) ? $order['notes'] : [];
        if (!empty($order['emi_account_id'])) $notes['emi_account_id'] = (string) $order['emi_account_id'];
        if (!empty($order['customer_id'])) $notes['customer_id'] = (string) $order['customer_id'];
        if (!empty($order['purpose'])) $notes['purpose'] = (string) $order['purpose'];

        $payload = [
            'amount' => $amountInPaise,
            'currency' => $order['currency'] ?? 'INR',
            'receipt' => (string) ($order['receipt'] ?? ('rec_' . Str::random(12))),
            'notes' => array_filter($notes),
        ];

        try {
            $response = Http::withoutVerifying()
                ->timeout(15)
                ->withBasicAuth($keyId, $keySecret)
                ->post(self::BASE_URL . '/orders', $payload);

            if (! $response->successful()) {
                $errorDescription = $response->json('error.description')
                    ?? $response->json('error.code')
                    ?? 'Order creation failed.';
                throw new DomainException("Razorpay order creation failed ({$response->status()}): {$errorDescription}");
            }

            $data = $response->json();

            return [
                'order_id' => $data['id'] ?? null,
                'amount' => $data['amount'] ?? $amountInPaise,
                'currency' => $data['currency'] ?? 'INR',
                'receipt' => $data['receipt'] ?? $payload['receipt'],
                'status' => $data['status'] ?? 'created',
                'key_id' => $keyId,
                'raw' => $data,
            ];
        } catch (DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DomainException('Razorpay API request error: ' . $e->getMessage(), 0, $e);
        }
    }

    public function verifyPayment(PaymentGateway $gateway, array $payload): bool
    {
        $orderId = trim((string) ($payload['razorpay_order_id'] ?? ''));
        $paymentId = trim((string) ($payload['razorpay_payment_id'] ?? ''));
        $signature = trim((string) ($payload['razorpay_signature'] ?? ''));
        $secret = trim((string) $gateway->encrypted_secret);

        if (blank($orderId) || blank($paymentId) || blank($signature) || blank($secret)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    public function verifyWebhook(PaymentGateway $gateway, string $payload, ?string $signature): bool
    {
        $webhookSecret = trim((string) $gateway->encrypted_webhook_secret);

        if (blank($webhookSecret) || blank($signature)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        return hash_equals($expectedSignature, (string) $signature);
    }

    public function createSubscription(PaymentGateway $gateway, array $planData, array $subscriptionData): array
    {
        $keyId = trim((string) $gateway->public_key);
        $keySecret = trim((string) $gateway->encrypted_secret);

        if (blank($keyId) || blank($keySecret)) {
            throw new DomainException('Razorpay Key ID and Key Secret are not configured.');
        }

        // 1. Create a Plan in Razorpay (or retrieve if we were caching, but creating on the fly for simplicity as plans might be customized)
        $amountInPaise = (int) round(((float) ($planData['amount'] ?? 0)) * 100);
        $period = $planData['period'] ?? 'monthly'; // daily, weekly, monthly, yearly
        $interval = (int) ($planData['interval'] ?? 1);

        $planPayload = [
            'period' => $period,
            'interval' => $interval,
            'item' => [
                'name' => mb_substr($planData['name'] ?? 'Subscription Plan', 0, 40), // max 40 chars
                'amount' => $amountInPaise,
                'currency' => $planData['currency'] ?? 'INR',
                'description' => mb_substr($planData['description'] ?? 'Recurring Subscription', 0, 255),
            ],
        ];

        try {
            $planResponse = Http::withoutVerifying()
                ->timeout(15)
                ->withBasicAuth($keyId, $keySecret)
                ->post(self::BASE_URL . '/plans', $planPayload);

            if (! $planResponse->successful()) {
                $errorDescription = $planResponse->json('error.description') ?? $planResponse->json('error.code') ?? 'Plan creation failed.';
                throw new DomainException("Razorpay plan creation failed ({$planResponse->status()}): {$errorDescription}");
            }

            $planId = $planResponse->json('id');

            // 2. Create the Subscription
            $subPayload = [
                'plan_id' => $planId,
                'total_count' => (int) ($subscriptionData['total_count'] ?? 120), // 10 years by default for monthly
                'quantity' => 1,
                'customer_notify' => 1,
                'notes' => array_filter($subscriptionData['notes'] ?? []),
            ];

            if (!empty($subscriptionData['start_at'])) {
                $subPayload['start_at'] = (int) $subscriptionData['start_at'];
            }

            $subResponse = Http::withoutVerifying()
                ->timeout(15)
                ->withBasicAuth($keyId, $keySecret)
                ->post(self::BASE_URL . '/subscriptions', $subPayload);

            if (! $subResponse->successful()) {
                $errorDescription = $subResponse->json('error.description') ?? $subResponse->json('error.code') ?? 'Subscription creation failed.';
                throw new DomainException("Razorpay subscription creation failed ({$subResponse->status()}): {$errorDescription}");
            }

            $data = $subResponse->json();

            return [
                'subscription_id' => $data['id'] ?? null,
                'plan_id' => $planId,
                'status' => $data['status'] ?? 'created',
                'short_url' => $data['short_url'] ?? null,
                'key_id' => $keyId,
                'raw' => $data,
            ];
        } catch (DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DomainException('Razorpay API request error: ' . $e->getMessage(), 0, $e);
        }
    }

    public function verifySubscription(PaymentGateway $gateway, array $payload): bool
    {
        $paymentId = trim((string) ($payload['razorpay_payment_id'] ?? ''));
        $subscriptionId = trim((string) ($payload['razorpay_subscription_id'] ?? ''));
        $signature = trim((string) ($payload['razorpay_signature'] ?? ''));
        $secret = trim((string) $gateway->encrypted_secret);

        if (blank($paymentId) || blank($subscriptionId) || blank($signature) || blank($secret)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $paymentId . '|' . $subscriptionId, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    public function refundPayment(PaymentGateway $gateway, string $paymentId, ?float $amount = null, array $notes = []): array
    {
        $keyId = trim((string) $gateway->public_key);
        $keySecret = trim((string) $gateway->encrypted_secret);

        if (blank($keyId) || blank($keySecret)) {
            throw new DomainException('Razorpay Key ID and Key Secret are not configured.');
        }

        $payload = [];
        if ($amount !== null && $amount > 0) {
            $payload['amount'] = (int) round($amount * 100);
        }
        if (!empty($notes)) {
            $payload['notes'] = array_filter($notes);
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(15)
                ->withBasicAuth($keyId, $keySecret)
                ->post(self::BASE_URL . "/payments/{$paymentId}/refund", $payload);

            if (! $response->successful()) {
                $errorDescription = $response->json('error.description')
                    ?? $response->json('error.code')
                    ?? 'Razorpay refund request failed.';
                throw new DomainException("Razorpay refund failed ({$response->status()}): {$errorDescription}");
            }

            $data = $response->json();

            return [
                'success' => true,
                'refund_id' => $data['id'] ?? null,
                'payment_id' => $data['payment_id'] ?? $paymentId,
                'amount' => isset($data['amount']) ? ((float) $data['amount']) / 100 : $amount,
                'currency' => $data['currency'] ?? 'INR',
                'status' => $data['status'] ?? 'processed',
                'raw' => $data,
            ];
        } catch (DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DomainException('Razorpay API request error: ' . $e->getMessage(), 0, $e);
        }
    }
}
