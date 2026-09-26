<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PaymentGateway;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentGatewayManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;
    private Company $beta;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->alpha = Company::factory()->create();
        $this->beta = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->alpha->id]);
        $this->admin->assignRole('admin');
        Sanctum::actingAs($this->admin);
    }

    public function test_company_can_manage_multiple_gateways_and_switch_default(): void
    {
        $ids = [];
        foreach (['razorpay', 'cashfree', 'stripe'] as $provider) {
            $response = $this->postJson('/api/v1/payment-gateways', $this->payload($provider))
                ->assertCreated()->assertJsonPath('data.secret_configured', true)->assertJsonMissing(['secret' => 'DemoSecret@123']);
            $ids[] = $response->json('data.id');
        }
        $this->getJson('/api/v1/payment-gateways')->assertOk()->assertJsonCount(3, 'data');
        $this->patchJson("/api/v1/payment-gateways/{$ids[0]}/status", ['is_enabled' => true])->assertOk();
        $this->patchJson("/api/v1/payment-gateways/{$ids[1]}/status", ['is_enabled' => true])->assertOk();
        $this->postJson("/api/v1/payment-gateways/{$ids[0]}/set-default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->postJson("/api/v1/payment-gateways/{$ids[1]}/set-default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertDatabaseHas('payment_gateways', ['id' => $ids[0], 'is_default' => false]);
        $this->deleteJson("/api/v1/payment-gateways/{$ids[2]}")->assertOk();
        $this->getJson('/api/v1/payment-gateways?archived=1')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/payment-gateways/{$ids[2]}/restore")->assertOk()->assertJsonPath('data.is_enabled', false);
    }

    public function test_secrets_are_encrypted_replaceable_masked_and_absent_from_audit(): void
    {
        $created = $this->postJson('/api/v1/payment-gateways', $this->payload('razorpay'))->assertCreated();
        $id = $created->json('data.id');
        $stored = PaymentGateway::withoutGlobalScopes()->findOrFail($id);
        $this->assertStringNotContainsString('DemoSecret@123', (string) $stored->getRawOriginal('encrypted_secret'));
        $this->putJson("/api/v1/payment-gateways/{$id}", $this->payload('razorpay', 'ReplacementSecret@456'))
            ->assertOk()->assertJsonPath('data.secret_configured', true)->assertJsonMissing(['secret' => 'ReplacementSecret@456']);
        $this->assertSame('ReplacementSecret@456', $stored->fresh()->encrypted_secret);
        $audit = AuditLog::where('entity_id', (string) $id)->get()->toJson();
        $this->assertStringNotContainsString('DemoSecret@123', $audit);
        $this->assertStringNotContainsString('ReplacementSecret@456', $audit);
    }

    public function test_company_a_cannot_access_or_mutate_company_b_gateway(): void
    {
        $gateway = PaymentGateway::factory()->create(['company_id' => $this->beta->id]);
        $this->getJson("/api/v1/payment-gateways/{$gateway->id}")->assertNotFound();
        $this->putJson("/api/v1/payment-gateways/{$gateway->id}", $this->payload('stripe'))->assertNotFound();
        $this->postJson("/api/v1/payment-gateways/{$gateway->id}/test")->assertNotFound();
        $this->postJson("/api/v1/payment-gateways/{$gateway->id}/set-default")->assertNotFound();
    }

    public function test_webhook_resolves_gateway_without_trusting_company_body_and_verifies_signature(): void
    {
        $gateway = PaymentGateway::factory()->create(['company_id' => $this->alpha->id, 'provider' => 'razorpay', 'is_enabled' => true, 'encrypted_webhook_secret' => 'webhook-demo-secret']);
        $payload = json_encode(['company_id' => $this->beta->id, 'event' => 'payment.demo']);
        $signature = hash_hmac('sha256', $payload, 'webhook-demo-secret');
        $this->call('POST', '/api/webhooks/payments/razorpay', [], [], [], [
            'HTTP_X_GATEWAY_KEY' => $gateway->webhook_key, 'HTTP_X_PAYMENT_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(202)->assertJsonPath('accepted', true);
    }

    private function payload(string $provider, string $secret = 'DemoSecret@123'): array
    {
        return ['provider' => $provider, 'display_name' => 'Demo '.ucfirst($provider), 'environment' => 'test',
            'is_enabled' => false, 'is_default' => false, 'public_key' => 'demo_public_key', 'secret' => $secret,
            'webhook_secret' => 'DemoWebhook@123'];
    }
}
