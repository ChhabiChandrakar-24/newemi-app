<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\Payments\RazorpayGatewayProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RazorpayIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private RazorpayGatewayProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id]);
        $this->admin->assignRole('admin');
        $this->provider = new RazorpayGatewayProvider();
    }

    public function test_test_connection_fails_when_unconfigured(): void
    {
        $gateway = PaymentGateway::factory()->create([
            'company_id' => $this->company->id,
            'provider' => 'razorpay',
            'public_key' => null,
            'encrypted_secret' => null,
        ]);

        $result = $this->provider->testConnection($gateway);

        $this->assertFalse($result['success']);
        $this->assertSame('not_configured', $result['status']);
    }

    public function test_test_connection_live_verified_when_credentials_valid(): void
    {
        Http::fake([
            'https://api.razorpay.com/v1/orders*' => Http::response(['entity' => 'collection', 'count' => 0, 'items' => []], 200),
        ]);

        $gateway = PaymentGateway::factory()->create([
            'company_id' => $this->company->id,
            'provider' => 'razorpay',
            'public_key' => 'rzp_test_valid_key',
            'encrypted_secret' => 'valid_secret',
        ]);

        $result = $this->provider->testConnection($gateway);

        $this->assertTrue($result['success']);
        $this->assertSame('live_verified', $result['status']);
    }

    public function test_test_connection_reports_authentication_failure(): void
    {
        Http::fake([
            'https://api.razorpay.com/v1/orders*' => Http::response([
                'error' => [
                    'code' => 'BAD_REQUEST_ERROR',
                    'description' => 'Authentication failed',
                ],
            ], 401),
        ]);

        $gateway = PaymentGateway::factory()->create([
            'company_id' => $this->company->id,
            'provider' => 'razorpay',
            'public_key' => 'rzp_test_invalid_key',
            'encrypted_secret' => 'invalid_secret',
        ]);

        $result = $this->provider->testConnection($gateway);

        $this->assertFalse($result['success']);
        $this->assertSame('authentication_failed', $result['status']);
        $this->assertStringContainsString('Authentication failed', $result['message']);
    }

    public function test_order_creation_formats_payload_and_calls_api(): void
    {
        Http::fake([
            'https://api.razorpay.com/v1/orders' => Http::response([
                'id' => 'order_123456789',
                'amount' => 50000,
                'currency' => 'INR',
                'receipt' => 'rec_test_001',
                'status' => 'created',
            ], 200),
        ]);

        $gateway = PaymentGateway::factory()->create([
            'company_id' => $this->company->id,
            'provider' => 'razorpay',
            'public_key' => 'rzp_test_key',
            'encrypted_secret' => 'test_secret',
        ]);

        $order = $this->provider->createPaymentOrder($gateway, [
            'amount' => 500.00,
            'currency' => 'INR',
            'receipt' => 'rec_test_001',
            'emi_account_id' => 1,
        ]);

        $this->assertSame('order_123456789', $order['order_id']);
        $this->assertSame(50000, $order['amount']);
        $this->assertSame('created', $order['status']);
    }

    public function test_verify_payment_signature(): void
    {
        $gateway = PaymentGateway::factory()->create([
            'company_id' => $this->company->id,
            'provider' => 'razorpay',
            'encrypted_secret' => 'secret_123',
        ]);

        $orderId = 'order_abc';
        $paymentId = 'pay_xyz';
        $validSignature = hash_hmac('sha256', "{$orderId}|{$paymentId}", 'secret_123');

        $this->assertTrue($this->provider->verifyPayment($gateway, [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $validSignature,
        ]));

        $this->assertFalse($this->provider->verifyPayment($gateway, [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => 'invalid_signature',
        ]));
    }
}
