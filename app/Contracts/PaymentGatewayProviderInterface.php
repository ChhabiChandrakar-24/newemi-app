<?php

namespace App\Contracts;

use App\Models\PaymentGateway;

interface PaymentGatewayProviderInterface
{
    public function getProviderName(): string;

    public function testConnection(PaymentGateway $gateway): array;

    public function createPaymentOrder(PaymentGateway $gateway, array $order): array;

    public function verifyPayment(PaymentGateway $gateway, array $payload): bool;

    public function verifyWebhook(PaymentGateway $gateway, string $payload, ?string $signature): bool;

    public function createSubscription(PaymentGateway $gateway, array $planData, array $subscriptionData): array;

    public function verifySubscription(PaymentGateway $gateway, array $payload): bool;

    public function refundPayment(PaymentGateway $gateway, string $paymentId, ?float $amount = null, array $notes = []): array;
}

