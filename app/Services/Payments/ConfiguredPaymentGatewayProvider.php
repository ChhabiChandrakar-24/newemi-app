<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayProviderInterface;
use App\Models\PaymentGateway;
use DomainException;

class ConfiguredPaymentGatewayProvider implements PaymentGatewayProviderInterface
{
    public function __construct(private readonly string $provider) {}

    public function getProviderName(): string
    {
        return $this->provider;
    }

    public function testConnection(PaymentGateway $gateway): array
    {
        $configured = filled($gateway->public_key) && filled($gateway->encrypted_secret);

        return [
            'success' => false,
            'status' => $configured ? 'configuration_present_not_live_tested' : 'not_configured',
            'message' => $configured
                ? 'Credentials are configured. Live provider calls are disabled until the provider-specific SDK and merchant contract are enabled.'
                : 'Required credentials are not configured.',
        ];
    }

    public function createPaymentOrder(PaymentGateway $gateway, array $order): array
    {
        throw new DomainException("{$this->provider} online order creation is not enabled. No payment was created.");
    }

    public function verifyPayment(PaymentGateway $gateway, array $payload): bool
    {
        return false;
    }

    public function verifyWebhook(PaymentGateway $gateway, string $payload, ?string $signature): bool
    {
        if (! filled($gateway->encrypted_webhook_secret) || ! filled($signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $gateway->encrypted_webhook_secret), $signature);
    }

    public function createSubscription(PaymentGateway $gateway, array $planData, array $subscriptionData): array
    {
        throw new DomainException("{$this->provider} subscription creation is not enabled.");
    }

    public function verifySubscription(PaymentGateway $gateway, array $payload): bool
    {
        return false;
    }

    public function refundPayment(PaymentGateway $gateway, string $paymentId, ?float $amount = null, array $notes = []): array
    {
        throw new DomainException("{$this->provider} refunds are not enabled.");
    }
}
