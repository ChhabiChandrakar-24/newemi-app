<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Device;
use App\Models\EmiAccount;
use App\Models\Payment;
use App\Models\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactoryTenantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_scoped_factory_states_keep_relationships_in_the_same_tenant(): void
    {
        $alpha = Company::factory()->create();
        $beta = Company::factory()->create();

        $account = EmiAccount::factory()->forCompany($beta)->create();
        $payment = Payment::factory()->forCompany($beta)->create();
        $device = Device::factory()->forCompany($beta)->create();
        $gateway = PaymentGateway::factory()->forCompany($beta)->create();

        $this->assertNotSame($alpha->id, $beta->id);
        $this->assertSame($beta->id, $account->company_id);
        $this->assertSame($beta->id, $account->customer()->withoutGlobalScopes()->firstOrFail()->company_id);
        $this->assertSame($beta->id, $payment->company_id);
        $this->assertSame($beta->id, $payment->emiAccount()->withoutGlobalScopes()->firstOrFail()->company_id);
        $this->assertSame($beta->id, $payment->customer()->withoutGlobalScopes()->firstOrFail()->company_id);
        $this->assertSame($beta->id, $device->company_id);
        $this->assertSame($beta->id, $device->customer()->withoutGlobalScopes()->firstOrFail()->company_id);
        $this->assertSame($beta->id, $gateway->company_id);
    }
}
