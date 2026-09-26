<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayProviderInterface;
use App\Models\PaymentGateway;
use InvalidArgumentException;

class PaymentGatewayManager
{
    public function provider(string $provider): PaymentGatewayProviderInterface
    {
        if (! in_array($provider, PaymentGateway::PROVIDERS, true)) {
            throw new InvalidArgumentException('Unsupported payment gateway provider.');
        }

        return match ($provider) {
            'razorpay' => new RazorpayGatewayProvider(),
            default => new ConfiguredPaymentGatewayProvider($provider),
        };
    }

    public function schema(string $provider): array
    {
        return match ($provider) {
            'razorpay' => ['public_key' => 'Key ID', 'secret' => 'Key Secret', 'webhook_secret' => 'Webhook Secret'],
            'cashfree' => ['public_key' => 'Client ID', 'secret' => 'Client Secret', 'webhook_secret' => 'Webhook Secret'],
            'stripe' => ['public_key' => 'Publishable Key', 'secret' => 'Secret Key', 'webhook_secret' => 'Webhook Signing Secret'],
            'payu' => ['public_key' => 'Merchant Key', 'secret' => 'Merchant Salt', 'webhook_secret' => 'Webhook Secret'],
            'phonepe' => ['public_key' => 'Merchant ID', 'secret' => 'Salt Key', 'webhook_secret' => 'Webhook Secret'],
            'custom' => ['public_key' => 'Public Identifier', 'secret' => 'API Secret', 'webhook_secret' => 'Webhook Secret', 'base_url' => 'HTTPS Base URL'],
            default => [],
        };
    }
}
